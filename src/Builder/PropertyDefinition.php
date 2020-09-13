<?php

declare(strict_types=1);

namespace Orm\Builder;

use Orm\Exception\ArrayPropertyMustHaveAnArrayAnnotation;
use Orm\Exception\ArrayPropertyMustHaveATypeAnnotation;
use Orm\Exception\PropertyHasNoGetter;
use Orm\Exception\PropertyMustHaveAType;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflection\ReflectionClass;
use Roave\BetterReflection\Reflection\ReflectionParameter;
use Throwable;

class PropertyDefinition
{
    private string $name;
    private bool $variadic;
    private string $type;
    private string $getter;
    private ?ClassDefinition $class;

    /**
     * @throws Throwable
     */
    public function __construct(ReflectionClass $class, ReflectionParameter $param)
    {
        $this->name = $param->getName();
        $this->variadic = $param->isVariadic();
        $this->type = $this->searchParamType($class, $param);
        $this->class = $this->getClassDefinitionByType(str_replace('[]', '', $this->type));
        $this->getter = $this->searchParamGetter($class, $param, $this->type);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getIdType(): string
    {
        $class = $this->class
            ? $this->class->getIdType()
            : 'string';

        return $class ?: 'string';
    }

    public function getClassDefinition(): ?ClassDefinition
    {
        return $this->class;
    }

    public function getGetter(): string
    {
        return $this->getter;
    }

    public function isArray(): bool
    {
        return $this->checkArrayType($this->type) || $this->variadic;
    }

    public function isVariadic(): bool
    {
        return $this->variadic;
    }

    public function isEntity(): bool
    {
        return $this->class
            ? $this->class->isEntity()
            : false;
    }

    public function isValueObject(): bool
    {
        return $this->class
            ? $this->class->isValueObject()
            : false;
    }

    public function withGetter(string $getter): self
    {
        $new = clone $this;
        $new->getter = $getter;

        return $new;
    }

    private function checkScalarType(string $type): bool
    {
        return in_array($type, ['int', 'float', 'string', 'bool']);
    }

    private function checkArrayType(string $type): bool
    {
        return $type === 'array' || false !== strpos($type, '[]');
    }

    /**
     * @throws ArrayPropertyMustHaveATypeAnnotation
     * @throws ArrayPropertyMustHaveAnArrayAnnotation
     * @throws PropertyMustHaveAType
     */
    private function searchParamType(ReflectionClass $class, ReflectionParameter $param): string
    {
        $type = (string) $param->getType();

        if ('' === $type) {
            throw new PropertyMustHaveAType($param, $class);
        }

        if (true === $this->checkScalarType($type)) {
            return $type;
        }

        if (true === $this->checkArrayType($type)) {
            return $this->searchArrayType($param, $class);
        }

        return $type;
    }

    /**
     * @throws ArrayPropertyMustHaveATypeAnnotation
     * @throws ArrayPropertyMustHaveAnArrayAnnotation
     */
    private function searchArrayType(ReflectionParameter $param, ReflectionClass $class): string
    {
        $type = $this->searchTypeOnDocComment($param, $class);
        $namespace = $this->searchNamespace($class, $type);

        return sprintf('%s%s[]', $namespace, $type);
    }

    /**
     * @throws ArrayPropertyMustHaveATypeAnnotation
     * @throws ArrayPropertyMustHaveAnArrayAnnotation
     */
    private function searchTypeOnDocComment(ReflectionParameter $param, ReflectionClass $class): string
    {
        $docComment = $class->getConstructor()->getDocComment() ?: '';
        $pattern = sprintf('/@param(.*)\$%s/', $param->getName());

        preg_match($pattern, $docComment, $matches);
        $type = trim($matches[1] ?? '');

        if ('' === $type) {
            throw new ArrayPropertyMustHaveATypeAnnotation($param, $class);
        }

        if (strpos($type, '[]')) {
            return str_replace('[]', '', $type);
        }

        preg_match('/<(.*)>$/', $type, $matches);
        $type = trim($matches[1] ?? '');

        if ($type) {
            return $type;
        }

        throw new ArrayPropertyMustHaveAnArrayAnnotation($param, $class, $type);
    }

    private function searchNamespace(ReflectionClass $class, string $type): string
    {
        $parts = explode('\\', $type);
        $subNs = reset($parts);

        if ('' === $subNs) {
            return trim($type, '\\');
        }

        $file = file($class->getFileName() ?: '') ?: [];
        $lines = array_slice($file, 0, (int) $class->getStartLine());

        $pattern = sprintf('/use(.*)%s;/', $subNs);
        preg_match($pattern, implode(PHP_EOL, $lines), $matches);

        $match = trim($matches[0] ?? '');
        $namespace = trim($matches[1] ?? '');

        if ('' === $namespace && '' === $match && '' !== $subNs) {
            $namespace = sprintf('%s\\', $class->getNamespaceName());
        }

        return $namespace;
    }

    /**
     * @throws PropertyHasNoGetter
     */
    private function searchParamGetter(ReflectionClass $class, ReflectionParameter $param, string $type): string
    {
        if ($type === 'bool') {
            return $this->searchGetterForBoolean($class, $param);
        }

        $getter = sprintf('get%s', ucfirst($param->getName()));

        if ($class->hasMethod($getter)) {
            return $getter;
        }

        $numParams = count($class->getConstructor()->getParameters());

        if ($numParams === 1 && $class->hasMethod('__toString')) {
            return '__toString';
        }

        throw new PropertyHasNoGetter($class, $getter);
    }

    /**
     * @throws PropertyHasNoGetter
     */
    private function searchGetterForBoolean(ReflectionClass $class, ReflectionParameter $param): string
    {
        $isPrefix = sprintf('is%s', ucfirst($param->getName()));

        if (true === $class->hasMethod($isPrefix)) {
            return $isPrefix;
        }

        $hasPrefix = sprintf('has%s', ucfirst($param->getName()));

        if (true === $class->hasMethod($hasPrefix)) {
            return $hasPrefix;
        }

        throw new PropertyHasNoGetter($class, "{$isPrefix} or {$hasPrefix}");
    }

    private function getClassDefinitionByType(string $type): ?ClassDefinition
    {
        if ($this->checkScalarType($type) || $this->checkArrayType($type)) {
            return null;
        }

        return new ClassDefinition(
            (new BetterReflection())->classReflector()->reflect($type)
        );
    }
}
