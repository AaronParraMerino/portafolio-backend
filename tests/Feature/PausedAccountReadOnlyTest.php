<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsWritable;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PausedAccountReadOnlyTest extends TestCase
{
    public function test_paused_account_can_read_information(): void
    {
        $request = Request::create('/api/profile/25', 'GET');
        $request->setUserResolver(fn () => $this->pausedUser());

        $response = (new EnsureAccountIsWritable())->handle(
            $request,
            fn () => response()->json(['allowed' => true])
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_paused_account_cannot_modify_information(): void
    {
        $request = Request::create('/api/profile/25', 'PATCH');
        $request->setUserResolver(fn () => $this->pausedUser());

        $response = (new EnsureAccountIsWritable())->handle(
            $request,
            fn () => response()->json(['allowed' => true])
        );
        $data = $response->getData(true);

        $this->assertSame(423, $response->getStatusCode());
        $this->assertSame('account_paused', $data['status']);
    }

    public function test_paused_account_cannot_modify_routes_that_were_previously_unprotected(): void
    {
        Sanctum::actingAs($this->pausedUser());

        $requests = [
            fn () => $this->postJson('/api/eventos-personales'),
            fn () => $this->postJson('/api/eventos/25/10/inscribirse'),
            fn () => $this->postJson('/api/publicante/solicitudes'),
            fn () => $this->patchJson('/api/notificaciones/25/read-all'),
            fn () => $this->postJson('/api/administrador/notificaciones'),
        ];

        foreach ($requests as $request) {
            $request()
                ->assertStatus(423)
                ->assertJsonPath('status', 'account_paused');
        }
    }

    private function pausedUser(): Usuario
    {
        return new Usuario(['estado' => 'pausado']);
    }
}
