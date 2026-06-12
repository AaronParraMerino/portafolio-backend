<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoCrudController;
use App\Services\api\Proyecto\ProyectoCicloVidaService;
use App\Services\api\Proyecto\ProyectoConsultaService;
use App\Services\api\Proyecto\ProyectoCrudService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoCrudControllerTest extends TestCase
{
    public function test_unauthenticated_user_cannot_create_project(): void
    {
        $crudService = Mockery::mock(ProyectoCrudService::class);
        $crudService->shouldNotReceive('create');

        $controller = new ProyectoCrudController(
            $crudService,
            Mockery::mock(ProyectoConsultaService::class),
            Mockery::mock(ProyectoPermisoService::class),
            Mockery::mock(ProyectoCicloVidaService::class),
        );
        $request = Request::create('/api/projects', 'POST');

        $response = $controller->store($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'No autorizado'], $response->getData(true));
    }

    public function test_user_without_permission_cannot_update_project(): void
    {
        $crudService = Mockery::mock(ProyectoCrudService::class);
        $crudService->shouldNotReceive('update');

        $consultaService = Mockery::mock(ProyectoConsultaService::class);
        $consultaService->shouldReceive('findForUser')->once()->with(5, 10)->andReturn(['id_proyecto' => 10]);

        $permisoService = Mockery::mock(ProyectoPermisoService::class);
        $permisoService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_editar' => false]);

        $controller = new ProyectoCrudController(
            $crudService,
            $consultaService,
            $permisoService,
            Mockery::mock(ProyectoCicloVidaService::class),
        );
        $request = Request::create('/api/projects/10', 'PUT');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->update($request, 10);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'No tienes permiso para editar este proyecto'], $response->getData(true));
    }

    public function test_permanent_delete_requires_reinforced_confirmation(): void
    {
        $consultaService = Mockery::mock(ProyectoConsultaService::class);
        $consultaService->shouldReceive('findForUser')->once()->with(5, 10)->andReturn(['id_proyecto' => 10]);

        $permisoService = Mockery::mock(ProyectoPermisoService::class);
        $permisoService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_eliminar' => true]);

        $cicloVidaService = Mockery::mock(ProyectoCicloVidaService::class);
        $cicloVidaService->shouldReceive('delete')->once()->with(5, 10, null)->andReturn([
            'status' => 'confirmation_required',
            'tipo_eliminacion' => 'permanente',
            'titulo' => 'Proyecto manual',
            'message' => 'Escribe exactamente el titulo del proyecto para confirmar la eliminacion permanente.',
        ]);

        $controller = new ProyectoCrudController(
            Mockery::mock(ProyectoCrudService::class),
            $consultaService,
            $permisoService,
            $cicloVidaService,
        );
        $request = Request::create('/api/projects/10', 'DELETE');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->destroy($request, 10);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('confirmation_required', $response->getData(true)['status']);
    }

    public function test_deletion_preview_returns_lifecycle_information(): void
    {
        $consultaService = Mockery::mock(ProyectoConsultaService::class);
        $consultaService->shouldReceive('findForUser')->once()->with(5, 10)->andReturn(['id_proyecto' => 10]);

        $permisoService = Mockery::mock(ProyectoPermisoService::class);
        $permisoService->shouldReceive('resolve')->once()->with(5, 10)->andReturn(['puede_eliminar' => true]);

        $cicloVidaService = Mockery::mock(ProyectoCicloVidaService::class);
        $cicloVidaService->shouldReceive('deletionPreview')->once()->with(10)->andReturn([
            'status' => 'success',
            'tipo_eliminacion' => 'recuperable',
            'repositorios_vinculados' => 2,
        ]);

        $controller = new ProyectoCrudController(
            Mockery::mock(ProyectoCrudService::class),
            $consultaService,
            $permisoService,
            $cicloVidaService,
        );
        $request = Request::create('/api/projects/10/deletion-preview', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->deletionPreview($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('recuperable', $response->getData(true)['data']['tipo_eliminacion']);
    }
}
