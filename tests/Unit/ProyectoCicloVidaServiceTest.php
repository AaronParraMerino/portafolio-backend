<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\ProfileImageVariantService;
use App\Services\api\Proyecto\ProyectoCicloVidaService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProyectoCicloVidaServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_soft_delete_preserves_owners_and_validated_participants(): void
    {
        $owner = Usuario::factory()->create();
        $unvalidatedUser = Usuario::factory()->create();
        $validatedUser = Usuario::factory()->create();
        $projectId = $this->createProject('Proyecto recuperable');
        $repositoryId = $this->createRepository($projectId);

        $ownerParticipationId = $this->createParticipation($owner->id_usuario, $projectId, [
            'es_propietario' => true,
        ]);
        $unvalidatedParticipationId = $this->createParticipation($unvalidatedUser->id_usuario, $projectId);
        $validatedParticipationId = $this->createParticipation($validatedUser->id_usuario, $projectId);

        DB::table('participacion_repositorios')->insert([
            'id_participacion' => $validatedParticipationId,
            'id_proyecto_repositorio' => $repositoryId,
            'validado' => 'true',
            'es_propietario' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->service()->delete($owner->id_usuario, $projectId);

        $this->assertSame('soft_deleted', $result['status']);
        $this->assertSame(1, $result['participaciones_sin_validacion_desvinculadas']);
        $this->assertNotNull(DB::table('proyectos')->where('id_proyecto', $projectId)->value('deleted_at'));
        $this->assertNull(DB::table('participaciones')->where('id_participacion', $ownerParticipationId)->value('deleted_at'));
        $this->assertNotNull(DB::table('participaciones')->where('id_participacion', $unvalidatedParticipationId)->value('deleted_at'));
        $this->assertNull(DB::table('participaciones')->where('id_participacion', $validatedParticipationId)->value('deleted_at'));
    }

    public function test_project_without_repositories_requires_title_before_permanent_delete(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createProject('Proyecto permanente');
        $this->createParticipation($owner->id_usuario, $projectId, ['es_propietario' => true]);

        $service = $this->service();
        $withoutConfirmation = $service->delete($owner->id_usuario, $projectId);

        $this->assertSame('confirmation_required', $withoutConfirmation['status']);
        $this->assertNotNull(DB::table('proyectos')->where('id_proyecto', $projectId)->first());

        $result = $service->delete($owner->id_usuario, $projectId, 'Proyecto permanente');

        $this->assertSame('permanently_deleted', $result['status']);
        $this->assertNull(DB::table('proyectos')->where('id_proyecto', $projectId)->first());
        $this->assertDatabaseHas('bitacoras', [
            'registro_afectado_id' => $projectId,
            'tabla_afectada' => 'proyectos',
            'accion' => 'eliminacion_permanente_sin_repositorios',
        ]);
    }

    public function test_permanent_delete_releases_last_repository_atomically(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createProject('Proyecto ultimo repo');
        $repositoryId = $this->createRepository($projectId);
        DB::table('proyectos')->where('id_proyecto', $projectId)->update(['deleted_at' => now()]);

        $result = $this->service()->permanentlyDeleteDeletedProject(
            $owner->id_usuario,
            $projectId,
            'Proyecto ultimo repo',
            'eliminacion_permanente_ultimo_repositorio',
            $repositoryId
        );

        $this->assertSame('permanently_deleted', $result['status']);
        $this->assertNull(DB::table('proyectos')->where('id_proyecto', $projectId)->first());
        $this->assertNull(
            DB::table('proyecto_repositorios')
                ->where('id_proyecto_repositorio', $repositoryId)
                ->value('id_proyecto')
        );
        $this->assertDatabaseHas('bitacoras', [
            'registro_afectado_id' => $projectId,
            'accion' => 'eliminacion_permanente_ultimo_repositorio',
        ]);
    }

    private function service(): ProyectoCicloVidaService
    {
        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldReceive('notificarProyectoEliminado')->andReturn([]);

        return new ProyectoCicloVidaService(
            $notifications,
            Mockery::mock(ProfileImageVariantService::class),
        );
    }

    private function createProject(string $title): int
    {
        return (int) DB::table('proyectos')->insertGetId([
            'titulo' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');
    }

    private function createRepository(int $projectId): int
    {
        return (int) DB::table('proyecto_repositorios')->insertGetId([
            'id_proyecto' => $projectId,
            'nombre' => 'repo-test',
            'proveedor' => 'github',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto_repositorio');
    }

    private function createParticipation(int $userId, int $projectId, array $overrides = []): int
    {
        return (int) DB::table('participaciones')->insertGetId([
            'id_usuario' => $userId,
            'id_proyecto' => $projectId,
            'es_propietario' => ($overrides['es_propietario'] ?? false) ? 'true' : 'false',
            'participacion_validada' => ($overrides['participacion_validada'] ?? false) ? 'true' : 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_participacion');
    }
}
