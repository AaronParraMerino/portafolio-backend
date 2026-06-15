<?php

namespace Tests\Unit;

use App\Models\CuentaOauth;
use App\Models\Usuario;
use App\Services\api\Auth\GitlabOAuthService;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\GitlabRepositorySyncService;
use App\Services\api\Proyecto\ProyectoProveedorDesvinculacionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RepositorySyncAccessLossTest extends TestCase
{
    use DatabaseTransactions;

    public function test_github_invalida_ausentes_solo_despues_de_lista_remota_exitosa(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'github');
        Http::fake([
            'api.github.com/user/repos*' => Http::response([], 200),
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldReceive('reconciliarRepositoriosPresentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'github', [])
            ->andReturn(['usuarios_validados' => []]);
        $providerService->shouldReceive('invalidarRepositoriosAusentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'github', [])
            ->andReturn(['validaciones_invalidadas' => 2, 'usuarios_desvinculados' => [$user->id_usuario]]);

        $result = (new GithubRepositorySyncService($providerService))->syncForUsuario($user->id_usuario);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['stats']['validaciones_revocadas']);
        $this->assertSame(1, $result['stats']['participaciones_desvinculadas']);
    }

    public function test_github_no_invalida_ausentes_si_token_es_invalido(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'github');
        Http::fake([
            'api.github.com/user/repos*' => Http::response([], 401),
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldNotReceive('reconciliarRepositoriosPresentesConfirmados');
        $providerService->shouldNotReceive('invalidarRepositoriosAusentesConfirmados');

        $result = (new GithubRepositorySyncService($providerService))->syncForUsuario($user->id_usuario);

        $this->assertSame('invalid_token', $result['status']);
    }

    public function test_github_reconcilia_repositorios_presentes_confirmados(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'github');
        $repo = $this->githubRepoPayload(501);
        Http::fake([
            'api.github.com/user/repos*' => Http::response([$repo], 200),
            'api.github.com/repos/*' => Http::response([], 200),
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldReceive('reconciliarRepositoriosPresentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'github', [501])
            ->andReturn(['participaciones_validadas' => 1, 'usuarios_validados' => [$user->id_usuario]]);
        $providerService->shouldReceive('invalidarRepositoriosAusentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'github', [501])
            ->andReturn(['validaciones_invalidadas' => 0, 'usuarios_desvinculados' => []]);

        $result = (new GithubRepositorySyncService($providerService))->syncForUsuario($user->id_usuario);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['stats']['participaciones_validadas']);
    }

    public function test_gitlab_invalida_ausentes_solo_despues_de_lista_remota_completa(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'gitlab');
        Http::fake([
            'gitlab.com/api/v4/projects*' => Http::response([], 200),
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldReceive('reconciliarRepositoriosPresentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'gitlab', [])
            ->andReturn(['usuarios_validados' => []]);
        $providerService->shouldReceive('invalidarRepositoriosAusentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'gitlab', [])
            ->andReturn(['validaciones_invalidadas' => 1, 'usuarios_desvinculados' => []]);

        $result = (new GitlabRepositorySyncService(
            Mockery::mock(GitlabOAuthService::class),
            $providerService,
        ))->syncForUsuario($user->id_usuario);

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['stats']['lista_remota_completa']);
        $this->assertSame(1, $result['stats']['validaciones_revocadas']);
    }

    public function test_gitlab_no_invalida_ausentes_si_proveedor_falla(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'gitlab');
        Http::fake([
            'gitlab.com/api/v4/projects*' => Http::response([], 500),
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldNotReceive('reconciliarRepositoriosPresentesConfirmados');
        $providerService->shouldNotReceive('invalidarRepositoriosAusentesConfirmados');

        $result = (new GitlabRepositorySyncService(
            Mockery::mock(GitlabOAuthService::class),
            $providerService,
        ))->syncForUsuario($user->id_usuario);

        $this->assertSame('github_error', $result['status']);
    }

    public function test_gitlab_reconcilia_repositorios_presentes_aunque_lista_sea_incompleta(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'gitlab');
        $page = array_fill(0, 100, $this->gitlabRepoPayload(701));
        Http::fake([
            'gitlab.com/api/v4/projects*' => Http::sequence()
                ->push($page, 200)
                ->push($page, 200)
                ->push($page, 200),
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldReceive('reconciliarRepositoriosPresentesConfirmados')
            ->once()
            ->with($user->id_usuario, 'gitlab', Mockery::on(
                fn ($ids) => $ids !== [] && collect($ids)->every(fn ($id) => (int) $id === 701)
            ))
            ->andReturn(['participaciones_validadas' => 1, 'usuarios_validados' => [$user->id_usuario]]);
        $providerService->shouldNotReceive('invalidarRepositoriosAusentesConfirmados');

        $result = (new GitlabRepositorySyncService(
            Mockery::mock(GitlabOAuthService::class),
            $providerService,
        ))->syncForUsuario($user->id_usuario);

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['stats']['lista_remota_completa']);
        $this->assertSame(1, $result['stats']['participaciones_validadas']);
    }

    public function test_gitlab_no_invalida_ausentes_si_token_vencido_no_puede_renovarse(): void
    {
        $user = Usuario::factory()->create();
        $this->createAccount($user->id_usuario, 'gitlab', [
            'token_expires_at' => now()->subMinute(),
            'refresh_token' => null,
        ]);

        $providerService = Mockery::mock(ProyectoProveedorDesvinculacionService::class);
        $providerService->shouldNotReceive('reconciliarRepositoriosPresentesConfirmados');
        $providerService->shouldNotReceive('invalidarRepositoriosAusentesConfirmados');
        $oauth = Mockery::mock(GitlabOAuthService::class);
        $oauth->shouldReceive('refreshAccessToken')
            ->once()
            ->andReturn([
                'status' => 'invalid_token',
                'message' => 'Token vencido',
            ]);

        $result = (new GitlabRepositorySyncService(
            $oauth,
            $providerService,
        ))->syncForUsuario($user->id_usuario);

        $this->assertSame('invalid_token', $result['status']);
    }

    public function test_gitlab_marca_lista_incompleta_al_alcanzar_limite_de_paginas(): void
    {
        $service = new GitlabRepositorySyncService(
            Mockery::mock(GitlabOAuthService::class),
            Mockery::mock(ProyectoProveedorDesvinculacionService::class),
        );
        $page = array_fill(0, 100, ['id' => 1]);

        Http::fake([
            'gitlab.com/api/v4/projects*' => Http::sequence()
                ->push($page, 200)
                ->push($page, 200)
                ->push($page, 200),
        ]);

        $method = new \ReflectionMethod($service, 'fetchAllGitlabProjects');
        $result = $method->invoke($service, 'token');

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['complete']);
        $this->assertCount(300, $result['repos']);
    }

    private function createAccount(int $userId, string $provider, array $overrides = []): void
    {
        CuentaOauth::create([
            'usuario_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $provider.'-'.$userId,
            'email' => $provider.'-'.$userId.'@example.com',
            'nombre' => ucfirst($provider),
            'access_token' => 'token',
            ...$overrides,
        ]);
    }

    private function githubRepoPayload(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'repo-'.$id,
            'html_url' => 'https://github.com/test/repo-'.$id,
            'owner' => ['id' => 999, 'login' => 'test'],
            'permissions' => ['pull' => true],
        ];
    }

    private function gitlabRepoPayload(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'repo-'.$id,
            'path' => 'repo-'.$id,
            'web_url' => 'https://gitlab.com/test/repo-'.$id,
            'namespace' => ['full_path' => 'test', 'path' => 'test'],
            'permissions' => ['project_access' => ['access_level' => 30]],
        ];
    }
}
