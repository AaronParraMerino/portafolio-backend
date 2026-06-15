<?php

namespace Tests\Unit;

use App\Models\Usuario;
use App\Services\api\ProfileImageVariantService;
use App\Services\api\Proyecto\ProyectoParticipanteService;
use App\Services\api\Proyecto\ProyectoPermisoService;
use App\Services\api\ProyectoNotificacionGuardadoService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProyectoParticipanteServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_validacion_falsa_se_lista_como_participante_sin_validacion(): void
    {
        $user = Usuario::factory()->create();
        $projectId = (int) DB::table('proyectos')->insertGetId([
            'titulo' => 'Proyecto participantes',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto');
        DB::table('proyecto_configuraciones')->insert([
            'id_proyecto' => $projectId,
            'visibilidad_usuario_sin_validacion' => 'visible',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('participaciones')->insert([
            'id_usuario' => $user->id_usuario,
            'id_proyecto' => $projectId,
            'es_propietario' => 'false',
            'participacion_validada' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectRepoId = (int) DB::table('proyecto_repositorios')->insertGetId([
            'id_proyecto' => $projectId,
            'proveedor' => 'github',
            'url_repositorio' => 'https://github.com/example/project',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_proyecto_repositorio');
        $remoteRepoId = (int) DB::table('repositorio_github')->insertGetId([
            'id_proyecto_repositorio' => $projectRepoId,
            'github_repo_id' => 123456,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id_repositorio_github');
        DB::table('usuario_repositorio_validaciones')->insert([
            'id_usuario' => $user->id_usuario,
            'id_repositorio_github' => $remoteRepoId,
            'validado' => 'false',
            'es_propietario' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new ProyectoParticipanteService(
            new ProyectoPermisoService(),
            new ProfileImageVariantService(),
            Mockery::mock(ProyectoNotificacionGuardadoService::class),
        );

        $participant = collect($service->list($projectId, $user->id_usuario))->first();

        $this->assertFalse($participant['validacion_github']);
        $this->assertSame('usuario_sin_validacion_github', $participant['tipo_participante']);
    }
}
