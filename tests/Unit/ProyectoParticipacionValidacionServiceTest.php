<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\Proyecto\ProyectoParticipacionValidacionService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProyectoParticipacionValidacionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_recalculo_normal_quita_validacion_pero_no_desvincula(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);

        $result = $this->service()->reconciliarProyecto($projectId);

        $participation = DB::table('participaciones')->where('id_participacion', $participationId)->first();

        $this->assertSame([], $result['usuarios_desvinculados']);
        $this->assertFalse($this->databaseBool($participation->participacion_validada));
        $this->assertNull($participation->deleted_at);
    }

    public function test_perdida_confirmada_desvincula_si_pierde_ultima_validacion_y_puerta_esta_cerrada(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);

        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion')
            ->once()
            ->with($projectId, $user->id_usuario)
            ->andReturn([]);

        $result = (new ProyectoParticipacionValidacionService($notifications))->reconciliarProyecto(
            $projectId,
            ProyectoParticipacionValidacionService::MOTIVO_PERDIDA_ACCESO_PROVEEDOR,
            [$participationId]
        );

        $participation = DB::table('participaciones')->where('id_participacion', $participationId)->first();

        $this->assertSame([$user->id_usuario], $result['usuarios_desvinculados']);
        $this->assertSame('retirado', $participation->estado_participacion);
        $this->assertNotNull($participation->deleted_at);
    }

    public function test_perdida_confirmada_no_desvincula_si_conserva_otro_repositorio_validado(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $repo = $this->createRepository($projectId, 'github');
        $this->createValidation($user->id_usuario, $repo['remote_id'], true);

        $result = $this->service()->reconciliarProyecto(
            $projectId,
            ProyectoParticipacionValidacionService::MOTIVO_PERDIDA_ACCESO_PROVEEDOR,
            [$participationId]
        );

        $participation = DB::table('participaciones')->where('id_participacion', $participationId)->first();

        $this->assertSame([], $result['usuarios_desvinculados']);
        $this->assertTrue($this->databaseBool($participation->participacion_validada));
        $this->assertNull($participation->deleted_at);
    }

    public function test_sincronizacion_valida_a_participante_existente_sin_validacion(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(true);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, false);
        $repo = $this->createRepository($projectId, 'gitlab');
        $this->createValidation($user->id_usuario, $repo['remote_id'], true);

        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldReceive('notificarParticipacionValidada')
            ->once()
            ->with($projectId, $user->id_usuario)
            ->andReturn([]);
        $notifications->shouldNotReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion');

        $service = new ProyectoParticipacionValidacionService($notifications);
        $first = $service->reconciliarProyecto($projectId);
        $second = $service->reconciliarProyecto($projectId);

        $this->assertDatabaseHas('participacion_repositorios', [
            'id_participacion' => $participationId,
            'id_proyecto_repositorio' => $repo['project_repo_id'],
        ]);
        $this->assertSame([$user->id_usuario], $first['usuarios_validados']);
        $this->assertSame([], $second['usuarios_validados']);
        $this->assertTrue($this->databaseBool(
            DB::table('participaciones')->where('id_participacion', $participationId)->value('participacion_validada')
        ));
    }

    public function test_propietario_no_se_desvincula_automaticamente(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($owner->id_usuario, $projectId, true, true);

        $result = $this->service()->reconciliarProyecto(
            $projectId,
            ProyectoParticipacionValidacionService::MOTIVO_PERDIDA_ACCESO_PROVEEDOR,
            [$participationId]
        );

        $this->assertSame(1, $result['propietarios_protegidos']);
        $this->assertNull(
            DB::table('participaciones')->where('id_participacion', $participationId)->value('deleted_at')
        );
    }

    public function test_perdida_confirmada_no_desvincula_si_proyecto_permite_sin_validacion(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(true);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);

        $result = $this->service()->reconciliarProyecto(
            $projectId,
            ProyectoParticipacionValidacionService::MOTIVO_PERDIDA_ACCESO_PROVEEDOR,
            [$participationId]
        );

        $this->assertSame([], $result['usuarios_desvinculados']);
        $this->assertNull(
            DB::table('participaciones')->where('id_participacion', $participationId)->value('deleted_at')
        );
    }

    public function test_perdida_confirmada_sin_participantes_afectados_no_desvincula_en_masa(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);

        $result = $this->service()->reconciliarProyecto(
            $projectId,
            ProyectoParticipacionValidacionService::MOTIVO_PERDIDA_ACCESO_PROVEEDOR
        );

        $this->assertSame([], $result['usuarios_desvinculados']);
        $this->assertNull(
            DB::table('participaciones')->where('id_participacion', $participationId)->value('deleted_at')
        );
    }

    private function service(): ProyectoParticipacionValidacionService
    {
        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldNotReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion');
        $notifications->shouldNotReceive('notificarParticipacionValidada');

        return new ProyectoParticipacionValidacionService($notifications);
    }

    private function createProject(bool $allowUnvalidated): int
    {
        $projectId = (int) DB::table('proyectos')->insertGetId([
            'titulo' => 'Proyecto validaciones',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');

        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => $projectId,
            'permitir_participantes_sin_validacion' => $allowUnvalidated ? 'true' : 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $projectId;
    }

    private function createParticipation(int $userId, int $projectId, bool $validated, bool $owner = false): int
    {
        return (int) DB::table('participaciones')->insertGetId([
            'id_usuario' => $userId,
            'id_proyecto' => $projectId,
            'es_propietario' => $owner ? 'true' : 'false',
            'participacion_validada' => $validated ? 'true' : 'false',
            'estado_participacion' => 'activo',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_participacion');
    }

    private function createRepository(int $projectId, string $provider): array
    {
        $projectRepoId = (int) DB::table('proyecto_repositorios')->insertGetId([
            'id_proyecto' => $projectId,
            'nombre' => $provider.'-repo',
            'proveedor' => $provider,
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
        ];
    }

    private function createValidation(int $userId, int $remoteId, bool $validated): void
    {
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $userId,
            'id_repositorio_github' => $remoteId,
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
