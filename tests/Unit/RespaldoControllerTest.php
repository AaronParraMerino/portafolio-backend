<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Administrador\RespaldoController;
use App\Services\api\Administrador\AdminRespaldoService;
use App\Services\api\BitacoraService;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Mockery;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class RespaldoControllerTest extends TestCase
{
    public function test_full_backup_ignores_empty_tables_array(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'backup-test-');
        file_put_contents($path, '{"metadata":{},"tables":{}}');

        $backupService = Mockery::mock(AdminRespaldoService::class);
        $backupService->shouldReceive('generate')
            ->once()
            ->with('full', [])
            ->andReturn([
                'path' => $path,
                'filename' => 'creafolio-completo.json',
                'mode' => 'full',
                'tables' => [],
            ]);

        $auditService = Mockery::mock(BitacoraService::class);
        $auditService->shouldReceive('record')->once();

        $admin = new Usuario();
        $admin->id_usuario = 5;
        $admin->rol = 'admin';

        $request = Request::create(
            '/api/administrador/respaldos/generar',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['mode' => 'full', 'tables' => []]),
        );
        $request->setUserResolver(fn () => $admin);

        $response = (new RespaldoController($backupService, $auditService))->generate($request);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        @unlink($path);
    }

    public function test_generation_failure_returns_service_unavailable_with_reason(): void
    {
        $backupService = Mockery::mock(AdminRespaldoService::class);
        $backupService->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('No se pudo exportar la informacion del respaldo.'));

        $auditService = Mockery::mock(BitacoraService::class);
        $auditService->shouldNotReceive('record');

        $admin = new Usuario();
        $admin->id_usuario = 5;
        $admin->rol = 'admin';

        $request = Request::create(
            '/api/administrador/respaldos/generar',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['mode' => 'full']),
        );
        $request->setUserResolver(fn () => $admin);

        $response = (new RespaldoController($backupService, $auditService))->generate($request);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(
            'No se pudo exportar la informacion del respaldo.',
            $response->getData(true)['message'],
        );
    }
}
