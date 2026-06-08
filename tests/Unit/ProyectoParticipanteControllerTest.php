<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Proyecto\ProyectoParticipanteController;
use App\Services\api\Proyecto\ProyectoParticipanteService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class ProyectoParticipanteControllerTest extends TestCase
{
    public function test_user_without_access_cannot_list_participants(): void
    {
        $permissionService = Mockery::mock(ProyectoPermisoService::class);
        $permissionService->shouldReceive('userHasAccess')->once()->with(5, 10)->andReturnFalse();

        $participantService = Mockery::mock(ProyectoParticipanteService::class);
        $participantService->shouldNotReceive('list');

        $controller = new ProyectoParticipanteController($permissionService, $participantService);
        $request = Request::create('/api/projects/10/participants', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->index($request, 10);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['message' => 'Proyecto no encontrado'], $response->getData(true));
    }

    public function test_sole_owner_cannot_detach_from_project(): void
    {
        $participantService = Mockery::mock(ProyectoParticipanteService::class);
        $participantService->shouldReceive('detach')->once()->with(10, 5)->andReturn(['status' => 'sole_owner']);

        $controller = new ProyectoParticipanteController(
            Mockery::mock(ProyectoPermisoService::class),
            $participantService
        );
        $request = Request::create('/api/projects/10/participation', 'DELETE');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5]);

        $response = $controller->detach($request, 10);

        $this->assertSame(422, $response->getStatusCode());
    }
}
