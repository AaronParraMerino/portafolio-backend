<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Administrador\NotificacionController;
use App\Services\api\NotificacionService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdminNotificationAuthorizationTest extends TestCase
{
    public function test_non_admin_cannot_send_notices_to_users(): void
    {
        $service = Mockery::mock(NotificacionService::class);
        $service->shouldNotReceive('createAdminNotice');

        $controller = new NotificacionController($service);
        $request = Request::create('/api/administrador/notificaciones', 'POST');
        $request->setUserResolver(fn () => (object) [
            'id_usuario' => 10,
            'rol' => 'usuario',
        ]);

        $response = $controller->store($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['message' => 'No tienes permiso para enviar avisos a usuarios.'],
            $response->getData(true)
        );
    }
}
