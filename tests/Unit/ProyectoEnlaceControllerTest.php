<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoEnlaceController;
use App\Services\api\Proyecto\ProyectoConsultaService;
use App\Services\api\Proyecto\ProyectoEnlaceService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoEnlaceControllerTest extends TestCase
{
    public function test_missing_project_cannot_update_links(): void
    {
        $enlaceService = Mockery::mock(ProyectoEnlaceService::class);
        $enlaceService->shouldNotReceive('update');

        $consultaService = Mockery::mock(ProyectoConsultaService::class);
        $consultaService->shouldReceive('findForUser')->once()->with(5, 10)->andReturnNull();

        $controller = new ProyectoEnlaceController(
            $enlaceService,
            $consultaService,
            Mockery::mock(ProyectoPermisoService::class),
        );
        $request = Request::create('/api/projects/10/links', 'PATCH');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->update($request, 10);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Proyecto no encontrado'], $response->getData(true));
    }

    public function test_authorized_user_can_update_links(): void
    {
        $payload = ['url_demo' => 'https://example.com/demo'];
        $serialized = ['id_proyecto' => 10, 'url_demo' => $payload['url_demo']];

        $enlaceService = Mockery::mock(ProyectoEnlaceService::class);
        $enlaceService->shouldReceive('update')->once()->with(5, 10, $payload)->andReturn($serialized);

        $consultaService = Mockery::mock(ProyectoConsultaService::class);
        $consultaService->shouldReceive('findForUser')->once()->with(5, 10)->andReturn(['id_proyecto' => 10]);

        $permisoService = Mockery::mock(ProyectoPermisoService::class);
        $permisoService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => true]);

        $controller = new ProyectoEnlaceController($enlaceService, $consultaService, $permisoService);
        $request = Request::create('/api/projects/10/links', 'PATCH', $payload);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->update($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => $serialized], $response->getData(true));
    }
}
