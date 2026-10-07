<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Models\User;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        /** @var User|null $user */
        $user = $this->model->newQuery()
            ->where('email', strtolower(trim($email)))
            ->first();

        return $user;
    }

    public function emailExists(string $email): bool
    {
        return $this->model->newQuery()
            ->where('email', strtolower(trim($email)))
            ->exists();
    }
}
