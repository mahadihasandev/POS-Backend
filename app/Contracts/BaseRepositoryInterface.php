<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface BaseRepositoryInterface
{
    /**
     * Find a model by its primary key.
     */
    public function findById(int|string $id, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Find a model or throw an exception.
     */
    public function findOrFail(int|string $id, array $columns = ['*'], array $relations = []): Model;

    /**
     * Find a single record by specific attributes.
     */
    public function findOneBy(array $attributes, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Get all records matching attributes.
     */
    public function getAll(array $columns = ['*'], array $relations = []): Collection;

    /**
     * Create a new model record.
     */
    public function create(array $attributes): Model;

    /**
     * Update an existing model.
     */
    public function update(Model|int|string $model, array $attributes): Model;

    /**
     * Delete a model record.
     */
    public function delete(Model|int|string $model): bool;

    /**
     * Paginate query results.
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator;
}
