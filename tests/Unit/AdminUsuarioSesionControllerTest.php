<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Administrador\UsuarioSesionController;
use App\Services\api\SeccionService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminUsuarioSesionControllerTest extends TestCase
{
    public function test_non_admin_cannot_close_user_session(): void
    {
        $service = Mockery::mock(SeccionService::class);
        $service->shouldNotReceive('closeSessionByIdForUser');

        $controller = new UsuarioSesionController($service);
        $request = Request::create('/api/administrador/usuarios/10/sesiones/20', 'DELETE');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'usuario']);

        $response = $controller->destroy($request, 10, 20);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_admin_can_close_user_session(): void
    {
        $service = Mockery::mock(SeccionService::class);
        $service->shouldReceive('closeSessionByIdForUser')->once()->with(20, 10)->andReturn('closed');

        $controller = new UsuarioSesionController($service);
        $request = Request::create('/api/administrador/usuarios/10/sesiones/20', 'DELETE');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'admin']);

        $response = $controller->destroy($request, 10, 20);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'Sesion cerrada correctamente.'], $response->getData(true));
    }

    public function test_missing_session_returns_not_found(): void
    {
        $service = Mockery::mock(SeccionService::class);
        $service->shouldReceive('closeSessionByIdForUser')->once()->with(20, 10)->andReturn('not_found');

        $controller = new UsuarioSesionController($service);
        $request = Request::create('/api/administrador/usuarios/10/sesiones/20', 'DELETE');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'admin']);

        $response = $controller->destroy($request, 10, 20);

        $this->assertSame(404, $response->getStatusCode());
    }
}
