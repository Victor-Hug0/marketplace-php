<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_is_ok_when_database_and_redis_respond(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_endpoint_fails_when_database_is_unreachable(): void
    {
        $connection = config('database.default');
        config(["database.connections.{$connection}.port" => 1]);
        DB::purge($connection);

        $this->get('/up')->assertStatus(500);
    }

    public function test_health_endpoint_fails_when_redis_is_unreachable(): void
    {
        config(['database.redis.default.port' => 1]);
        Redis::purge('default');

        $this->get('/up')->assertStatus(500);
    }
}
