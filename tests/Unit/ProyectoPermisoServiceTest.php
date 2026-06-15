<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\Proyecto\ProyectoPermisoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProyectoPermisoServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_autoridad_no_edita_ni_administra_cuando_politica_es_propietarios(): void
    {
        [$userId, $projectId, $remoteRepoId] = $this->createParticipantWithRepositoryAuthority('github');
        $this->createConfiguration($projectId, 'propietarios', 'propietarios');
        $this->createAuthorityValidation($userId, $remoteRepoId);

        $permissions = (new ProyectoPermisoService())->resolve($userId, $projectId);

        $this->assertTrue($permissions['es_autoridad_github']);
        $this->assertFalse($permissions['puede_editar']);
        $this->assertFalse($permissions['puede_administrar']);
        $this->assertFalse($permissions['puede_configurar']);
    }

    public function test_autoridad_edita_y_administra_solo_cuando_politica_la_selecciona(): void
    {
        [$userId, $projectId, $remoteRepoId] = $this->createParticipantWithRepositoryAuthority('github');
        $this->createConfiguration($projectId, 'autoridad_github', 'autoridad_github');
        $this->createAuthorityValidation($userId, $remoteRepoId);

        $permissions = (new ProyectoPermisoService())->resolve($userId, $projectId);

        $this->assertTrue($permissions['puede_editar']);
        $this->assertTrue($permissions['puede_administrar']);
        $this->assertTrue($permissions['puede_configurar']);
    }

    public function test_participante_validado_edita_pero_no_administra_con_politica_correspondiente(): void
    {
        [$userId, $projectId] = $this->createParticipantWithRepositoryAuthority('github', true);
        $this->createConfiguration($projectId, 'participantes_validados', 'propietarios');

        $permissions = (new ProyectoPermisoService())->resolve($userId, $projectId);

        $this->assertTrue($permissions['puede_editar']);
        $this->assertFalse($permissions['puede_administrar']);
    }

    public function test_autoridad_gitlab_es_reconocida_cuando_politica_la_selecciona(): void
    {
        [$userId, $projectId, $remoteRepoId] = $this->createParticipantWithRepositoryAuthority('gitlab');
        $this->createConfiguration($projectId, 'autoridad_github', 'autoridad_github');
        $this->createAuthorityValidation($userId, $remoteRepoId);

        $permissions = (new ProyectoPermisoService())->resolve($userId, $projectId);

        $this->assertTrue($permissions['es_autoridad_github']);
        $this->assertTrue($permissions['puede_editar']);
        $this->assertTrue($permissions['puede_administrar']);
    }

    private function createParticipantWithRepositoryAuthority(string $provider, bool $validated = false): array
    {
        $user = Usuario::factory()->create();
        $projectId = (int) DB::table('proyectos')->insertGetId([
            'titulo' => 'Proyecto permisos',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');

        DB::table('participaciones')->insert([
            'id_usuario' => $user->id_usuario,
            'id_proyecto' => $projectId,
            'es_propietario' => 'false',
            'participacion_validada' => $validated ? 'true' : 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $projectRepoId = (int) DB::table('proyecto_repositorios')->insertGetId([
            'id_proyecto' => $projectId,
            'proveedor' => $provider,
            'url_repositorio' => 'https://'.$provider.'.com/example/project',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto_repositorio');

        $remoteRepoId = (int) DB::table('repositorio_github')->insertGetId([
            'id_proyecto_repositorio' => $projectRepoId,
            'github_repo_id' => random_int(100000, 999999),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_repositorio_github');

        return [(int) $user->id_usuario, $projectId, $remoteRepoId];
    }

    private function createConfiguration(int $projectId, string $editPolicy, string $adminPolicy): void
    {
        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => $projectId,
            'puede_editar_proyecto' => $editPolicy,
            'puede_administrar_proyecto' => $adminPolicy,
            'github_nivel_autoridad' => 'maintainer',
            'github_prevalece_sobre_creador' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAuthorityValidation(int $userId, int $remoteRepoId): void
    {
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $userId,
            'id_repositorio_github' => $remoteRepoId,
            'relacion_github' => 'maintainer',
            'validado' => 'true',
            'validado_at' => now(),
            'ultima_verificacion_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
