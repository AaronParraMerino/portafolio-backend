<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\api\Administrador\AdminRespaldoService;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AdminBackupDownloadTest extends TestCase
{
    public function test_admin_can_request_full_backup_without_tables(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'backup-http-test-');
        file_put_contents($path, '{"metadata":{},"tables":{}}');

        $service = Mockery::mock(AdminRespaldoService::class);
        $service->shouldReceive('generate')
            ->once()
            ->with('full', [])
            ->andReturn([
                'path' => $path,
                'filename' => 'creafolio-completo.json',
                'mode' => 'full',
                'tables' => [],
            ]);
        $this->app->instance(AdminRespaldoService::class, $service);

        $admin = Usuario::query()->where('rol', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/administrador/respaldos/generar', [
            'mode' => 'full',
        ]);

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8')
            ->assertDownload('creafolio-completo.json');

        File::delete($path);
    }
}
