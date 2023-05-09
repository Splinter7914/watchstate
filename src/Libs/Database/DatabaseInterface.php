<?php

declare(strict_types=1);

namespace App\Libs\Database;

use App\Libs\Entity\StateInterface;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

interface DatabaseInterface
{
    public const MIGRATE_UP = 'up';

    public const MIGRATE_DOWN = 'down';

    /**
     * Set db driver options.
     *
     * @param array $options
     * @return self
     */
    public function setOptions(array $options): self;

    /**
     * Insert Entity immediately.
     *
     * @param StateInterface $entity
     *
     * @return StateInterface Return given entity with valid $id
     */
    public function insert(StateInterface $entity): StateInterface;

    /**
     * Get Entity.
     *
     * @param StateInterface $entity
     *
     * @return StateInterface|null
     */
    public function get(StateInterface $entity): StateInterface|null;

    /**
     * Load entities from backend.
     *
     * @param DateTimeInterface|null $date Get Entities That has changed since given time, if null get all.
     * @param array $opts
     *
     * @return array<StateInterface>
     */
    public function getAll(DateTimeInterface|null $date = null, array $opts = []): array;

    /**
     * Return Number of Items.
     *
     * @param DateTimeInterface|null $date if provided, it will return items changes since this date.
     *
     * @return int
     */
    public function getCount(DateTimeInterface|null $date = null): int;

    /**
     * Return database records for given items.
     *
     * @param array<StateInterface> $items
     *
     * @return array<StateInterface>
     */
    public function find(StateInterface ...$items): array;

    /**
     * Update Entity immediately.
     *
     * @param StateInterface $entity
     *
     * @return StateInterface Return the given entity.
     */
    public function update(StateInterface $entity): StateInterface;

    /**
     * Remove Entity.
     *
     * @param StateInterface $entity
     *
     * @return bool
     */
    public function remove(StateInterface $entity): bool;

    /**
     * Insert/Update Entities.
     *
     * @param array<StateInterface> $entities
     * @param array $opts
     *
     * @return array
     */
    public function commit(array $entities, array $opts = []): array;

    /**
     * Run database migrations.
     *
     * @param string $dir direction {@see MIGRATE_UP}, {@see MIGRATE_DOWN}
     * @param array $opts
     *
     * @return mixed
     */
    public function migrations(string $dir, array $opts = []): mixed;

    /**
     * Check Indexes.
     *
     * @param array $opts
     *
     * @return mixed
     */
    public function ensureIndex(array $opts = []): mixed;

    /**
     * Migrate data from old database version.
     *
     * @param string $version represent the new version.
     * @param LoggerInterface|null $logger
     *
     * @return mixed
     */
    public function migrateData(string $version, LoggerInterface|null $logger = null): mixed;

    /**
     * Is the database up to date with migrations?
     *
     * @return bool
     */
    public function isMigrated(): bool;

    /**
     * Run Maintenance tasks on database.
     *
     * @param array $opts
     * @return mixed
     */
    public function maintenance(array $opts = []): mixed;

    /**
     * Make Migration.
     *
     * @param string $name
     * @param array $opts
     *
     * @return mixed can return migration file name in supported cases.
     * @throws
     */
    public function makeMigration(string $name, array $opts = []): mixed;

    /**
     * Inject Logger.
     *
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self;

    /**
     * Get Underlying PDO Instance.
     *
     * @return PDO
     * @throws RuntimeException if PDO is not initialized yet.
     */
    public function getPDO(): PDO;

    /**
     * Enable Single Transaction mode.
     *
     * @return bool
     */
    public function singleTransaction(): bool;

    /**
     * Wrap Queries into single transaction.
     *
     * @param Closure(DatabaseInterface): mixed $callback
     *
     * @return mixed
     * @throws PDOException
     */
    public function transactional(Closure $callback): mixed;
}
