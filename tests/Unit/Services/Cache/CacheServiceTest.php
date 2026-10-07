<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cache;

use App\Contracts\CacheServiceInterface;
use Tests\TestCase;

class CacheServiceTest extends TestCase
{
    private CacheServiceInterface $cacheService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheService = app(CacheServiceInterface::class);
    }

    public function test_it_stores_and_retrieves_items(): void
    {
        $key = 'test_key_' . uniqid();
        $value = ['data' => 'fast_cache_value', 'timestamp' => time()];

        $this->cacheService->put($key, $value, 60);

        $this->assertTrue($this->cacheService->has($key));
        $this->assertEquals($value, $this->cacheService->get($key));

        $this->cacheService->forget($key);
        $this->assertFalse($this->cacheService->has($key));
    }

    public function test_it_remembers_computed_value(): void
    {
        $key = 'remember_key_' . uniqid();
        $counter = 0;

        $computed = $this->cacheService->remember($key, 60, function () use (&$counter) {
            $counter++;
            return 'computed_result';
        });

        $this->assertEquals('computed_result', $computed);
        $this->assertEquals(1, $counter);

        // Second call must hit L1 or L2 cache, callback not executed
        $second = $this->cacheService->remember($key, 60, function () use (&$counter) {
            $counter++;
            return 'new_result';
        });

        $this->assertEquals('computed_result', $second);
        $this->assertEquals(1, $counter);
    }
}
