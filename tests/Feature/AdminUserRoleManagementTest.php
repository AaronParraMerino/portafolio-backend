<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Administrador\UsuarioRolController;
use App\Models\Usuario;
use App\Services\api\Administrador\AdminUsuarioRolService;
use App\Services\api\AdminNotificacionGuardadoService;
use App\Services\api\UsuarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AdminUserRoleManagementTest extends TestCase
{
    private array $sendGridEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->sendGridEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_admin_can_assign_publisher_role_with_notice_and_email(): void
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
            'rol' => 'usuario',
            'estado' => 'activo',
        ]);
        $usuario->id_usuario = 25;

        $updatedUser = new Usuario([
            'nombre' => 'Ana',
            'apellido' => 'Perez',
            'correo' => 'ana@example.com',
            'rol' => 'publicante',
            'estado' => 'activo',
        ]);
        $updatedUser->id_usuario = 25;

        $usuarioService = Mockery::mock(UsuarioService::class);
        $usuarioService->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $usuarioService->shouldReceive('updateRole')->once()->with($usuario, 'publicante')->andReturn($updatedUser);

        $notificacionService = Mockery::mock(AdminNotificacionGuardadoService::class);
        $notificacionService->shouldReceive('createAdminNotice')
            ->once()
            ->withArgs(fn (int $adminId, array $data) => $adminId === 3
                && $data['destinatarios'] === [25]
                && $data['mensaje'] === 'Cumple requisitos para publicar eventos.'
                && $data['tipo'] === 'cuenta'
                && $data['canales'] === ['inapp', 'email'])
            ->andReturn(['status' => 'success']);

        $controller = new UsuarioRolController(
            new AdminUsuarioRolService($usuarioService, $notificacionService)
        );
        $request = Request::create('/api/administrador/usuarios/25/rol', 'PATCH', [
            'rol' => 'publicante',
            'razon' => 'Cumple requisitos para publicar eventos.',
            'canales' => ['inapp', 'email'],
        ]);
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->update($request, 25);
        $data = $response->getData(true)['data'];

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('publicante', $data['rol']);
        $this->assertSame(['inapp', 'email'], $data['canales_enviados']);
        Http::assertSent(function ($request): bool {
            $content = $request['content'][0]['value'] ?? '';

            return $request->url() === 'https://api.sendgrid.com/v3/mail/send'
                && $request['personalizations'][0]['to'][0]['email'] === 'ana@example.com'
                && $request['subject'] === 'Rol publicante asignado'
                && str_contains($content, 'Cumple requisitos para publicar eventos.');
        });
    }

    public function test_admin_role_cannot_be_changed_from_users_screen(): void
    {
        $usuario = new Usuario([
            'nombre' => 'Admin',
            'apellido' => 'Sistema',
            'correo' => 'root@example.com',
            'rol' => 'admin',
            'estado' => 'activo',
        ]);
        $usuario->id_usuario = 25;

        $usuarioService = Mockery::mock(UsuarioService::class);
        $usuarioService->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $usuarioService->shouldNotReceive('updateRole');

        $notificacionService = Mockery::mock(AdminNotificacionGuardadoService::class);
        $notificacionService->shouldNotReceive('createAdminNotice');

        $controller = new UsuarioRolController(
            new AdminUsuarioRolService($usuarioService, $notificacionService)
        );
        $request = Request::create('/api/administrador/usuarios/25/rol', 'PATCH', [
            'rol' => 'publicante',
            'razon' => 'Intento de cambio no permitido.',
        ]);
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 3,
            'rol' => 'admin',
        ]);

        $response = $controller->update($request, 25);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('base de datos', $response->getData(true)['message']);
    }
}
