<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AuthServiceInterface;
use App\Contracts\CacheServiceInterface;
use App\Contracts\DriveItemRepositoryInterface;
use App\Contracts\DriveServiceInterface;
use App\Contracts\EncryptionServiceInterface;
use App\Contracts\TokenServiceInterface;
use App\Contracts\UserRepositoryInterface;
use App\Repositories\DriveItemRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Cache\CacheService;
use App\Services\Drive\DriveService;
use App\Services\Security\EncryptionService;
use App\Services\Security\JwtTokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Core Security, Token, and High-Performance Cache Services (Singletons for peak speed)
        $this->app->singleton(EncryptionServiceInterface::class, EncryptionService::class);
        $this->app->singleton(TokenServiceInterface::class, JwtTokenService::class);
        $this->app->scoped(CacheServiceInterface::class, CacheService::class);

        // Repositories
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(DriveItemRepositoryInterface::class, DriveItemRepository::class);

        // Domain Services
        $this->app->bind(AuthServiceInterface::class, AuthService::class);
        $this->app->bind(DriveServiceInterface::class, DriveService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Prevent N+1 queries during development and enforce strict model attributes
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
