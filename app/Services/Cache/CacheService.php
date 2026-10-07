<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Contracts\CacheServiceInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class CacheService implements CacheServiceInterface
{
    /**
     * L1 In-Memory Fast Cache for current request lifecycle.
     *
     * @var array<string, mixed>
     */
    private array $memoryCache = [];

    /**
     * @var array<string>
     */
    private array $activeTags = [];

    public function __construct(
        private readonly ?CacheRepository $store = null
    ) {}

    private function getStore(): CacheRepository
    {
        $base = $this->store ?? Cache::store();

        if (!empty($this->activeTags) && method_exists($base, 'tags')) {
            return $base->tags($this->activeTags);
        }

        return $base;
    }

    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        $fullKey = $this->resolveKey($key);

        // L1 Check
        if (array_key_exists($fullKey, $this->memoryCache)) {
            return $this->memoryCache[$fullKey];
        }

        // L2 Check / Populate
        $value = $this->getStore()->remember($key, $ttl, $callback);
        $this->memoryCache[$fullKey] = $value;

        return $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->resolveKey($key);

        if (array_key_exists($fullKey, $this->memoryCache)) {
            return $this->memoryCache[$fullKey];
        }

        $value = $this->getStore()->get($key, $default);
        if ($value !== $default) {
            $this->memoryCache[$fullKey] = $value;
        }

        return $value;
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        $fullKey = $this->resolveKey($key);
        $this->memoryCache[$fullKey] = $value;

        return (bool) $this->getStore()->put($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        $fullKey = $this->resolveKey($key);

        if (array_key_exists($fullKey, $this->memoryCache)) {
            return true;
        }

        return $this->getStore()->has($key);
    }

    public function forget(string $key): bool
    {
        $fullKey = $this->resolveKey($key);
        unset($this->memoryCache[$fullKey]);

        return (bool) $this->getStore()->forget($key);
    }

    public function tags(array|string $tags): static
    {
        $clone = clone $this;
        $clone->activeTags = is_array($tags) ? $tags : [$tags];

        return $clone;
    }

    public function flush(): bool
    {
        $this->memoryCache = [];

        return (bool) $this->getStore()->flush();
    }

    public function withLock(string $lockName, int $seconds, Closure $callback): mixed
    {
        $lock = Cache::lock($lockName, $seconds);

        return $lock->get($callback);
    }

    private function resolveKey(string $key): string
    {
        if (!empty($this->activeTags)) {
            return implode(':', $this->activeTags) . ':' . $key;
        }

        return $key;
    }
}
