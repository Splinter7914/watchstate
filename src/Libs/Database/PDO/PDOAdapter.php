<?php

declare(strict_types=1);

namespace App\Libs\Database\PDO;

use App\Libs\Container;
use App\Libs\Database\DatabaseException as DBException;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Options;
use Closure;
use DateTimeInterface;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

final class PDOAdapter implements iDB
{
    private const LOCK_RETRY = 4;

    private bool $viaTransaction = false;
    private bool $singleTransaction = false;

    private array $options = [];

    /**
     * Cache Prepared Statements.
     *
     * @var array<array-key, PDOStatement>
     */
    private array $stmt = [
        'insert' => null,
        'update' => null,
    ];

    private string $driver = 'sqlite';

    public function __construct(private LoggerInterface $logger, private PDO $pdo)
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (is_string($driver)) {
            $this->driver = $driver;
        }
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function insert(iState $entity): iState
    {
        try {
            if (null !== ($entity->id ?? null)) {
                throw new DBException(
                    r('Unable to insert item that has primary key. [#{id}].', ['id' => $entity->id]), 21
                );
            }

            $data = $entity->getAll();
            unset($data[iState::COLUMN_ID]);

            // -- @TODO i dont like this section, And this should not happen here.
            if (false === $entity->isWatched()) {
                foreach ($data[iState::COLUMN_META_DATA] ?? [] as $via => $metadata) {
                    $data[iState::COLUMN_META_DATA][$via][iState::COLUMN_WATCHED] = '0';
                    if (null === ($metadata[iState::COLUMN_META_DATA_PLAYED_AT] ?? null)) {
                        continue;
                    }
                    unset($data[iState::COLUMN_META_DATA][$via][iState::COLUMN_META_DATA_PLAYED_AT]);
                }
            }

            foreach (iState::ENTITY_ARRAY_KEYS as $key) {
                if (null !== ($data[$key] ?? null) && true === is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === ($this->stmt['insert'] ?? null)) {
                $this->stmt['insert'] = $this->pdo->prepare(
                    $this->pdoInsert('state', iState::ENTITY_KEYS)
                );
            }

            $this->execute($this->stmt['insert'], $data);

            $entity->id = (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->stmt['insert'] = null;
            if (false === $this->viaTransaction && false === $this->singleTransaction) {
                $this->logger->error($e->getMessage(), [
                    'entity' => $entity->getAll(),
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTrace(),
                    ],
                ]);
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function get(iState $entity): iState|null
    {
        $inTraceMode = true === (bool)($this->options[Options::DEBUG_TRACE] ?? false);

        if ($inTraceMode) {
            $this->logger->debug(r('DATABASE: Looking for [{name}].', ['name' => $entity->getName()]));
        }

        if (null !== $entity->id) {
            $stmt = $this->query(
                r(
                    'SELECT * FROM state WHERE $[column] = $[id]',
                    [
                        'column' => iState::COLUMN_ID,
                        'id' => (int)$entity->id
                    ],
                    '$[',
                    ']'
                )
            );

            if (false !== ($item = $stmt->fetch(PDO::FETCH_ASSOC))) {
                $item = $entity::fromArray($item);

                if ($inTraceMode) {
                    $this->logger->debug(
                        r('DATABASE: Found [{name}] using direct id match.', [
                            'name' => $item->getName()
                        ]),
                        [
                            iState::COLUMN_ID => $entity->id
                        ]
                    );
                }
                return $item;
            }
        }

        if (null !== ($item = $this->findByExternalId($entity))) {
            if ($inTraceMode) {
                $this->logger->debug(
                    r('DATABASE: Found [{name}] using external id match.', [
                        'name' => $item->getName()
                    ]),
                    [
                        iState::COLUMN_GUIDS => $entity->getGuids(),
                    ]
                );
            }
            return $item;
        }

        return null;
    }

    public function getAll(DateTimeInterface|null $date = null, array $opts = []): array
    {
        $arr = [];

        if (true === array_key_exists('fields', $opts)) {
            $fields = implode(', ', $opts['fields']);
        } else {
            $fields = '*';
        }

        if (true === (bool)($this->options[Options::DEBUG_TRACE] ?? false)) {
            $this->logger->info('DATABASE: Selecting fields', $opts['fields'] ?? ['all']);
        }

        $sql = "SELECT {$fields} FROM state";

        if (null !== $date) {
            $sql .= ' WHERE ' . iState::COLUMN_UPDATED . ' > ' . $date->getTimestamp();
        }

        if (null === ($opts['class'] ?? null) || false === ($opts['class'] instanceof iState)) {
            $class = Container::get(iState::class);
        } else {
            $class = $opts['class'];
        }

        foreach ($this->query($sql) as $row) {
            $arr[] = $class::fromArray($row);
        }

        return $arr;
    }

    public function getCount(DateTimeInterface|null $date = null): int
    {
        $sql = 'SELECT COUNT(id) AS total FROM state';

        if (null !== $date) {
            $sql .= ' WHERE ' . iState::COLUMN_UPDATED . ' > ' . $date->getTimestamp();
        }

        return (int)$this->query($sql)->fetchColumn();
    }

    public function find(iState ...$items): array
    {
        $list = [];

        foreach ($items as $item) {
            if (null === ($entity = $this->get($item))) {
                continue;
            }

            $list[$entity->id] = $entity;
        }

        return $list;
    }

    public function update(iState $entity): iState
    {
        try {
            if (null === ($entity->id ?? null)) {
                throw new DBException('Unable to update item with out primary key.', 51);
            }

            $data = $entity->getAll();

            // -- @TODO i dont like this section, And this should not happen here.
            if (false === $entity->isWatched()) {
                foreach ($data[iState::COLUMN_META_DATA] ?? [] as $via => $metadata) {
                    $data[iState::COLUMN_META_DATA][$via][iState::COLUMN_WATCHED] = '0';
                    if (null === ($metadata[iState::COLUMN_META_DATA_PLAYED_AT] ?? null)) {
                        continue;
                    }
                    unset($data[iState::COLUMN_META_DATA][$via][iState::COLUMN_META_DATA_PLAYED_AT]);
                }
            }

            foreach (iState::ENTITY_ARRAY_KEYS as $key) {
                if (null !== ($data[$key] ?? null) && true === is_array($data[$key])) {
                    ksort($data[$key]);
                    $data[$key] = json_encode($data[$key], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            if (null === ($this->stmt['update'] ?? null)) {
                $this->stmt['update'] = $this->pdo->prepare(
                    $this->pdoUpdate('state', iState::ENTITY_KEYS)
                );
            }

            $this->execute($this->stmt['update'], $data);
        } catch (PDOException $e) {
            $this->stmt['update'] = null;
            if (false === $this->viaTransaction && false === $this->singleTransaction) {
                $this->logger->error($e->getMessage(), [
                    'entity' => $entity->getAll(),
                    'exception' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTrace(),
                    ]
                ]);
                return $entity;
            }
            throw $e;
        }

        return $entity->updateOriginal();
    }

    public function remove(iState $entity): bool
    {
        if (null === $entity->id && !$entity->hasGuids() && $entity->hasRelativeGuid()) {
            return false;
        }

        try {
            if (null === $entity->id) {
                if (null === ($dbEntity = $this->get($entity))) {
                    return false;
                }
                $id = $dbEntity->id;
            } else {
                $id = $entity->id;
            }

            $this->query(
                r('DELETE FROM state WHERE ${column} = ${id}', [
                    'column' => iState::COLUMN_ID,
                    'id' => (int)$id
                ])
            );
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), [
                'entity' => $entity->getAll(),
                'exception' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                ],
            ]);
            return false;
        }

        return true;
    }

    public function commit(array $entities, array $opts = []): array
    {
        $actions = [
            'added' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        return $this->transactional(function () use ($entities, $actions) {
            foreach ($entities as $entity) {
                try {
                    if (null === $entity->id) {
                        $this->insert($entity);
                        $actions['added']++;
                    } else {
                        $this->update($entity);
                        $actions['updated']++;
                    }
                } catch (PDOException $e) {
                    $actions['failed']++;
                    $this->logger->error($e->getMessage(), [
                        'entity' => $entity->getAll(),
                        'exception' => [
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                        ],
                    ]);
                }
            }

            return $actions;
        });
    }

    public function migrations(string $dir, array $opts = []): mixed
    {
        $class = new PDOMigrations($this->pdo, $this->logger);

        return match (strtolower($dir)) {
            iDB::MIGRATE_UP => $class->up(),
            iDB::MIGRATE_DOWN => $class->down(),
            default => throw new DBException(
                r('Unknown migration direction [{dir}] was given.', [
                    'name' => $dir
                ]), 91
            ),
        };
    }

    public function ensureIndex(array $opts = []): mixed
    {
        return (new PDOIndexer($this->pdo, $this->logger))->ensureIndex($opts);
    }

    public function migrateData(string $version, LoggerInterface|null $logger = null): mixed
    {
        return (new PDODataMigration($this->pdo, $logger ?? $this->logger))->automatic();
    }

    public function isMigrated(): bool
    {
        return (new PDOMigrations($this->pdo, $this->logger))->isMigrated();
    }

    public function makeMigration(string $name, array $opts = []): mixed
    {
        return (new PDOMigrations($this->pdo, $this->logger))->make($name);
    }

    public function maintenance(array $opts = []): mixed
    {
        return (new PDOMigrations($this->pdo, $this->logger))->runMaintenance();
    }

    public function setLogger(LoggerInterface $logger): iDB
    {
        $this->logger = $logger;

        return $this;
    }

    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    public function singleTransaction(): bool
    {
        $this->singleTransaction = true;

        if (false === $this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        return $this->pdo->inTransaction();
    }

    public function transactional(Closure $callback): mixed
    {
        if (true === $this->pdo->inTransaction()) {
            $this->viaTransaction = true;
            $result = $callback($this);
            $this->viaTransaction = false;
            return $result;
        }

        try {
            $this->pdo->beginTransaction();

            $this->viaTransaction = true;
            $result = $callback($this);
            $this->viaTransaction = false;

            $this->pdo->commit();

            return $result;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->viaTransaction = false;
            throw $e;
        }
    }

    /**
     * If we are using single transaction,
     * commit all changes on class destruction.
     */
    public function __destruct()
    {
        if (true === $this->singleTransaction && true === $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }

        $this->stmt = [];
    }

    /**
     * Generate SQL Insert Statement.
     *
     * @param string $table
     * @param array $columns
     * @return string
     */
    private function pdoInsert(string $table, array $columns): string
    {
        $queryString = "INSERT INTO {$table} (%(columns)) VALUES(%(values))";

        $sql_columns = $sql_placeholder = [];

        foreach ($columns as $column) {
            if (iState::COLUMN_ID === $column) {
                continue;
            }

            $sql_columns[] = $column;
            $sql_placeholder[] = ':' . $column;
        }

        $queryString = str_replace(
            ['%(columns)', '%(values)'],
            [implode(', ', $sql_columns), implode(', ', $sql_placeholder)],
            $queryString
        );

        return trim($queryString);
    }

    /**
     * Generate SQL Update Statement.
     *
     * @param string $table
     * @param array $columns
     * @return string
     */
    private function pdoUpdate(string $table, array $columns): string
    {
        /** @noinspection SqlWithoutWhere */
        $queryString = "UPDATE {$table} SET %(place) = %(holder) WHERE " . iState::COLUMN_ID . " = :id";

        $placeholders = [];

        foreach ($columns as $column) {
            if (iState::COLUMN_ID === $column) {
                continue;
            }
            $placeholders[] = r('{column} = :{column}', ['column' => $column]);
        }

        return trim(str_replace('%(place) = %(holder)', implode(', ', $placeholders), $queryString));
    }

    /**
     * Find db entity using External ID.
     * External ID format is: (db_name)://(id)
     *
     * @param iState $entity
     * @return iState|null
     */
    private function findByExternalId(iState $entity): iState|null
    {
        $guids = [];
        $cond = [
            'type' => $entity->type,
        ];

        $sqlEpisode = '';

        if (true === $entity->isEpisode()) {
            $sqlEpisode = ' AND ' . iState::COLUMN_SEASON . ' = :season AND ' . iState::COLUMN_EPISODE . ' = :episode ';

            $cond['season'] = $entity->season;
            $cond['episode'] = $entity->episode;

            foreach ($entity->getParentGuids() as $key => $val) {
                if (empty($val)) {
                    continue;
                }

                $guids[] = "JSON_EXTRACT(" . iState::COLUMN_PARENT . ",'$.{$key}') = :p_{$key}";
                $cond['p_' . $key] = $val;
            }
        }

        foreach ($entity->getGuids() as $key => $val) {
            if (empty($val)) {
                continue;
            }

            $guids[] = "JSON_EXTRACT(" . iState::COLUMN_GUIDS . ",'$.{$key}') = :g_{$key}";
            $cond['g_' . $key] = $val;
        }

        if (null !== ($backendId = $entity->getMetadata($entity->via)[iState::COLUMN_ID] ?? null)) {
            $key = $entity->via . '.' . iState::COLUMN_ID;
            $guids[] = "JSON_EXTRACT(" . iState::COLUMN_META_DATA . ",'$.{$key}') = :m_bid";
            $cond['m_bid'] = $backendId;
        }

        if (empty($guids)) {
            return null;
        }

        $sqlGuids = ' AND ( ' . implode(' OR ', $guids) . ' ) ';

        $sql = "SELECT * FROM state WHERE " . iState::COLUMN_TYPE . " = :type {$sqlEpisode} {$sqlGuids} LIMIT 1";

        $stmt = $this->pdo->prepare($sql);

        if (false === $this->execute($stmt, $cond)) {
            throw new DBException('Failed to execute sql query.', 61);
        }

        if (false === ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            return null;
        }

        return $entity::fromArray($row);
    }

    private function execute(PDOStatement $stmt, array $cond = []): bool
    {
        for ($i = 0; $i <= self::LOCK_RETRY; $i++) {
            try {
                return $stmt->execute($cond);
            } catch (PDOException $e) {
                if (true === str_contains(strtolower($e->getMessage()), 'database is locked')) {
                    if ($i >= self::LOCK_RETRY) {
                        throw $e;
                    }

                    /** @noinspection PhpUnhandledExceptionInspection */
                    $sleep = self::LOCK_RETRY + random_int(1, 3);

                    $this->logger->warning('Database is locked. sleeping for [%(sleep)].', ['sleep' => $sleep]);

                    sleep($sleep);
                } else {
                    throw $e;
                }
            }
        }

        return false;
    }

    private function query(string $sql): PDOStatement|false
    {
        for ($i = 0; $i <= self::LOCK_RETRY; $i++) {
            try {
                return $this->pdo->query($sql);
            } catch (PDOException $e) {
                if (true === str_contains(strtolower($e->getMessage()), 'database is locked')) {
                    if ($i >= self::LOCK_RETRY) {
                        throw $e;
                    }

                    /** @noinspection PhpUnhandledExceptionInspection */
                    $sleep = self::LOCK_RETRY + random_int(1, 3);

                    $this->logger?->warning('Database is locked. sleeping for [%(sleep)].', context: [
                        'sleep' => $sleep,
                    ]);

                    sleep($sleep);
                } else {
                    throw $e;
                }
            }
        }

        return false;
    }

    /**
     * FOR DEBUGGING AND DISPLAY PURPOSES ONLY.
     *
     * **DO NOT USE FOR ANYTHING ELSE.**
     *
     * @param string $sql
     * @param array $parameters
     * @return string
     *
     * @internal
     */
    public function getRawSQLString(string $sql, array $parameters): string
    {
        $replacer = [];

        foreach ($parameters as $key => $val) {
            $replacer['/(\:' . preg_quote($key, '/') . ')(?:\b|\,)/'] = ctype_digit(
                (string)$val
            ) ? (int)$val : '"' . $val . '"';
        }

        return preg_replace(array_keys($replacer), array_values($replacer), $sql);
    }

    public function identifier(string $text, bool $quote = true): string
    {
        // table or column has to be valid ASCII name.
        // this is opinionated, but we only allow [a-zA-Z0-9_] in column/table name.
        if (!\preg_match('#\w#', $text)) {
            throw new \RuntimeException(
                r('Invalid column/table [{ident}]: Column/table must be valid ASCII code.', [
                    'ident' => $text
                ])
            );
        }

        // The first character cannot be [0-9]:
        if (\preg_match('/^\d/', $text)) {
            throw new \RuntimeException(
                r('Invalid column/table [{ident}]: Must begin with a letter or underscore.', [
                        'ident' => $text
                    ]
                )
            );
        }

        return !$quote ? $text : match ($this->driver) {
            'mssql' => '[' . $text . ']',
            'mysql' => '`' . $text . '`',
            default => '"' . $text . '"',
        };
    }

}
