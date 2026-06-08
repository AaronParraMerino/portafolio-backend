<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoConsultaController;
use App\Services\api\Proyecto\ProyectoConsultaService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoConsultaControllerTest extends TestCase
{
    public function test_user_cannot_list_another_users_projects(): void
    {
        $service = Mockery::mock(ProyectoConsultaService::class);
        $service->shouldNotReceive('listByUser');

        $controller = new ProyectoConsultaController($service);
        $request = Request::create('/api/projects/usuario/10', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->indexByUsuario($request, 10);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_missing_project_returns_not_found(): void
    {
        $service = Mockery::mock(ProyectoConsultaService::class);
        $service->shouldReceive('findSerializedForUser')->once()->with(5, 10)->andReturnNull();

        $controller = new ProyectoConsultaController($service);
        $request = Request::create('/api/projects/10', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->show($request, 10);

        $this->assertSame(404, $response->getStatusCode());
    }
}
