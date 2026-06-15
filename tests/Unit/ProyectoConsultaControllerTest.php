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
        $service->shouldNotReceive('listByUserPaginated');

        $controller = new ProyectoConsultaController($service);
        $request = Request::create('/api/projects/usuario/10', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->indexByUsuario($request, 10);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_user_can_request_projects_in_packages(): void
    {
        $service = Mockery::mock(ProyectoConsultaService::class);
        $service->shouldReceive('listByUserPaginated')
            ->once()
            ->with(5, 2, 12, 'es')
            ->andReturn([
                'data' => [['id' => 20]],
                'meta' => [
                    'pagina_actual' => 2,
                    'ultima_pagina' => 3,
                    'por_pagina' => 12,
                    'total' => 25,
                    'hay_mas' => true,
                ],
            ]);

        $controller = new ProyectoConsultaController($service);
        $request = Request::create('/api/projects/usuario/5?page=2&per_page=12', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->indexByUsuario($request, 5);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $response->getData(true)['meta']['pagina_actual']);
        $this->assertTrue($response->getData(true)['meta']['hay_mas']);
    }

    public function test_missing_project_returns_not_found(): void
    {
        $service = Mockery::mock(ProyectoConsultaService::class);
        $service->shouldReceive('findSerializedForUser')->once()->with(5, 10, 'es')->andReturnNull();

        $controller = new ProyectoConsultaController($service);
        $request = Request::create('/api/projects/10', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->show($request, 10);

        $this->assertSame(404, $response->getStatusCode());
    }
}
