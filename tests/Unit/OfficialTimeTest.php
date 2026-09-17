<?php

namespace Tests\Unit;

use App\Services\OfficialTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfficialTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OfficialTime::flushState();
        Cache::forget(OfficialTime::CACHE_OFFSET);
        Cache::forget(OfficialTime::CACHE_FETCHED);
    }

    public function test_uses_internet_time_when_provider_responds(): void
    {
        $remote = time() + 3600;
        Http::fake([
            'worldtimeapi.org/*' => Http::response(['unixtime' => $remote], 200),
        ]);

        $this->assertSame('internet', OfficialTime::source());
        $this->assertEqualsWithDelta($remote, OfficialTime::now()->timestamp, 5);
        $this->assertEqualsWithDelta(3600, (int) OfficialTime::skewSeconds(), 5);
        $this->assertSame('America/Guayaquil', OfficialTime::now()->timezoneName);
    }

    public function test_falls_back_to_local_clock_without_internet(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('sin internet');
        });

        $this->assertSame('local', OfficialTime::source());
        $this->assertNull(OfficialTime::skewSeconds());
        $this->assertEqualsWithDelta(time(), OfficialTime::now()->timestamp, 5);
    }

    public function test_reuses_cached_offset_when_recalibration_fails(): void
    {
        Cache::put(OfficialTime::CACHE_OFFSET, 7200, now()->addDay());
        Cache::put(OfficialTime::CACHE_FETCHED, now()->subHour()->toDateTimeString(), now()->addDay());

        Http::fake(function (): never {
            throw new \RuntimeException('sin internet');
        });

        $this->assertSame('internet', OfficialTime::source());
        $this->assertEqualsWithDelta(time() + 7200, OfficialTime::now()->timestamp, 5);
    }
}
