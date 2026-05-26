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
        $service->shouldNotReceive('getUserNotifications');
        $service->shouldNotReceive('markNotificationAsRead');
        $service->shouldNotReceive('markNotificationsAsRead');
        $service->shouldNotReceive('markAllNotificationsAsRead');
        $service->shouldNotReceive('countUnreadNotifications');

        $controller = new NotificationController($service);
        $request = Request::create('/api/notificaciones/20', 'GET');
        $request->setUserResolver(fn () => (object) ['id_usuario' => 10]);

        $responses = [
            $controller->index($request, 20),
            $controller->markAsRead($request, 20, 1),
            $controller->markManyAsRead($request, 20),
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
