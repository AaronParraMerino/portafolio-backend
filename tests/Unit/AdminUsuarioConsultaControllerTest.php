<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Administrador\UsuarioConsultaController;
use App\Services\api\Administrador\AdminUsuarioPanelService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminUsuarioConsultaControllerTest extends TestCase
{
    public function test_non_admin_cannot_read_user_panel(): void
    {
        $service = Mockery::mock(AdminUsuarioPanelService::class);
        $service->shouldNotReceive('getPanel');

        $controller = new UsuarioConsultaController($service);
        $request = Request::create('/api/administrador/usuarios', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'usuario']);

        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_admin_receives_user_panel_from_service(): void
    {
        $panel = [
            'items' => [],
            'communications' => [],
            'history' => [],
            'templates' => [],
            'metrics' => ['total' => 0],
        ];
        $service = Mockery::mock(AdminUsuarioPanelService::class);
        $service->shouldReceive('getPanel')->once()->andReturn($panel);

        $controller = new UsuarioConsultaController($service);
        $request = Request::create('/api/administrador/usuarios', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 5, 'rol' => 'admin']);

        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($panel, $response->getData(true));
    }
}
