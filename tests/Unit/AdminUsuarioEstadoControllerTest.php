<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Administrador\UsuarioEstadoController;
use App\Services\api\Administrador\AdminUsuarioEstadoService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminUsuarioEstadoControllerTest extends TestCase
{
    public function test_non_admin_cannot_change_account_status(): void
    {
        $service = Mockery::mock(AdminUsuarioEstadoService::class);
        $service->shouldNotReceive('block');

        $controller = new UsuarioEstadoController($service);
        $request = Request::create('/api/administrador/usuarios/10/bloquear', 'PATCH');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'usuario']);

        $response = $controller->block($request, 10);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_controller_returns_service_status_and_body(): void
    {
        $result = ['body' => ['message' => 'Usuario no encontrado.'], 'status' => 404];
        $service = Mockery::mock(AdminUsuarioEstadoService::class);
        $service->shouldReceive('pause')
            ->once()
            ->with(5, 10, Mockery::type('string'))
            ->andReturn($result);

        $controller = new UsuarioEstadoController($service);
        $request = Request::create('/api/administrador/usuarios/10/pausar', 'PATCH');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'admin']);

        $response = $controller->pause($request, 10);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($result['body'], $response->getData(true));
    }
}
