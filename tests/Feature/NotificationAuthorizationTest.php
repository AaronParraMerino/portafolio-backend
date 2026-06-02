<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\NotificationController;
use App\Services\api\NotificacionService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class NotificationAuthorizationTest extends TestCase
{
    public function test_authenticated_user_cannot_operate_on_another_users_notifications(): void
    {
        $service = Mockery::mock(NotificacionService::class);
        $service->shouldNotReceive('obtenerResumenModulosNoLeidos');
        $service->shouldNotReceive('obtenerSegundoNivelPorModulo');
        $service->shouldNotReceive('obtenerMensajesNoLeidosPorGrupo');
        $service->shouldNotReceive('obtenerNotificacionesLeidas');
        $service->shouldNotReceive('marcarNotificacionComoLeida');
        $service->shouldNotReceive('marcarNotificacionComoNoLeida');
        $service->shouldNotReceive('marcarGrupoComoLeido');
        $service->shouldNotReceive('marcarModuloComoLeido');
        $service->shouldNotReceive('marcarTodasComoLeidas');
        $service->shouldNotReceive('contarNoLeidas');

        $controller = new NotificationController($service);
        $request = Request::create('/api/notificaciones/20', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 10]);

        $responses = [
            $controller->modulos($request, 20),
            $controller->segundoNivel($request, 20, 'proyectos'),
            $controller->mensajesGrupo($request, 20, 'proyectos', 'proyecto_1'),
            $controller->readNotifications($request, 20),
            $controller->markAsRead($request, 20, 1),
            $controller->markAsUnread($request, 20, 1),
            $controller->markGroupAsRead($request, 20),
            $controller->markModuleAsRead($request, 20),
            $controller->markAllAsRead($request, 20),
            $controller->countUnread($request, 20),
        ];

        foreach ($responses as $response) {
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(
                ['message' => 'No autorizado'],
                $response->getData(true)
            );
        }
    }
}
