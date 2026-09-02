<?php

namespace Neo4j\Neo4jLaravel\Concerns;

use Illuminate\Support\Str;

/**
 * Configure a standard Eloquent model for Neo4j node persistence.
 *
 * Models keep a UUID property as the Eloquent primary key (`id` by default).
 * When a full node is returned from Cypher, Neo4j's native element id is also
 * hydrated onto the model and available via {@see elementId()}.
 *
 * Laravel's SoftDeletes trait works with Neo4j models: soft delete sets
 * `deleted_at`, and force delete removes the node (DETACH DELETE).
 */
trait HasNeo4jConnection
{
    public static function bootHasNeo4jConnection(): void
    {
        static::creating(function ($model): void {
            $key = $model->getKeyName();

            if ($key !== null && $model->getAttribute($key) === null) {
                $model->setAttribute($key, (string) Str::uuid());
            }
        });
    }

    public function initializeHasNeo4jConnection(): void
    {
        $this->connection ??= 'neo4j';
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    /**
     * Neo4j element id for the matched node, when a full node was returned.
     *
     * Distinct from the Eloquent UUID primary key property (`id`).
     */
    public function elementId(): ?string
    {
        return isset($this->attributes['elementId'])
            ? (string) $this->attributes['elementId']
            : null;
    }

    /**
     * Exclude Neo4j metadata from CREATE payloads.
     *
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        $attributes = parent::getAttributesForInsert();
        unset($attributes['elementId']);

        return $attributes;
    }

    /**
     * Exclude Neo4j metadata from SET payloads.
     *
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate()
    {
        $dirty = parent::getDirtyForUpdate();
        unset($dirty['elementId']);

        return $dirty;
    }

    /**
     * Use a node label instead of Eloquent's plural snake-case table name.
     */
    public function getTable(): string
    {
        return $this->table ?? Str::studly(class_basename($this));
    }

    public function getLabel(): string
    {
        return $this->getTable();
    }

    public function setLabel(string $label): static
    {
        return $this->setTable($label);
    }
}
