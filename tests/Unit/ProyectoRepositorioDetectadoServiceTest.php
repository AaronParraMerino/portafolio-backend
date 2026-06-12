<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\Proyecto\ProyectoRepositorioDetectadoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProyectoRepositorioDetectadoServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_validation_can_restore_and_release_deleted_project(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto recuperable');
        $repo = $this->createRepository($projectId, 'github', 'backend');
        $this->validateRepository($user->id_usuario, $repo['remote_id'], 'owner', true);

        $repos = $this->service()->reposForUser($user->id_usuario, 'github');
        $detected = $repos->firstWhere('id_proyecto', $projectId);

        $this->assertNotNull($detected);
        $this->assertSame('proyecto_eliminado', $detected['estado_vinculacion']);
        $this->assertTrue($detected['recuperacion']['puede_restaurar']);
        $this->assertTrue($detected['recuperacion']['puede_liberar_repositorio']);
        $this->assertFalse($detected['recuperacion']['puede_solicitar_restauracion']);
        $this->assertSame(1, $detected['recuperacion']['propietarios_validados_total']);
    }

    public function test_validated_collaborator_can_request_but_unvalidated_user_cannot_see_deleted_project(): void
    {
        $owner = Usuario::factory()->create();
        $collaborator = Usuario::factory()->create();
        $unvalidated = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto colaborativo');
        $repo = $this->createRepository($projectId, 'gitlab', 'frontend');
        $this->validateRepository($owner->id_usuario, $repo['remote_id'], 'owner', true);
        $this->validateRepository($collaborator->id_usuario, $repo['remote_id'], 'collaborator', false);
        $this->validateRepository($unvalidated->id_usuario, $repo['remote_id'], 'unknown', false, false);

        $service = $this->service();
        $collaboratorRepos = $service->reposForUser($collaborator->id_usuario, 'gitlab');
        $unvalidatedRepos = $service->reposForUser($unvalidated->id_usuario, 'gitlab');

        $collaboratorDetected = $collaboratorRepos->firstWhere('id_proyecto', $projectId);
        $unvalidatedDetected = $unvalidatedRepos->firstWhere('id_proyecto', $projectId);

        $this->assertNotNull($collaboratorDetected);
        $this->assertTrue($collaboratorDetected['recuperacion']['puede_solicitar_restauracion']);
        $this->assertTrue($collaboratorDetected['recuperacion']['requiere_unirse_para_solicitar']);
        $this->assertFalse($collaboratorDetected['recuperacion']['puede_restaurar']);
        $this->assertNull($unvalidatedDetected);
    }

    public function test_deleted_project_groups_multiple_detected_repositories_once(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto con varios repos');
        $repoA = $this->createRepository($projectId, 'github', 'api');
        $repoB = $this->createRepository($projectId, 'github', 'web');
        $this->validateRepository($user->id_usuario, $repoA['remote_id'], 'owner', true);
        $this->validateRepository($user->id_usuario, $repoB['remote_id'], 'owner', true);

        $service = $this->service();
        $repos = $service->reposForUser($user->id_usuario, 'github');
        $groups = collect($service->deletedProjectGroups($repos));
        $group = $groups->first(fn (array $item) => (int) $item['proyecto']['id_proyecto'] === $projectId);

        $this->assertNotNull($group);
        $this->assertCount(2, $group['repositorios_detectados']);
        $this->assertSame(2, $group['recuperacion']['repositorios_vinculados_total']);
        $this->assertSame($projectId, $group['proyecto']['id_proyecto']);
    }

    public function test_pending_restore_request_blocks_duplicate_request(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto solicitado');
        $repo = $this->createRepository($projectId, 'github', 'worker');
        $this->validateRepository($user->id_usuario, $repo['remote_id'], 'collaborator', false);

        DB::table('notificaciones')->insert([
            'id_usuario_actor' => $user->id_usuario,
            'modulo' => 'proyectos',
            'contexto_tipo' => 'proyecto',
            'contexto_referencia' => 'proyecto_'.$projectId,
            'grupo_titulo' => 'Proyecto solicitado',
            'tipo' => 'project_restore_request',
            'mensaje' => 'Solicitud pendiente',
            'accion_estado' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detected = $this->service()
            ->reposForUser($user->id_usuario, 'github')
            ->firstWhere('id_proyecto', $projectId);

        $this->assertTrue($detected['recuperacion']['solicitud_pendiente']);
        $this->assertFalse($detected['recuperacion']['puede_solicitar_restauracion']);
    }

    public function test_validated_collaborator_can_restore_when_project_has_no_validated_owner(): void
    {
        $collaborator = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto sin propietario');
        $repo = $this->createRepository($projectId, 'github', 'community');
        $this->validateRepository($collaborator->id_usuario, $repo['remote_id'], 'collaborator', false);

        $detected = $this->service()
            ->reposForUser($collaborator->id_usuario, 'github')
            ->firstWhere('id_proyecto', $projectId);

        $this->assertTrue($detected['recuperacion']['puede_restaurar']);
        $this->assertFalse($detected['recuperacion']['puede_solicitar_restauracion']);
        $this->assertSame(0, $detected['recuperacion']['propietarios_validados_total']);
    }

    public function test_active_open_project_is_not_advertised_without_validated_repository_relation(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createActiveProject('Proyecto de acceso por URL');
        $this->createRepository($projectId, 'github', 'shared');
        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => $projectId,
            'permitir_participantes_sin_validacion' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $detected = $this->service()
            ->reposForUser($user->id_usuario, 'github')
            ->firstWhere('id_proyecto', $projectId);

        $this->assertNull($detected);
    }

    private function service(): ProyectoRepositorioDetectadoService
    {
        return new ProyectoRepositorioDetectadoService();
    }

    private function createDeletedProject(string $title): int
    {
        return (int) DB::table('proyectos')->insertGetId([
            'titulo' => $title,
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');
    }

    private function createActiveProject(string $title): int
    {
        return (int) DB::table('proyectos')->insertGetId([
            'titulo' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');
    }

    private function createRepository(int $projectId, string $provider, string $name): array
    {
        $projectRepoId = (int) DB::table('proyecto_repositorios')->insertGetId([
            'id_proyecto' => $projectId,
            'nombre' => $name,
            'proveedor' => $provider,
            'url_repositorio' => "https://{$provider}.com/test/{$name}",
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto_repositorio');

        $remoteId = (int) DB::table('repositorio_github')->insertGetId([
            'id_proyecto_repositorio' => $projectRepoId,
            'github_repo_id' => random_int(100000, 999999),
            'github_owner' => 'test',
            'github_repo_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_repositorio_github');

        return ['project_repo_id' => $projectRepoId, 'remote_id' => $remoteId];
    }

    private function validateRepository(
        int $userId,
        int $remoteId,
        string $relation,
        bool $owner,
        bool $validated = true
    ): void {
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $userId,
            'id_repositorio_github' => $remoteId,
            'relacion_github' => $relation,
            'es_propietario' => $owner ? 'true' : 'false',
            'validado' => $validated ? 'true' : 'false',
            'validado_at' => $validated ? now() : null,
            'ultima_verificacion_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
