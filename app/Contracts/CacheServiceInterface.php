<?php

declare(strict_types=1);

namespace App\Contracts;

use Closure;
use DateInterval;
use DateTimeInterface;

interface CacheServiceInterface
{
    /**
     * Retrieve an item from the cache, or execute the given Closure and store the result.
     *
     * @template T
     * @param string $key
     * @param DateTimeInterface|DateInterval|int|null $ttl (in seconds or Carbon/DateTime)
     * @param Closure(): T $callback
     * @return T
     */
    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed;

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store an item in the cache.
     */
    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool;

    /**
     * Check if an item exists in the cache.
     */
    public function has(string $key): bool;

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool;

    /**
     * Tagged cache operation for grouped invalidation (supported by Redis).
     *
     * @param array<string>|string $tags
     * @return static
     */
    public function tags(array|string $tags): static;

    /**
     * Flush all items in the cache or active tagged group.
     */
    public function flush(): bool;

    /**
     * Execute callback with atomic distributed lock (protects against Cache Stampedes).
     *
     * @template T
     * @param string $lockName
     * @param int $seconds
     * @param Closure(): T $callback
     * @return T|false
     */
    public function withLock(string $lockName, int $seconds, Closure $callback): mixed;
}
