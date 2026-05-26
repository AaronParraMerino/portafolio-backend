<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsWritable;
use App\Models\Usuario;
use Illuminate\Http\Request;
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

    private function pausedUser(): Usuario
    {
        return new Usuario(['estado' => 'pausado']);
    }
}
