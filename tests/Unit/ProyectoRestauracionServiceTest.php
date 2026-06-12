<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\Proyecto\ProyectoCicloVidaService;
use App\Services\api\Proyecto\ProyectoRestauracionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProyectoRestauracionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_validated_repository_owner_can_restore_project(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto restaurable');
        $repo = $this->createRepository($projectId, 'github', 'api');
        $this->validateRepository($owner->id_usuario, $repo['remote_id'], 'owner', true);

        $result = $this->service()->restore($owner->id_usuario, $projectId);

        $this->assertSame('success', $result['status']);
        $this->assertNull(DB::table('proyectos')->where('id_proyecto', $projectId)->value('deleted_at'));
        $this->assertDatabaseHas('participaciones', [
            'id_usuario' => $owner->id_usuario,
            'id_proyecto' => $projectId,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('bitacoras', [
            'accion' => 'proyecto_restaurado',
            'registro_afectado_id' => $projectId,
        ]);
    }

    public function test_validated_collaborator_joins_and_sends_request_to_all_owners(): void
    {
        $ownerA = Usuario::factory()->create();
        $ownerB = Usuario::factory()->create();
        $collaborator = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto solicitado');
        $repoA = $this->createRepository($projectId, 'github', 'backend');
        $repoB = $this->createRepository($projectId, 'gitlab', 'frontend');
        $this->validateRepository($ownerA->id_usuario, $repoA['remote_id'], 'owner', true);
        $this->validateRepository($ownerB->id_usuario, $repoB['remote_id'], 'owner', true);
        $this->validateRepository($collaborator->id_usuario, $repoA['remote_id'], 'collaborator', false);
        DB::table('participaciones')->insert([
            'id_usuario' => $collaborator->id_usuario,
            'id_proyecto' => $projectId,
            'participacion_validada' => 'false',
            'es_propietario' => 'false',
            'estado_participacion' => 'retirado',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service()->requestRestore($collaborator->id_usuario, $projectId, 'Por favor restaurar');

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['destinatarios']);
        $participation = DB::table('participaciones')
            ->where('id_usuario', $collaborator->id_usuario)
            ->where('id_proyecto', $projectId)
            ->first();
        $this->assertNotNull($participation);
        $this->assertTrue($this->databaseBool($participation->participacion_validada));
        $this->assertFalse($this->databaseBool($participation->es_propietario));
        $this->assertNull($participation->deleted_at);
        $this->assertSame(
            2,
            DB::table('notificacion_usuario')->where('id_notificacion', $result['id_notificacion'])->count()
        );
    }

    public function test_owner_rejects_request_and_applies_fifteen_day_cooldown(): void
    {
        [$owner, $collaborator, $projectId] = $this->projectWithOwnerAndCollaborator();
        $request = $this->service()->requestRestore($collaborator->id_usuario, $projectId);

        $result = $this->service()->respond(
            $owner->id_usuario,
            $projectId,
            $request['id_notificacion'],
            'rechazar',
            'Aun no'
        );

        $this->assertSame('rejected', $result['status']);
        $notification = DB::table('notificaciones')->where('id_notificacion', $request['id_notificacion'])->first();
        $this->assertSame('rechazada', $notification->accion_estado);
        $this->assertNotNull($notification->accion_disponible_nuevamente_at);
        $this->assertSame('cooldown', $this->service()->requestRestore($collaborator->id_usuario, $projectId)['status']);
    }

    public function test_owner_approves_request_and_restores_project(): void
    {
        [$owner, $collaborator, $projectId] = $this->projectWithOwnerAndCollaborator();
        $request = $this->service()->requestRestore($collaborator->id_usuario, $projectId);

        $result = $this->service()->respond(
            $owner->id_usuario,
            $projectId,
            $request['id_notificacion'],
            'aprobar'
        );

        $this->assertSame('approved', $result['status']);
        $this->assertNull(DB::table('proyectos')->where('id_proyecto', $projectId)->value('deleted_at'));
        $this->assertSame(
            'aprobada',
            DB::table('notificaciones')->where('id_notificacion', $request['id_notificacion'])->value('accion_estado')
        );
    }

    public function test_owner_releases_repository_and_last_repository_requires_confirmation(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto a eliminar');
        $repoA = $this->createRepository($projectId, 'github', 'backend');
        $repoB = $this->createRepository($projectId, 'github', 'frontend');
        $this->validateRepository($owner->id_usuario, $repoA['remote_id'], 'owner', true);
        $this->validateRepository($owner->id_usuario, $repoB['remote_id'], 'owner', true);

        $service = $this->service();
        $released = $service->releaseRepository($owner->id_usuario, $projectId, $repoA['project_repo_id']);
        $confirmation = $service->releaseRepository($owner->id_usuario, $projectId, $repoB['project_repo_id']);

        $this->assertSame('released', $released['status']);
        $this->assertNull(DB::table('proyecto_repositorios')->where('id_proyecto_repositorio', $repoA['project_repo_id'])->value('id_proyecto'));
        $this->assertSame('confirmation_required', $confirmation['status']);
        $this->assertSame($projectId, DB::table('proyecto_repositorios')->where('id_proyecto_repositorio', $repoB['project_repo_id'])->value('id_proyecto'));
    }

    public function test_releasing_last_repository_delegates_atomic_permanent_deletion(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto final');
        $repo = $this->createRepository($projectId, 'github', 'principal');
        $this->validateRepository($owner->id_usuario, $repo['remote_id'], 'owner', true);

        $lifecycle = Mockery::mock(ProyectoCicloVidaService::class);
        $lifecycle->shouldReceive('permanentlyDeleteDeletedProject')
            ->once()
            ->with(
                $owner->id_usuario,
                $projectId,
                'Proyecto final',
                'eliminacion_permanente_ultimo_repositorio',
                $repo['project_repo_id']
            )
            ->andReturn(['status' => 'permanently_deleted']);

        $result = (new ProyectoRestauracionService($lifecycle))->releaseRepository(
            $owner->id_usuario,
            $projectId,
            $repo['project_repo_id'],
            'Proyecto final'
        );

        $this->assertSame('released_and_project_deleted', $result['status']);
        $this->assertSame(
            $projectId,
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $repo['project_repo_id'])
                ->value('id_proyecto')
        );
    }

    public function test_validated_collaborator_restores_when_no_validated_owner_exists(): void
    {
        $collaborator = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto sin propietario');
        $repo = $this->createRepository($projectId, 'github', 'community');
        $this->validateRepository($collaborator->id_usuario, $repo['remote_id'], 'collaborator', false);

        $result = $this->service()->restore($collaborator->id_usuario, $projectId);

        $this->assertSame('success', $result['status']);
        $this->assertNull(DB::table('proyectos')->where('id_proyecto', $projectId)->value('deleted_at'));
    }

    private function projectWithOwnerAndCollaborator(): array
    {
        $owner = Usuario::factory()->create();
        $collaborator = Usuario::factory()->create();
        $projectId = $this->createDeletedProject('Proyecto con solicitud');
        $repo = $this->createRepository($projectId, 'github', 'principal');
        $this->validateRepository($owner->id_usuario, $repo['remote_id'], 'owner', true);
        $this->validateRepository($collaborator->id_usuario, $repo['remote_id'], 'collaborator', false);

        return [$owner, $collaborator, $projectId];
    }

    private function service(): ProyectoRestauracionService
    {
        return new ProyectoRestauracionService(Mockery::mock(ProyectoCicloVidaService::class));
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
            'github_repo_id' => random_int(1000000, 9999999),
            'github_owner' => 'test',
            'github_repo_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_repositorio_github');

        return ['project_repo_id' => $projectRepoId, 'remote_id' => $remoteId];
    }

    private function validateRepository(int $userId, int $remoteId, string $relation, bool $owner): void
    {
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $userId,
            'id_repositorio_github' => $remoteId,
            'relacion_github' => $relation,
            'es_propietario' => $owner ? 'true' : 'false',
            'validado' => 'true',
            'validado_at' => now(),
            'ultima_verificacion_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function databaseBool(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
