<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\Proyecto\ProyectoParticipacionValidacionService;
use App\Services\api\Proyecto\ProyectoProveedorDesvinculacionService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProyectoProveedorDesvinculacionServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_desvincular_github_no_afecta_gitlab_y_conserva_validacion_del_proyecto(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $github = $this->createRepository($projectId, 'github');
        $gitlab = $this->createRepository($projectId, 'gitlab');
        $this->createValidationAndLink($user->id_usuario, $participationId, $github, true);
        $this->createValidationAndLink($user->id_usuario, $participationId, $gitlab, true);

        $result = $this->service()->desvincularProveedor($user->id_usuario, 'github');

        $this->assertSame(1, $result['validaciones_invalidadas']);
        $this->assertFalse($this->validationIsValid($user->id_usuario, $github['remote_id']));
        $this->assertTrue($this->validationIsValid($user->id_usuario, $gitlab['remote_id']));
        $this->assertTrue($this->participationIsValid($participationId));
        $this->assertNull($this->participation($participationId)->deleted_at);
    }

    public function test_desvincular_ultimo_proveedor_saca_participante_si_proyecto_exige_validacion(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $github = $this->createRepository($projectId, 'github');
        $this->createValidationAndLink($user->id_usuario, $participationId, $github, true);

        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion')
            ->once()
            ->with($projectId, $user->id_usuario)
            ->andReturn([]);

        $service = new ProyectoProveedorDesvinculacionService(
            new ProyectoParticipacionValidacionService($notifications)
        );
        $result = $service->desvincularProveedor($user->id_usuario, 'github');

        $this->assertSame([$user->id_usuario], $result['usuarios_desvinculados']);
        $this->assertFalse($this->validationIsValid($user->id_usuario, $github['remote_id']));
        $this->assertNotNull($this->participation($participationId)->deleted_at);
    }

    public function test_desvincular_ultimo_proveedor_conserva_participante_si_proyecto_permite_sin_validacion(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(true);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $gitlab = $this->createRepository($projectId, 'gitlab');
        $this->createValidationAndLink($user->id_usuario, $participationId, $gitlab, true);

        $result = $this->service()->desvincularProveedor($user->id_usuario, 'gitlab');

        $this->assertSame([], $result['usuarios_desvinculados']);
        $this->assertFalse($this->participationIsValid($participationId));
        $this->assertNull($this->participation($participationId)->deleted_at);
    }

    public function test_desvincular_proveedor_no_soportado_no_modifica_validaciones(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $github = $this->createRepository($projectId, 'github');
        $this->createValidationAndLink($user->id_usuario, $participationId, $github, true);

        $result = $this->service()->desvincularProveedor($user->id_usuario, 'google');

        $this->assertSame('ignored', $result['status']);
        $this->assertTrue($this->validationIsValid($user->id_usuario, $github['remote_id']));
        $this->assertTrue($this->participationIsValid($participationId));
    }

    public function test_desvincular_ultimo_proveedor_no_expulsa_propietario(): void
    {
        $owner = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($owner->id_usuario, $projectId, true, true);
        $github = $this->createRepository($projectId, 'github');
        $this->createValidationAndLink($owner->id_usuario, $participationId, $github, true);

        $result = $this->service()->desvincularProveedor($owner->id_usuario, 'github');

        $this->assertSame([], $result['usuarios_desvinculados']);
        $this->assertFalse($this->participationIsValid($participationId));
        $this->assertNull($this->participation($participationId)->deleted_at);
    }

    public function test_lista_remota_confirmada_invalida_solo_repositorios_ausentes(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(false);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, true);
        $presente = $this->createRepository($projectId, 'github');
        $ausente = $this->createRepository($projectId, 'github');
        $this->createValidationAndLink($user->id_usuario, $participationId, $presente, true);
        $this->createValidationAndLink($user->id_usuario, $participationId, $ausente, true);

        $result = $this->service()->invalidarRepositoriosAusentesConfirmados(
            $user->id_usuario,
            'github',
            [$presente['provider_id']]
        );

        $this->assertSame(1, $result['validaciones_invalidadas']);
        $this->assertTrue($this->validationIsValid($user->id_usuario, $presente['remote_id']));
        $this->assertFalse($this->validationIsValid($user->id_usuario, $ausente['remote_id']));
        $this->assertTrue($this->participationIsValid($participationId));
    }

    public function test_repositorio_presente_confirmado_valida_participante_existente(): void
    {
        $user = Usuario::factory()->create();
        $projectId = $this->createProject(true);
        $participationId = $this->createParticipation($user->id_usuario, $projectId, false);
        $github = $this->createRepository($projectId, 'github');
        $this->createValidationAndLink($user->id_usuario, $participationId, $github, true);
        DB::table('participacion_repositorios')
            ->where('id_participacion', $participationId)
            ->update(['validado' => 'false', 'validado_at' => null]);

        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldReceive('notificarParticipacionValidada')
            ->once()
            ->with($projectId, $user->id_usuario)
            ->andReturn([]);
        $notifications->shouldNotReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion');

        $service = new ProyectoProveedorDesvinculacionService(
            new ProyectoParticipacionValidacionService($notifications)
        );
        $result = $service->reconciliarRepositoriosPresentesConfirmados(
            $user->id_usuario,
            'github',
            [$github['provider_id']]
        );

        $this->assertSame([$user->id_usuario], $result['usuarios_validados']);
        $this->assertSame(1, $result['participaciones_validadas']);
        $this->assertTrue($this->participationIsValid($participationId));
    }

    private function service(): ProyectoProveedorDesvinculacionService
    {
        $notifications = Mockery::mock(ProyectoNotificacionGuardadoService::class);
        $notifications->shouldNotReceive('notificarDesvinculacionAutomaticaPorPerdidaValidacion');
        $notifications->shouldNotReceive('notificarParticipacionValidada');

        return new ProyectoProveedorDesvinculacionService(
            new ProyectoParticipacionValidacionService($notifications)
        );
    }

    private function createProject(bool $allowUnvalidated): int
    {
        $projectId = (int) DB::table('proyectos')->insertGetId([
            'titulo' => 'Proyecto proveedor',
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
            'url_repositorio' => "https://{$provider}.com/test/repo",
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto_repositorio');

        $providerId = random_int(100000, 999999);
        $remoteId = (int) DB::table('repositorio_github')->insertGetId([
            'id_proyecto_repositorio' => $projectRepoId,
            'github_repo_id' => $providerId,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_repositorio_github');

        return [
            'project_repo_id' => $projectRepoId,
            'remote_id' => $remoteId,
            'provider_id' => $providerId,
        ];
    }

    private function createValidationAndLink(int $userId, int $participationId, array $repo, bool $validated): void
    {
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $userId,
            'id_repositorio_github' => $repo['remote_id'],
            'relacion_github' => 'collaborator',
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

    private function validationIsValid(int $userId, int $remoteId): bool
    {
        return $this->databaseBool(
            DB::table('usuario_repositorio_validaciones')
                ->where('id_usuario', $userId)
                ->where('id_repositorio_github', $remoteId)
                ->value('validado')
        );
    }

    private function participationIsValid(int $participationId): bool
    {
        return $this->databaseBool($this->participation($participationId)->participacion_validada);
    }

    private function participation(int $participationId): object
    {
        return DB::table('participaciones')->where('id_participacion', $participationId)->first();
    }

    private function databaseBool(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
