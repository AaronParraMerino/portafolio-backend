<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Administrador\UsuarioEstadoController;
use App\Models\Usuario;
use App\Services\api\Administrador\AdminUsuarioEstadoService;
use App\Services\api\AdminNotificacionGuardadoService;
use App\Services\api\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AdminInactivationNotificationTest extends TestCase
{
    private array $sendGridEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->sendGridEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_admin_can_inactivate_with_custom_reason_and_both_notification_channels(): void
    {
        foreach ([
            'SENDGRID_API_KEY' => 'testing-key',
            'SENDGRID_FROM_ADDRESS' => 'admin@example.com',
            'SENDGRID_FROM_NAME' => 'Portafolio',
            'SENDGRID_API_URL' => 'https://api.sendgrid.com/v3/mail/send',
        ] as $name => $value) {
            $this->sendGridEnvironment[$name] = getenv($name);
            putenv("{$name}={$value}");
        }

        Http::fake([
            'https://api.sendgrid.com/v3/mail/send' => Http::response([], 202),
        ]);

        $usuario = new Usuario([
            'nombre' => 'Ana',
            'apellido' => 'Perez',
            'correo' => 'ana@example.com',
            'estado' => 'activo',
        ]);
        $usuario->id_usuario = 25;

        $usuarioService = Mockery::mock(UsuarioService::class);
        $usuarioService->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $usuarioService->shouldReceive('delete')->once()->with($usuario);

        $notificacionService = Mockery::mock(AdminNotificacionGuardadoService::class);
        $notificacionService->shouldReceive('createAdminNotice')
            ->once()
            ->withArgs(fn (int $adminId, array $data) => $adminId === 3
                && $data['destinatarios'] === [25]
                && $data['mensaje'] === 'Incumplimiento de politicas.'
                && $data['tipo'] === 'cuenta'
                && $data['urgencia'] === 'alta'
                && $data['canales'] === ['inapp', 'email']
                && $data['segmentos'] === ['seleccionados'])
            ->andReturn(['status' => 'success']);

        $controller = new UsuarioEstadoController(
            new AdminUsuarioEstadoService($usuarioService, $notificacionService)
        );
        $request = Request::create('/api/administrador/usuarios/25', 'DELETE', [
            'razon' => 'Incumplimiento de politicas.',
            'canales' => ['inapp', 'email'],
        ]);
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->inactivate($request, 25);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['inapp', 'email'], $response->getData(true)['data']['canales_enviados']);
        Http::assertSent(function ($request): bool {
            $content = $request['content'][0]['value'] ?? '';

            return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
                && $request['personalizations'][0]['to'][0]['email'] === 'ana@example.com'
                && $request['subject'] === 'Tu cuenta ha sido inactivada'
                && str_contains($content, 'Ana Perez')
                && str_contains($content, 'Incumplimiento de politicas.');
        });
    }
}
