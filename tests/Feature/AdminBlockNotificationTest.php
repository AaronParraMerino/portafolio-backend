<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Administrador\UsuarioController;
use App\Models\Usuario;
use App\Services\api\NotificacionService;
use App\Services\api\SeccionService;
use App\Services\api\UsuarioService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminBlockNotificationTest extends TestCase
{
    public function test_admin_can_block_account_with_required_inapp_reason(): void
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
        $usuarioService->shouldReceive('block')->once()->with($usuario);

        $notificacionService = Mockery::mock(NotificacionService::class);
        $notificacionService->shouldReceive('createAdminNotice')
            ->once()
            ->withArgs(fn (int $adminId, array $data) => $adminId === 3
                && $data['destinatarios'] === [25]
                && $data['titulo'] === 'Cuenta bloqueada'
                && $data['contenido'] === 'Incumplimiento grave de politicas.'
                && $data['tipo'] === 'seguridad'
                && $data['canales'] === ['inapp'])
            ->andReturn(['status' => 'success']);

        $controller = new UsuarioController(
            Mockery::mock(SeccionService::class),
            $usuarioService,
            $notificacionService
        );
        $request = Request::create('/api/administrador/usuarios/25/bloquear', 'PATCH', [
            'razon' => 'Incumplimiento grave de politicas.',
        ]);
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->block($request, 25);
        $data = $response->getData(true)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('bloqueado', $data['estado']);
        $this->assertSame('Incumplimiento grave de politicas.', $data['razon']);
        $this->assertSame(['inapp'], $data['canales_enviados']);
    }
}
