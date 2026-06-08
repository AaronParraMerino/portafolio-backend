<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Administrador\UsuarioRolController;
use App\Services\api\Administrador\AdminUsuarioRolService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminUsuarioRolControllerTest extends TestCase
{
    public function test_non_admin_cannot_update_user_role(): void
    {
        $service = Mockery::mock(AdminUsuarioRolService::class);
        $service->shouldNotReceive('update');

        $controller = new UsuarioRolController($service);
        $request = Request::create('/api/administrador/usuarios/10/rol', 'PATCH');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'usuario']);

        $response = $controller->update($request, 10);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_controller_returns_role_service_result(): void
    {
        $payload = [
            'rol' => 'publicante',
            'razon' => 'Cumple requisitos suficientes.',
            'canales' => ['inapp'],
        ];
        $result = [
            'body' => ['message' => 'Rol actualizado y aviso enviado correctamente.'],
            'status' => 200,
        ];
        $service = Mockery::mock(AdminUsuarioRolService::class);
        $service->shouldReceive('update')
            ->once()
            ->with(5, 10, 'publicante', $payload['razon'], ['inapp'])
            ->andReturn($result);

        $controller = new UsuarioRolController($service);
        $request = Request::create('/api/administrador/usuarios/10/rol', 'PATCH', $payload);
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'admin']);

        $response = $controller->update($request, 10);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($result['body'], $response->getData(true));
    }
}
