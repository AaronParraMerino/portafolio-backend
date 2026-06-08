<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoConfiguracionController;
use App\Services\api\Proyecto\ProyectoPermisoService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoConfiguracionControllerTest extends TestCase
{
    public function test_authorized_user_can_read_project_configuration(): void
    {
        $permissions = [
            'puede_configurar' => true,
            'puede_editar' => true,
        ];
        $configuration = [
            'id_proyecto' => 10,
            'puede_editar_proyecto' => 'participantes_validados',
        ];

        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->once()->with(5, 10)->andReturn($permissions);
        $permissionService->shouldReceive('configuration')->once()->with(10)->andReturn($configuration);

        $controller = new ProyectoConfiguracionController(
            $permissionService,
            Mockery::mock(ProyectoNotificacionGuardadoService::class),
        );

        $request = Request::create('/api/projects/10/configuration', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->show($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'data' => [
                'configuracion' => $configuration,
                'permisos' => $permissions,
            ],
        ], $response->getData(true));
    }

    public function test_user_without_project_access_receives_not_found(): void
    {
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnFalse();
        $permissionService->shouldNotReceive('resolve');
        $permissionService->shouldNotReceive('configuration');

        $controller = new ProyectoConfiguracionController(
            $permissionService,
            Mockery::mock(ProyectoNotificacionGuardadoService::class),
        );

        $request = Request::create('/api/projects/10/configuration', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->show($request, 10);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Proyecto no encontrado'], $response->getData(true));
    }
}
