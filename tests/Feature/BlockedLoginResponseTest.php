<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Services\api\AuthService;
use App\Services\api\SeccionService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class BlockedLoginResponseTest extends TestCase
{
    public function test_blocked_login_returns_reason_for_frontend_modal(): void
    {
        $authService = Mockery::mock(AuthService::class);
        $authService->shouldReceive('attemptLogin')
            ->once()
            ->with([
                'correo' => 'ana@example.com',
                'password' => 'secret123',
            ])
            ->andReturn([
                'status' => 'blocked',
                'razon' => 'Actividad no permitida.',
            ]);

        $controller = new AuthController($authService, Mockery::mock(SeccionService::class));
        $request = Request::create('/api/auth/login', 'POST', [
            'correo' => 'ana@example.com',
            'password' => 'secret123',
        ]);

        $response = $controller->login($request);
        $data = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked_account', $data['status']);
        $this->assertSame('Actividad no permitida.', $data['razon']);
    }
}
