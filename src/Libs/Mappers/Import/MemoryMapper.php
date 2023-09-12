<?php

declare(strict_types=1);

namespace App\Libs\Mappers\Import;

use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Mappers\ImportInterface as iImport;
use App\Libs\Message;
use App\Libs\Options;
use DateTimeInterface as iDate;
use PDOException;
use Psr\Log\LoggerInterface as iLogger;

final class MemoryMapper implements iImport
{
    protected const GUID = 'local_db://';

    /**
     * @var array<int,iState> Entities table.
     */
    protected array $objects = [];

    /**
     * @var array<string,int> Map GUIDs to entities.
     */
    protected array $pointers = [];

    /**
     * @var array<int,int> List Changed Entities.
     */
    protected array $changed = [];

    protected array $options = [];

    protected bool $fullyLoaded = false;

    public function __construct(protected iLogger $logger, protected iDB $db)
    {
    }

    public function setOptions(array $options = []): iImport
    {
        $this->options = $options;

        return $this;
    }

    public function loadData(iDate|null $date = null): self
    {
        $this->fullyLoaded = null === $date;

        foreach ($this->db->getAll($date, opts: ['class' => $this->options['class'] ?? null]) as $entity) {
            $pointer = self::GUID . $entity->id;

            if (null !== ($this->objects[$pointer] ?? null)) {
                continue;
            }

            $this->objects[$pointer] = $entity;
            $this->addPointers($this->objects[$pointer], $pointer);
        }

        $this->logger->info('MAPPER: Preloaded [{pointers}] pointer and [{objects}] object into memory.', [
            'pointers' => number_format(count($this->pointers)),
            'objects' => number_format(count($this->objects)),
        ]);

        return $this;
    }

    public function add(iState $entity, array $opts = []): self
    {
        if (false === $entity->hasGuids() && false === $entity->hasRelativeGuid()) {
            $this->logger->warning('MAPPER: Ignoring [{backend}] [{title}] no valid/supported external ids.', [
                'id' => $entity->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
            ]);
            Message::increment("{$entity->via}.{$entity->type}.failed_no_guid");
            return $this;
        }

        $metadataOnly = true === (bool)ag($opts, Options::IMPORT_METADATA_ONLY);

        /**
         * Handle new item logic here.
         */
        if (false === ($pointer = $this->getPointer($entity))) {
            if (true === $metadataOnly) {
                Message::increment("{$entity->via}.{$entity->type}.failed");
                $this->logger->notice('MAPPER: Ignoring [{backend}] [{title}]. Does not exist in database.', [
                    'metaOnly' => true,
                    'backend' => $entity->via,
                    'title' => $entity->getName(),
                    'data' => $entity->getAll(),
                ]);
                return $this;
            }

            $this->objects[] = $entity;
            $pointer = array_key_last($this->objects);

            $this->changed[$pointer] = $pointer;

            Message::increment("{$entity->via}.{$entity->type}.added");
            $this->addPointers($this->objects[$pointer], $pointer);

            if (true === $this->inTraceMode()) {
                $data = $entity->getAll();
                unset($data['id']);
                $data[iState::COLUMN_UPDATED] = makeDate($data[iState::COLUMN_UPDATED]);
                $data[iState::COLUMN_WATCHED] = 0 === $data[iState::COLUMN_WATCHED] ? 'No' : 'Yes';
                if ($entity->isMovie()) {
                    unset($data[iState::COLUMN_SEASON], $data[iState::COLUMN_EPISODE], $data[iState::COLUMN_PARENT]);
                }
            } else {
                $data = [
                    iState::COLUMN_META_DATA => [
                        $entity->via => [
                            iState::COLUMN_ID => ag($entity->getMetadata($entity->via), iState::COLUMN_ID),
                            iState::COLUMN_UPDATED => makeDate($entity->updated),
                            iState::COLUMN_GUIDS => $entity->getGuids(),
                            iState::COLUMN_PARENT => $entity->getParentGuids(),
                        ]
                    ],
                ];
            }

            $this->logger->notice('MAPPER: [{backend}] added [{title}] as new item.', [
                'backend' => $entity->via,
                'title' => $entity->getName(),
                true === $this->inTraceMode() ? 'trace' : 'metadata' => $data,
            ]);

            return $this;
        }

        $keys = [iState::COLUMN_META_DATA];

        /**
         * DO NOT operate directly on this object it should be cloned.
         * It should maintain pristine condition until changes are committed.
         */
        $cloned = clone $this->objects[$pointer];

        /**
         * ONLY update backend metadata as requested by caller.
         * if metadataOnly is set or the event is tainted.
         */
        if (true === $metadataOnly || true === $entity->isTainted()) {
            if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                $this->changed[$pointer] = $pointer;
                Message::increment("{$entity->via}.{$entity->type}.updated");

                $this->objects[$pointer] = $this->objects[$pointer]->apply(
                    entity: $entity,
                    fields: array_merge($keys, [iState::COLUMN_EXTRA])
                );

                $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

                $changes = $this->objects[$pointer]->diff(fields: $keys);

                if (count($changes) >= 1) {
                    $this->logger->notice('MAPPER: [{backend}] updated [{title}] metadata.', [
                        'id' => $cloned->id,
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                        'changes' => $changes,
                    ]);
                }
                return $this;
            }

            if (true === $this->inTraceMode()) {
                $this->logger->info('MAPPER: [{backend}] [{title}] No metadata changes detected.', [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                ]);
            }

            return $this;
        }

        // -- Item date is older than recorded last sync date logic handling.
        if (null !== ($opts['after'] ?? null) && true === ($opts['after'] instanceof iDate)) {
            if ($opts['after']->getTimestamp() >= $entity->updated) {
                // -- Handle mark as unplayed logic.
                if (false === $entity->isWatched() && true === $cloned->shouldMarkAsUnplayed(backend: $entity)) {
                    $this->changed[$pointer] = $pointer;
                    Message::increment("{$entity->via}.{$entity->type}.updated");

                    $this->objects[$pointer] = $this->objects[$pointer]->apply(
                        entity: $entity,
                        fields: array_merge($keys, [iState::COLUMN_EXTRA])
                    )->markAsUnplayed(backend: $entity);

                    $changes = $this->objects[$pointer]->diff(
                        array_merge($keys, [iState::COLUMN_WATCHED, iState::COLUMN_UPDATED])
                    );

                    if (count($changes) >= 1) {
                        $this->logger->notice('MAPPER: [{backend}] marked [{title}] as unplayed.', [
                            'id' => $cloned->id,
                            'backend' => $entity->via,
                            'title' => $cloned->getName(),
                            'changes' => $changes,
                        ]);
                    }

                    return $this;
                }

                // -- this sometimes leads to never ending updates as data from backends conflicts.
                if (true === (bool)ag($this->options, Options::MAPPER_ALWAYS_UPDATE_META)) {
                    if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
                        $this->changed[$pointer] = $pointer;
                        Message::increment("{$entity->via}.{$entity->type}.updated");

                        $this->objects[$pointer] = $this->objects[$pointer]->apply(
                            entity: $entity,
                            fields: array_merge($keys, [iState::COLUMN_EXTRA])
                        );

                        $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

                        $changes = $this->objects[$pointer]->diff(fields: $keys);

                        if (count($changes) >= 1) {
                            $this->logger->notice('MAPPER: [{backend}] updated [{title}] metadata.', [
                                'id' => $cloned->id,
                                'backend' => $entity->via,
                                'title' => $cloned->getName(),
                                'changes' => $changes,
                                'fields' => implode(',', $keys),
                            ]);
                        }

                        return $this;
                    }
                }

                if ($this->inTraceMode()) {
                    $this->logger->debug('MAPPER: Ignoring [{backend}] [{title}]. No changes detected.', [
                        'id' => $cloned->id,
                        'backend' => $entity->via,
                        'title' => $cloned->getName(),
                    ]);
                }

                Message::increment("{$entity->via}.{$entity->type}.ignored_not_played_since_last_sync");
                return $this;
            }
        }

        /**
         * Fix for #329
         * This conditional block should proceed only if specific conditions are met.
         * 1- the backend state is [unwatched] while the db state is [watched]
         * 2- if the db.metadata.backend.played_at is equal to entity.updated or the db.metadata has no data.
         * 3 - mark entity as tainted and re-process it.
         */
        if (true === $cloned->isWatched() && false === $entity->isWatched()) {
            $message = 'MAPPER: Watch state conflict detected in [{backend}] [{title}] [{new_state}] vs db [{current_state}].';
            $hasMeta = count($cloned->getMetadata($entity->via)) >= 1;
            $hasDate = $entity->updated === ag($cloned->getMetadata($entity->via), iState::COLUMN_META_DATA_PLAYED_AT);

            if (false === $hasMeta) {
                $message .= ' No metadata. Marking the item as tainted and re-processing.';
            } elseif (true === $hasDate) {
                $message .= ' db.metadata.played_at is equal to entity.updated. Marking the item as tainted and re-processing.';
            }

            $this->logger->warning($message, [
                'id' => $cloned->id,
                'backend' => $entity->via,
                'title' => $entity->getName(),
                'current_state' => $cloned->isWatched() ? 'played' : 'unplayed',
                'new_state' => $entity->isWatched() ? 'played' : 'unplayed',
            ]);

            if (false === $hasMeta || true === $hasDate) {
                $metadata = $entity->getMetadata();
                $metadata[$entity->via][iState::COLUMN_META_DATA_PLAYED_AT] = $entity->updated;
                $entity->metadata = $metadata;
                $entity->setIsTainted(true);
                return $this->add($entity, $opts);
            }
        }

        $keys = $opts['diff_keys'] ?? array_flip(
            array_keys_diff(
                base: array_flip(iState::ENTITY_KEYS),
                list: iState::ENTITY_IGNORE_DIFF_CHANGES,
                has: false
            )
        );

        if (true === (clone $cloned)->apply(entity: $entity, fields: $keys)->isChanged(fields: $keys)) {
            $this->changed[$pointer] = $pointer;
            Message::increment("{$entity->via}.{$entity->type}.updated");

            $this->objects[$pointer] = $this->objects[$pointer]->apply(
                entity: $entity,
                fields: array_merge($keys, [iState::COLUMN_EXTRA])
            );

            $this->removePointers($cloned)->addPointers($this->objects[$pointer], $pointer);

            $changes = $this->objects[$pointer]->diff(fields: $keys);

            $message = 'MAPPER: [{backend}] Updated [{title}].';

            if ($cloned->isWatched() !== $this->objects[$pointer]->isWatched()) {
                $message = 'MAPPER: [{backend}] Updated and marked [{title}] as [{state}].';
            }

            if (count($changes) >= 1) {
                $this->logger->notice($message, [
                    'id' => $cloned->id,
                    'backend' => $entity->via,
                    'title' => $cloned->getName(),
                    'changes' => $changes,
                    'state' => $this->objects[$pointer]->isWatched() ? 'played' : 'unplayed',
                    'fields' => implode(', ', $keys),
                ]);
            }

            return $this;
        }

        $context = [
            'id' => $cloned->id,
            'backend' => $entity->via,
            'title' => $cloned->getName(),
        ];

        if (true === $this->inTraceMode()) {
            $context['state'] = [
                'database' => $cloned->getAll(),
                'backend' => $entity->getAll(),
            ];
        }

        $this->logger->debug('MAPPER: [{backend}] [{title}] metadata and play state is identical.', $context);

        Message::increment("{$entity->via}.{$entity->type}.ignored_no_change");

        return $this;
    }

    public function get(iState $entity): null|iState
    {
        return false === ($pointer = $this->getPointer($entity)) ? null : $this->objects[$pointer];
    }

    public function remove(iState $entity): bool
    {
        if (false === ($pointer = $this->getPointer($entity))) {
            return false;
        }

        $this->removePointers($this->objects[$pointer]);

        $this->db->remove($this->objects[$pointer]);

        if (null !== ($this->objects[$pointer] ?? null)) {
            unset($this->objects[$pointer]);
        }

        if (null !== ($this->changed[$pointer] ?? null)) {
            unset($this->changed[$pointer]);
        }

        return true;
    }

    public function commit(): mixed
    {
        $state = $this->db->transactional(function (iDB $db) {
            $list = [
                iState::TYPE_MOVIE => ['added' => 0, 'updated' => 0, 'failed' => 0],
                iState::TYPE_EPISODE => ['added' => 0, 'updated' => 0, 'failed' => 0],
            ];

            $count = count($this->changed);

            if (0 === $count) {
                $this->logger->notice('MAPPER: No changes detected.');
                return $list;
            }
            $inDryRunMode = $this->inDryRunMode();

            if (true === $inDryRunMode) {
                $this->logger->notice('MAPPER: Recorded [{total}] object changes.', [
                    'total' => $count
                ]);
            }

            foreach ($this->changed as $pointer) {
                try {
                    $entity = &$this->objects[$pointer];

                    if (null === $entity->id) {
                        if (false === $inDryRunMode) {
                            $db->insert($entity);
                        }
                        $list[$entity->type]['added']++;
                    } else {
                        if (false === $inDryRunMode) {
                            $db->update($entity);
                        }
                        $list[$entity->type]['updated']++;
                    }
                } catch (PDOException $e) {
                    $list[$entity->type]['failed']++;
                    $this->logger->error($e->getMessage(), $entity->getAll());
                }
            }

            return $list;
        });

        $this->reset();

        return $state;
    }

    public function has(iState $entity): bool
    {
        return null !== $this->get($entity);
    }

    public function reset(): self
    {
        $this->fullyLoaded = false;
        $this->objects = $this->changed = $this->pointers = [];

        return $this;
    }

    public function getObjects(array $opts = []): array
    {
        return $this->objects;
    }

    public function getObjectsCount(): int
    {
        return count($this->objects);
    }

    public function count(): int
    {
        return count($this->changed);
    }

    public function setLogger(iLogger $logger): self
    {
        $this->logger = $logger;
        $this->db->setLogger($logger);
        return $this;
    }

    public function setDatabase(iDB $db): self
    {
        $this->db = $db;
        return $this;
    }

    public function __destruct()
    {
        if (false === (bool)ag($this->options, Options::MAPPER_DISABLE_AUTOCOMMIT) && $this->count() >= 1) {
            $this->commit();
        }
    }

    public function inDryRunMode(): bool
    {
        return true === (bool)ag($this->options, Options::DRY_RUN, false);
    }

    public function inTraceMode(): bool
    {
        return true === (bool)ag($this->options, Options::DEBUG_TRACE, false);
    }

    public function getPointersList(): array
    {
        return $this->pointers;
    }

    public function getChangedList(): array
    {
        return $this->changed;
    }

    protected function addPointers(iState $entity, string|int $pointer): iImport
    {
        foreach ($entity->getRelativePointers() as $key) {
            $this->pointers[$key] = $pointer;
        }

        foreach ($entity->getPointers() as $key) {
            $this->pointers[$key . '/' . $entity->type] = $pointer;
        }

        return $this;
    }

    /**
     * Is the object already mapped?
     *
     * @param iState $entity
     *
     * @return int|string|bool int|string pointer for the object, or false if not registered.
     */
    protected function getPointer(iState $entity): int|string|bool
    {
        if (null !== $entity->id && null !== ($this->objects[self::GUID . $entity->id] ?? null)) {
            return self::GUID . $entity->id;
        }

        foreach ($entity->getRelativePointers() as $key) {
            if (null !== ($this->pointers[$key] ?? null)) {
                return $this->pointers[$key];
            }
        }

        foreach ($entity->getPointers() as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                return $this->pointers[$lookup];
            }
        }

        if (false === $this->fullyLoaded && null !== ($lazyEntity = $this->db->get($entity))) {
            $this->objects[self::GUID . $entity->id] = $lazyEntity;

            $this->addPointers($this->objects[self::GUID . $entity->id], self::GUID . $entity->id);

            return self::GUID . $entity->id;
        }

        return false;
    }

    protected function removePointers(iState $entity): iImport
    {
        foreach ($entity->getPointers() as $key) {
            $lookup = $key . '/' . $entity->type;
            if (null !== ($this->pointers[$lookup] ?? null)) {
                unset($this->pointers[$lookup]);
            }
        }

        foreach ($entity->getRelativePointers() as $key) {
            if (null !== ($this->pointers[$key] ?? null)) {
                unset($this->pointers[$key]);
            }
        }

        return $this;
    }
}
