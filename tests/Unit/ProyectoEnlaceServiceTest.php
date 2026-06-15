<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\GithubRepositorySyncService;
use App\Services\api\Proyecto\ProyectoConsultaService;
use App\Services\api\Proyecto\ProyectoEnlaceService;
use App\Services\api\Proyecto\ProyectoParticipacionValidacionService;
use App\Services\api\Proyecto\ProyectoSerializer;
use App\Services\api\ProyectoNotificacionGuardadoService;
use App\Services\api\TecnologiaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProyectoEnlaceServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quitar_ultimo_repositorio_lo_desvincula_sin_sacar_participante_ni_borrar_validacion(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject();
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $repo = $this->createRepository($projectId, 'github', 'api');
        $this->createValidationAndLink($user->id_usuario, $participationId, $repo, true);

        $service = $this->serviceExpectingGithubSync($user->id_usuario, $projectId, []);
        $this->syncRepositories($service, $user->id_usuario, $projectId, []);

        $this->assertNull(
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $repo['project_repo_id'])
                ->value('id_proyecto')
        );
        $this->assertDatabaseMissing('participacion_repositorios', [
            'id_participacion' => $participationId,
            'id_proyecto_repositorio' => $repo['project_repo_id'],
        ]);
        $this->assertDatabaseHas('usuario_repositorio_validaciones', [
            'id_usuario' => $user->id_usuario,
            'id_repositorio_github' => $repo['remote_id'],
        ]);
        $participation = DB::table('participaciones')->where('id_participacion', $participationId)->first();
        $this->assertFalse($this->databaseBool($participation->participacion_validada));
        $this->assertNull($participation->deleted_at);
    }

    public function test_quitar_github_conserva_gitlab_y_participante_permanece_validado(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject();
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $github = $this->createRepository($projectId, 'github', 'api');
        $gitlab = $this->createRepository($projectId, 'gitlab', 'frontend');
        $this->createValidationAndLink($user->id_usuario, $participationId, $github, true);
        $this->createValidationAndLink($user->id_usuario, $participationId, $gitlab, true);

        $service = $this->serviceExpectingGithubSync($user->id_usuario, $projectId, []);
        $this->syncRepositories($service, $user->id_usuario, $projectId, [$gitlab['url']]);

        $this->assertNull(
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $github['project_repo_id'])
                ->value('id_proyecto')
        );
        $this->assertSame(
            $projectId,
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $gitlab['project_repo_id'])
                ->value('id_proyecto')
        );
        $this->assertTrue($this->databaseBool(
            DB::table('participaciones')->where('id_participacion', $participationId)->value('participacion_validada')
        ));
        $this->assertDatabaseHas('usuario_repositorio_validaciones', [
            'id_usuario' => $user->id_usuario,
            'id_repositorio_github' => $github['remote_id'],
        ]);
    }

    public function test_quitar_repositorio_recalcula_todos_los_participantes_sin_desvincularlos(): void
    {
        $editor = Usuario::factory()->create();
        $collaborator = Usuario::factory()->create();
        $projectId = $this->createProject();
        $editorParticipationId = $this->createParticipation($editor->id_usuario, $projectId, true);
        $collaboratorParticipationId = $this->createParticipation($collaborator->id_usuario, $projectId, true);
        $repo = $this->createRepository($projectId, 'github', 'api');
        $this->createValidationAndLink($editor->id_usuario, $editorParticipationId, $repo, true);
        $this->createValidationAndLink($collaborator->id_usuario, $collaboratorParticipationId, $repo, true);

        $service = $this->serviceExpectingGithubSync($editor->id_usuario, $projectId, []);
        $this->syncRepositories($service, $editor->id_usuario, $projectId, []);

        foreach ([$editorParticipationId, $collaboratorParticipationId] as $participationId) {
            $participation = DB::table('participaciones')->where('id_participacion', $participationId)->first();
            $this->assertFalse($this->databaseBool($participation->participacion_validada));
            $this->assertNull($participation->deleted_at);
        }
    }

    public function test_url_equivalente_con_barra_final_no_desvincula_repositorio(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject();
        $repo = $this->createRepository($projectId, 'gitlab', 'frontend');

        $service = $this->serviceExpectingGithubSync($user->id_usuario, $projectId, []);
        $this->syncRepositories($service, $user->id_usuario, $projectId, [$repo['url'].'/']);

        $this->assertSame(
            $projectId,
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $repo['project_repo_id'])
                ->value('id_proyecto')
        );
    }

    public function test_fallo_al_validar_nuevas_urls_no_desvincula_repositorios_existentes(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject();
        $repo = $this->createRepository($projectId, 'gitlab', 'frontend');
        $newGithubUrl = 'https://github.com/test/invalido';

        $github = Mockery::mock(GithubRepositorySyncService::class);
        $github->shouldReceive('syncProjectRepoUrlsForUsuario')
            ->once()
            ->with($user->id_usuario, $projectId, [$newGithubUrl], false)
            ->andReturn([
                'status' => 'error',
                'http_status' => 422,
                'message' => 'Repositorio invalido',
            ]);

        try {
            $this->syncRepositories(
                $this->service($github),
                $user->id_usuario,
                $projectId,
                [$newGithubUrl]
            );
            $this->fail('Se esperaba error de validacion');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(
            $projectId,
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $repo['project_repo_id'])
                ->value('id_proyecto')
        );
    }

    private function serviceExpectingGithubSync(int $userId, int $projectId, array $githubUrls): ProyectoEnlaceService
    {
        $github = Mockery::mock(GithubRepositorySyncService::class);
        $github->shouldReceive('syncProjectRepoUrlsForUsuario')
            ->once()
            ->with($userId, $projectId, $githubUrls, false)
            ->andReturn(['status' => 'success']);

        return $this->service($github);
    }

    private function service(GithubRepositorySyncService $github): ProyectoEnlaceService
    {
        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldNotReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion');

        return new ProyectoEnlaceService(
            $github,
            Mockery::mock(TecnologiaService::class),
            Mockery::mock(ProyectoNotificacionGuardadoService::class),
            Mockery::mock(ProyectoConsultaService::class),
            Mockery::mock(ProyectoSerializer::class),
            new ProyectoParticipacionValidacionService($notifications),
        );
    }

    private function syncRepositories(
        ProyectoEnlaceService $service,
        int $userId,
        int $projectId,
        array $urls,
    ): void {
        $method = new \ReflectionMethod($service, 'syncProjectRepositories');
        $method->invoke($service, $userId, $projectId, ['url_repositorios' => $urls]);
    }

    private function createProject(): int
    {
        $projectId = (int) DB::table('proyectos')->insertGetId([
            'titulo' => 'Proyecto enlaces',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');

        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => $projectId,
            'permitir_participantes_sin_validacion' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $projectId;
    }

    private function createParticipation(int $userId, int $projectId, bool $validated): int
    {
        return (int) DB::table('participaciones')->insertGetId([
            'id_usuario' => $userId,
            'id_proyecto' => $projectId,
            'es_propietario' => 'false',
            'participacion_validada' => $validated ? 'true' : 'false',
            'estado_participacion' => 'activo',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_participacion');
    }

    private function createRepository(int $projectId, string $provider, string $name): array
    {
        $url = "https://{$provider}.com/test/{$name}";
        $projectRepoId = (int) DB::table('proyecto_repositorios')->insertGetId([
            'id_proyecto' => $projectId,
            'nombre' => $name,
            'proveedor' => $provider,
            'url_repositorio' => $url,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto_repositorio');

        $remoteId = (int) DB::table('repositorio_github')->insertGetId([
            'id_proyecto_repositorio' => $projectRepoId,
            'github_repo_id' => random_int(100000, 999999),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_repositorio_github');

        return [
            'project_repo_id' => $projectRepoId,
            'remote_id' => $remoteId,
            'url' => $url,
        ];
    }

    private function createValidationAndLink(int $userId, int $participationId, array $repo, bool $validated): void
    {
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $userId,
            'id_repositorio_github' => $repo['remote_id'],
            'validado' => $validated ? 'true' : 'false',
            'es_propietario' => 'false',
            'validado_at' => $validated ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('participacion_repositorios')->insert([
            'id_participacion' => $participationId,
            'id_proyecto_repositorio' => $repo['project_repo_id'],
            'validado' => $validated ? 'true' : 'false',
            'es_propietario' => 'false',
            'validado_at' => $validated ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function databaseBool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
