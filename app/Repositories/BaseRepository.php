<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\BaseRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

abstract class BaseRepository implements BaseRepositoryInterface
{
    public function __construct(
        protected Model $model
    ) {}

    public function findById(int|string $id, array $columns = ['*'], array $relations = []): ?Model
    {
        return $this->model->newQuery()->with($relations)->find($id, $columns);
    }

    public function findOrFail(int|string $id, array $columns = ['*'], array $relations = []): Model
    {
        $result = $this->findById($id, $columns, $relations);

        if ($result === null) {
            throw (new ModelNotFoundException())->setModel(get_class($this->model), [$id]);
        }

        return $result;
    }

    public function findOneBy(array $attributes, array $columns = ['*'], array $relations = []): ?Model
    {
        return $this->model->newQuery()->with($relations)->where($attributes)->first($columns);
    }

    public function getAll(array $columns = ['*'], array $relations = []): Collection
    {
        return $this->model->newQuery()->with($relations)->get($columns);
    }

    public function create(array $attributes): Model
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function update(Model|int|string $model, array $attributes): Model
    {
        $instance = $model instanceof Model ? $model : $this->findOrFail($model);
        $instance->update($attributes);

        return $instance->fresh();
    }

    public function delete(Model|int|string $model): bool
    {
        $instance = $model instanceof Model ? $model : $this->findOrFail($model);

        return (bool) $instance->delete();
    }

    public function paginate(
        int $perPage = 15,
        array $columns = ['*'],
        string $pageName = 'page',
        ?int $page = null
    ): LengthAwarePaginator {
        return $this->model->newQuery()->paginate($perPage, $columns, $pageName, $page);
    }
}
