<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoConfiguracionController;
use App\Services\api\Proyecto\ProyectoPermisoService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoConfiguracionControllerTest extends TestCase
{
    use DatabaseTransactions;

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

    public function test_cerrar_puerta_no_expulsa_participantes_sin_validacion_existentes(): void
    {
        $owner = Usuario::factory()->create();
        $participant = Usuario::factory()->create();
        $projectId = (int) DB::table('proyectos')->insertGetId([
            'titulo' => 'Proyecto puerta',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');
        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => $projectId,
            'permitir_participantes_sin_validacion' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('participaciones')->insert([
            [
                'id_usuario' => $owner->id_usuario,
                'id_proyecto' => $projectId,
                'es_propietario' => 'true',
                'participacion_validada' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_usuario' => $participant->id_usuario,
                'id_proyecto' => $projectId,
                'es_propietario' => 'false',
                'participacion_validada' => 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with($owner->id_usuario, $projectId)->andReturnTrue();
        $permissionService->shouldReceive('resolve')->twice()->with($owner->id_usuario, $projectId)->andReturn([
            'puede_configurar' => true,
        ]);
        $permissionService->shouldReceive('postgresBool')->once()->with(false)->andReturn('false');
        $permissionService->shouldReceive('configuration')->once()->with($projectId)->andReturn([
            'id_proyecto' => $projectId,
            'permitir_participantes_sin_validacion' => false,
        ]);

        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldReceive('notificarConfiguracionActualizada')->once()->andReturn([]);

        $controller = new ProyectoConfiguracionController($permissionService, $notifications);
        $request = Request::create('/api/projects/'.$projectId.'/configuration', 'PATCH', [
            'permitir_participantes_sin_validacion' => false,
        ]);
        $request->setUserResolver(fn () => (object) ['id_usuario' => $owner->id_usuario]);

        $response = $controller->update($request, $projectId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(
            DB::table('participaciones')
                ->where('id_usuario', $participant->id_usuario)
                ->where('id_proyecto', $projectId)
                ->value('deleted_at')
        );
    }
}
