<?php

namespace Tests\Unit;

use App\Models\CuentaOauth;
use App\Models\Usuario;
use App\Services\api\Auth\DiscordOAuthService;
use App\Services\api\Auth\GithubOAuthService;
use App\Services\api\Auth\GitlabOAuthService;
use App\Services\api\Auth\GoogleOAuthService;
use App\Services\api\AuthService;
use App\Services\api\Proyecto\ProyectoProveedorDesvinculacionService;
use App\Services\api\SeccionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class AuthServiceProviderUnlinkTest extends TestCase
{
    use DatabaseTransactions;

    public function test_unlink_exitoso_invalida_proveedor_antes_de_eliminar_cuenta(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'github');
        $this->createAccount($user->id_usuario, 'google');

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldReceive('desvincularProveedor')
            ->once()
            ->with($user->id_usuario, 'github')
            ->andReturn(['status' => 'success']);

        $result = $this->service($providerService)->unlinkOAuthAccountFromUser($user->id_usuario, 'github');

        $this->assertSame('success', $result['status']);
        $this->assertNull(CuentaOauth::where('usuario_id', $user->id_usuario)->where('provider', 'github')->first());
        $this->assertNotNull(CuentaOauth::where('usuario_id', $user->id_usuario)->where('provider', 'google')->first());
    }

    public function test_no_invalida_si_es_ultima_cuenta_vinculada(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'github');

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldNotReceive('desvincularProveedor');

        $result = $this->service($providerService)->unlinkOAuthAccountFromUser($user->id_usuario, 'github');

        $this->assertSame('last_provider', $result['status']);
        $this->assertNotNull(CuentaOauth::where('usuario_id', $user->id_usuario)->where('provider', 'github')->first());
    }

    public function test_fallo_al_invalidar_proveedor_conserva_cuenta_oauth(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'github');
        $this->createAccount($user->id_usuario, 'google');

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldReceive('desvincularProveedor')
            ->once()
            ->with($user->id_usuario, 'github')
            ->andThrow(new \RuntimeException('Fallo de reconciliacion'));

        try {
            $this->service($providerService)->unlinkOAuthAccountFromUser($user->id_usuario, 'github');
            $this->fail('Se esperaba fallo de reconciliacion');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo de reconciliacion', $exception->getMessage());
        }

        $this->assertNotNull(CuentaOauth::where('usuario_id', $user->id_usuario)->where('provider', 'github')->first());
    }

    private function service(ProyectoProveedorDesvinculacionService $providerService): AuthService
    {
        return new AuthService(
            Mockery::mock(SeccionService::class),
            Mockery::mock(GoogleOAuthService::class),
            Mockery::mock(GithubOAuthService::class),
            Mockery::mock(GitlabOAuthService::class),
            Mockery::mock(DiscordOAuthService::class),
            null,
            $providerService,
        );
    }

    private function createAccount(int $userId, string $provider): void
    {
        CuentaOauth::create([
            'usuario_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $provider.'-'.$userId,
            'email' => $provider.'-'.$userId.'@example.com',
            'nombre' => ucfirst($provider),
        ]);
    }
}
