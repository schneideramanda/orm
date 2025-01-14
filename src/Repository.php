<?php

declare(strict_types=1);

namespace Orm;

use Traversable;

/**
 * @template T of object
 */
abstract class Repository
{
    protected Connection $connection;
    protected EntityManager $em;

    abstract public function getTable(): string;

    /**
     * @param array<string, string> $order
     * @return array<string, string>
     */
    abstract public function getOrder(array $order): array;

    abstract public function getColumns(): string;

    abstract public function getBindings(): string;

    abstract public function getColumnsEqualBindings(): string;

    /**
     * @param T $entity
     * @return array<string, bool|float|int|string|null>
     */
    abstract public function getDeleteCriteria(object $entity): array;

    /**
     * @param T $entity
     * @return array<string, bool|float|int|string|null>
     */
    abstract public function entityToDatabaseRow(object $entity): array;

    /**
     * @param mixed[] $item
     * @return T
     */
    abstract public function databaseRowToEntity(array $item): object;

    public function __construct(Connection $connection, EntityManager $factory)
    {
        $this->connection = $connection;
        $this->em = $factory;
    }

    /**
     * @return T|null
     */
    public function loadById(string|int $id): ?object
    {
        return $this->selectOne(['id' => $id]);
    }

    /**
     * @param array<string, string|int|float|bool|null> $where
     * @param array<string, string> $order
     * @return T|null
     */
    public function loadBy(array $where, array $order = []): ?object
    {
        return $this->selectOne($where, $this->getOrder($order));
    }

    /**
     * @param array<string, string|int|float|bool|null> $bindings
     * @return T|null
     */
    public function loadByQuery(string $query, array $bindings = []): ?object
    {
        $item = $this->connection->execute($query, $bindings)->fetch();

        if (false === is_array($item)) {
            return null;
        }

        return $this->databaseRowToEntity($item);
    }

    /**
     * @param array<string, string|int|float|bool|null> $where
     */
    public function exists(array $where = []): bool
    {
        $result = $this->connection->select($this->getTable(), $where, [], 1);
        $items = iterator_to_array($result);
        $item = current($items);

        return $item !== false;
    }

    /**
     * @param array<string, string|int|float|bool|null> $where
     * @param array<string, string> $order
     * @return Traversable<T>
     */
    public function select(array $where = [], array $order = [], ?int $limit = null, ?int $offset = null): Traversable
    {
        $items = $this->connection->select($this->getTable(), $where, $this->getOrder($order), $limit, $offset);

        foreach ($items as $item) {
            yield $this->databaseRowToEntity($item);
        }
    }

    /**
     * @param array<string, string|int|float|bool|null> $where
     * @param array<string, string> $order
     * @return T|null
     */
    public function selectOne(array $where, array $order = []): ?object
    {
        $result = $this->connection->select($this->getTable(), $where, $this->getOrder($order), 1);
        $items = iterator_to_array($result);
        $item = current($items);

        if (!$item) {
            return null;
        }

        return $this->databaseRowToEntity($item);
    }

    /**
     * @param array<string, string|int|float|bool|null> $bindings
     * @return Traversable<T>
     */
    public function selectByQuery(string $query, array $bindings = []): Traversable
    {
        $items = $this->connection->execute($query, $bindings);

        foreach ($items as $item) {
            yield $this->databaseRowToEntity($item);
        }
    }

    /**
     * @param T ...$entities
     */
    public function insert(object ...$entities): void
    {
        $statement = "
            INSERT INTO {$this->getTable()} (
                {$this->getColumns()}
            ) values (
                {$this->getBindings()}
            );
        ";

        foreach ($entities as $entity) {
            $this->connection->execute($statement, $this->entityToDatabaseRow($entity));
        }
    }

    /**
     * @param T ...$entities
     */
    public function update(object ...$entities): void
    {
        $statement = "
            UPDATE {$this->getTable()} SET
                {$this->getColumnsEqualBindings()}
            WHERE id = :id
        ";

        foreach ($entities as $entity) {
            $this->connection->execute($statement, $this->entityToDatabaseRow($entity));
        }
    }

    /**
     * @param T ...$entities
     */
    public function delete(object ...$entities): void
    {
        $statement = "DELETE FROM {$this->getTable()} WHERE id = :id";

        foreach ($entities as $entity) {
            $this->connection->execute($statement, $this->getDeleteCriteria($entity));
        }
    }

    protected function floatToDbString(?float $value): ?string
    {
        return $value !== null ? sprintf('%.10f', $value) : null;
    }
}
