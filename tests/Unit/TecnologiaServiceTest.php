<?php

namespace Tests\Unit;

use App\Services\api\TecnologiaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class TecnologiaServiceTest extends TestCase
{
    public function test_devicon_lookup_falls_back_when_cache_store_cannot_write(): void
    {
        Cache::shouldReceive('remember')
            ->once()
            ->with('devicon_json', \Mockery::any(), \Mockery::type('callable'))
            ->andThrow(new \RuntimeException('Permission denied'));

        Http::fake([
            'raw.githubusercontent.com/devicons/devicon/master/devicon.json' => Http::response([
                [
                    'name' => 'cobol',
                    'altnames' => ['COBOL'],
                    'tags' => ['mainframe'],
                ],
            ]),
        ]);

        $method = (new ReflectionClass(TecnologiaService::class))->getMethod('buscarEnDevicon');
        $method->setAccessible(true);

        $result = $method->invoke(new TecnologiaService(), 'Cobol');

        $this->assertSame('cobol', $result['name']);
    }
}
