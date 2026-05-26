<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\UsuarioController;
use App\Models\Usuario;
use App\Services\api\UsuarioService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class UserCannotSetAccountStatusTest extends TestCase
{
    public function test_user_cannot_block_or_activate_account_through_profile_update(): void
    {
        $usuario = new Usuario([
            'nombre' => 'Ana',
            'apellido' => 'Perez',
            'correo' => 'ana@example.com',
            'estado' => 'activo',
            'rol' => 'usuario',
        ]);
        $usuario->id_usuario = 25;

        $service = Mockery::mock(UsuarioService::class);
        $service->shouldReceive('findById')->once()->with(25)->andReturn($usuario);
        $service->shouldNotReceive('update');

        $controller = new UsuarioController($service);
        $request = Request::create('/api/usuarios/25', 'PUT', [
            'estado' => 'bloqueado',
        ]);
        $request->setUserResolver(fn () => $usuario);

        $response = $controller->update($request, 25);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('acciones administrativas', $response->getData(true)['message']);
    }
}
