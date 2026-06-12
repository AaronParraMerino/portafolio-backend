<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoRestauracionController;
use App\Services\api\Proyecto\ProyectoRestauracionService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoRestauracionControllerTest extends TestCase
{
    public function test_restore_forbidden_returns_403(): void
    {
        $service = Mockery::mock(ProyectoRestauracionService::class);
        $service->shouldReceive('restore')->once()->with(5, 10)->andReturn(['status' => 'forbidden']);

        $response = (new ProyectoRestauracionController($service))->restore($this->request('POST'), 10);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_pending_restore_request_returns_409(): void
    {
        $service = Mockery::mock(ProyectoRestauracionService::class);
        $service->shouldReceive('requestRestore')->once()->with(5, 10, null)->andReturn([
            'status' => 'pending',
            'id_notificacion' => 20,
        ]);

        $response = (new ProyectoRestauracionController($service))->requestRestore($this->request('POST'), 10);

        $this->assertSame(409, $response->getStatusCode());
    }

    public function test_approved_restore_request_returns_200(): void
    {
        $service = Mockery::mock(ProyectoRestauracionService::class);
        $service->shouldReceive('respond')->once()->with(5, 10, 20, 'aprobar', null)->andReturn([
            'status' => 'approved',
        ]);
        $request = $this->request('PATCH', ['decision' => 'aprobar']);

        $response = (new ProyectoRestauracionController($service))->respond($request, 10, 20);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_last_repository_confirmation_returns_409(): void
    {
        $service = Mockery::mock(ProyectoRestauracionService::class);
        $service->shouldReceive('releaseRepository')->once()->with(5, 10, 30, null)->andReturn([
            'status' => 'confirmation_required',
            'ultimo_repositorio' => true,
        ]);

        $response = (new ProyectoRestauracionController($service))->releaseRepository($this->request('DELETE'), 10, 30);

        $this->assertSame(409, $response->getStatusCode());
    }

    private function request(string $method, array $data = []): Request
    {
        $request = Request::create('/api/projects/10', $method, $data);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        return $request;
    }
}
