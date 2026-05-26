<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Administrador\UsuarioController;
use App\Models\Usuario;
use App\Services\api\NotificacionService;
use App\Services\api\SeccionService;
use App\Services\api\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AdminActivationNotificationTest extends TestCase
{
    private array $sendGridEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->sendGridEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_admin_can_activate_without_code_and_notify_by_both_channels(): void
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
            'estado' => 'inactivo',
        ]);
        $usuario->id_usuario = 25;

        $usuarioService = Mockery::mock(UsuarioService::class);
        $usuarioService->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $usuarioService->shouldReceive('activate')->once()->with($usuario);

        $notificacionService = Mockery::mock(NotificacionService::class);
        $notificacionService->shouldReceive('createAdminNotice')
            ->once()
            ->withArgs(fn (int $adminId, array $data) => $adminId === 3
                && $data['destinatarios'] === [25]
                && $data['titulo'] === 'Cuenta activada'
                && $data['contenido'] === 'Tu cuenta ha sido habilitada.'
                && $data['canales'] === ['inapp', 'email'])
            ->andReturn(['status' => 'success']);

        $controller = new UsuarioController(
            Mockery::mock(SeccionService::class),
            $usuarioService,
            $notificacionService
        );
        $request = Request::create('/api/administrador/usuarios/25/activar', 'PATCH', [
            'razon' => 'Tu cuenta ha sido habilitada.',
            'canales' => ['inapp', 'email'],
        ]);
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->activate($request, 25);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('activo', $response->getData(true)['data']['estado']);
        $this->assertSame(['inapp', 'email'], $response->getData(true)['data']['canales_enviados']);
        Http::assertSent(function ($request): bool {
            $content = $request['content'][0]['value'] ?? '';

            return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
                && $request['personalizations'][0]['to'][0]['email'] === 'ana@example.com'
                && $request['subject'] === 'Tu cuenta ha sido activada'
                && str_contains($content, 'Tu cuenta ha sido habilitada.')
                && ! str_contains($content, 'codigo');
        });
    }
}
