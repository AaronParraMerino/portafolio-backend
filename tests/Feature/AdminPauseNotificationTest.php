<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Administrador\UsuarioEstadoController;
use App\Models\Usuario;
use App\Services\api\Administrador\AdminUsuarioEstadoService;
use App\Services\api\AdminNotificacionGuardadoService;
use App\Services\api\UsuarioService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminPauseNotificationTest extends TestCase
{
    public function test_admin_can_pause_active_account_with_required_inapp_reason(): void
    {
        $usuario = new Usuario([
            'nombre' => 'Ana',
            'apellido' => 'Perez',
            'correo' => 'ana@example.com',
            'estado' => 'activo',
        ]);
        $usuario->id_usuario = 25;

        $usuarioService = Mockery::mock(UsuarioService::class);
        $usuarioService->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $usuarioService->shouldReceive('pause')->once()->with($usuario);

        $notificacionService = Mockery::mock(AdminNotificacionGuardadoService::class);
        $notificacionService->shouldReceive('createAdminNotice')
            ->once()
            ->withArgs(fn (int $adminId, array $data) => $adminId === 3
                && $data['destinatarios'] === [25]
                && $data['mensaje'] === 'Revision administrativa pendiente.'
                && $data['tipo'] === 'cuenta'
                && $data['urgencia'] === 'media'
                && $data['canales'] === ['inapp']
                && $data['segmentos'] === ['seleccionados'])
            ->andReturn(['status' => 'success']);

        $controller = new UsuarioEstadoController(
            new AdminUsuarioEstadoService($usuarioService, $notificacionService)
        );
        $request = Request::create('/api/administrador/usuarios/25/pausar', 'PATCH', [
            'razon' => 'Revision administrativa pendiente.',
        ]);
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->pause($request, 25);
        $data = $response->getData(true)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pausado', $data['estado']);
        $this->assertSame('Revision administrativa pendiente.', $data['razon']);
        $this->assertSame(['inapp'], $data['canales_enviados']);
    }

    public function test_admin_cannot_pause_hidden_account_without_activating_it_first(): void
    {
        $usuario = new Usuario(['estado' => 'inactivo']);
        $usuario->id_usuario = 25;

        $usuarioService = Mockery::mock(UsuarioService::class);
        $usuarioService->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $usuarioService->shouldNotReceive('pause');

        $controller = new UsuarioEstadoController(
            new AdminUsuarioEstadoService(
                $usuarioService,
                Mockery::mock(AdminNotificacionGuardadoService::class)
            )
        );
        $request = Request::create('/api/administrador/usuarios/25/pausar', 'PATCH');
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->pause($request, 25);

        $this->assertSame(422, $response->getStatusCode());
    }
}
