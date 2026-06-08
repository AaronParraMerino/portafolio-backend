<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HomeRecentProjectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);
        DB::purge();
        DB::reconnect();

        $this->createTables();
    }

    public function test_recent_projects_returns_published_public_projects_without_duplicates(): void
    {
        $this->createProject(1, 'Proyecto reciente', '2026-06-08 10:00:00');
        $this->createProject(2, 'Proyecto anterior', '2026-06-07 10:00:00');
        $this->createProject(3, 'Proyecto borrador', '2026-06-09 10:00:00', 'borrador');
        $this->createProject(4, 'Proyecto privado', '2026-06-09 10:00:00');

        DB::table('participaciones')->insert([
            ['id_usuario' => 1, 'id_proyecto' => 1, 'visibilidad' => 'publico'],
            ['id_usuario' => 2, 'id_proyecto' => 1, 'visibilidad' => 'publico'],
            ['id_usuario' => 1, 'id_proyecto' => 2, 'visibilidad' => 'publico'],
            ['id_usuario' => 1, 'id_proyecto' => 3, 'visibilidad' => 'publico'],
            ['id_usuario' => 1, 'id_proyecto' => 4, 'visibilidad' => 'privado'],
        ]);

        DB::table('tecnologias')->insert([
            'id_tecnologia' => 10,
            'nombre' => 'Laravel',
            'tipo' => 'framework',
            'color' => '#ff0000',
        ]);
        DB::table('uso_tecnologias')->insert([
            'id_proyecto' => 1,
            'id_tecnologia' => 10,
            'es_principal' => true,
            'es_visible' => true,
        ]);
        DB::table('proyecto_evidencias')->insert([
            'id_evidencia' => 20,
            'id_proyecto' => 1,
            'titulo' => 'Portada',
            'tipo' => 'imagen',
            'url' => 'http://localhost/storage/projects/1/images/portada.jpg',
            'es_portada' => true,
            'es_visible' => true,
            'orden' => 0,
        ]);

        $response = $this->getJson('/api/home/proyectos-recientes');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.recientes')
            ->assertJsonCount(2, 'data.hero')
            ->assertJsonPath('data.recientes.0.id_proyecto', 1)
            ->assertJsonPath('data.recientes.0.tecnologias.0', 'Laravel')
            ->assertJsonPath('data.recientes.0.imagen_portada.titulo', 'Portada')
            ->assertJsonPath('data.recientes.1.id_proyecto', 2)
            ->assertJsonMissing(['titulo' => 'Proyecto borrador'])
            ->assertJsonMissing(['titulo' => 'Proyecto privado']);
    }

    public function test_recent_projects_limits_hero_to_six_projects(): void
    {
        foreach (range(1, 8) as $id) {
            $this->createProject($id, "Proyecto {$id}", "2026-06-0{$id} 10:00:00");
            DB::table('participaciones')->insert([
                'id_usuario' => $id,
                'id_proyecto' => $id,
                'visibilidad' => 'publico',
            ]);
        }

        $this->getJson('/api/home/proyectos-recientes?limit=8')
            ->assertOk()
            ->assertJsonCount(6, 'data.hero')
            ->assertJsonCount(8, 'data.recientes')
            ->assertJsonPath('data.recientes.0.id_proyecto', 8);
    }

    public function test_public_project_detail_returns_complete_safe_information(): void
    {
        $this->createProject(1, 'Proyecto publico', '2026-06-08 10:00:00');
        DB::table('usuarios')->insert([
            'id_usuario' => 10,
            'nombre' => 'Ada',
            'apellido' => 'Lovelace',
            'correo' => 'ada@example.com',
            'estado' => 'activo',
        ]);
        DB::table('perfiles')->insert(['usuario_id' => 10, 'foto_perfil' => null]);
        $participationId = DB::table('participaciones')->insertGetId([
            'id_usuario' => 10,
            'id_proyecto' => 1,
            'rol' => 'Backend',
            'descripcion_aporte' => 'Construyo la API',
            'es_propietario' => true,
            'participacion_validada' => true,
            'estado_participacion' => 'activo',
            'visibilidad' => 'publico',
        ]);
        DB::table('tecnologias')->insert([
            'id_tecnologia' => 10,
            'nombre' => 'Laravel',
            'tipo' => 'framework',
            'color' => '#ff0000',
        ]);
        DB::table('uso_tecnologias')->insert([
            'id_proyecto' => 1,
            'id_tecnologia' => 10,
            'version_usada' => '11',
            'porcentaje_uso' => 70,
            'es_principal' => true,
            'es_visible' => true,
        ]);
        DB::table('proyecto_evidencias')->insert([
            'id_proyecto' => 1,
            'titulo' => 'Video demo',
            'tipo' => 'video',
            'url' => 'https://youtube.com/watch?v=demo',
            'es_visible' => true,
            'orden' => 0,
        ]);
        DB::table('proyecto_repositorios')->insert([
            'id_proyecto_repositorio' => 20,
            'id_proyecto' => 1,
            'nombre' => 'api',
            'tipo' => 'backend',
            'proveedor' => 'github',
            'url_repositorio' => 'https://github.com/test/api',
        ]);
        DB::table('participacion_repositorios')->insert([
            'id_participacion' => $participationId,
            'id_proyecto_repositorio' => 20,
            'validado' => true,
            'es_propietario' => true,
        ]);
        DB::table('repositorio_github')->insert([
            'id_proyecto_repositorio' => 20,
            'github_owner' => 'test',
            'github_repo_name' => 'api',
            'stars_count' => 5,
            'commits_count' => 30,
        ]);

        $this->actingAs(Usuario::query()->findOrFail(10), 'sanctum')
            ->getJson('/api/projects/public/1')
            ->assertOk()
            ->assertJsonPath('data.titulo', 'Proyecto publico')
            ->assertJsonPath('data.tecnologias.0.version_usada', '11')
            ->assertJsonPath('data.repositorios.0.stars_count', 5)
            ->assertJsonPath('data.participantes.0.nombre', 'Ada Lovelace')
            ->assertJsonPath('data.participantes.0.vinculado_repositorio', true)
            ->assertJsonPath('data.participantes.0.es_propietario_repositorio', true)
            ->assertJsonPath('data.participantes.0.ruta_portafolio', '/portafolio/10')
            ->assertJsonPath('data.evidencias.0.tipo', 'video')
            ->assertJsonMissing(['correo' => 'ada@example.com'])
            ->assertJsonMissing(['email' => 'ada@example.com']);
    }

    public function test_public_project_detail_hides_non_public_projects(): void
    {
        $this->createProject(1, 'Proyecto privado', '2026-06-08 10:00:00');
        DB::table('usuarios')->insert([
            'id_usuario' => 10,
            'nombre' => 'Ada',
            'apellido' => 'Lovelace',
            'correo' => 'ada@example.com',
            'estado' => 'activo',
        ]);
        DB::table('participaciones')->insert([
            'id_usuario' => 10,
            'id_proyecto' => 1,
            'visibilidad' => 'privado',
        ]);

        $this->actingAs(Usuario::query()->findOrFail(10), 'sanctum')
            ->getJson('/api/projects/public/1')
            ->assertNotFound();
    }

    public function test_public_project_detail_requires_authentication(): void
    {
        $this->createProject(1, 'Proyecto publico', '2026-06-08 10:00:00');

        $this->getJson('/api/projects/public/1')->assertUnauthorized();
    }

    private function createProject(
        int $id,
        string $title,
        string $publishedAt,
        string $publicationStatus = 'publicado'
    ): void {
        DB::table('proyectos')->insert([
            'id_proyecto' => $id,
            'titulo' => $title,
            'descripcion' => "Descripcion de {$title}",
            'categoria_proyecto' => 'educativo',
            'plataforma_objetivo' => 'web',
            'estado_publicacion' => $publicationStatus,
            'estado_desarrollo' => 'en_desarrollo',
            'es_destacado' => false,
            'publicado_at' => $publishedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    }

    private function createTables(): void
    {
        Schema::create('proyectos', function (Blueprint $table) {
            $table->increments('id_proyecto');
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('categoria_proyecto');
            $table->string('plataforma_objetivo');
            $table->string('estado_publicacion');
            $table->string('estado_desarrollo');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('es_destacado')->default(false);
            $table->string('origen')->default('manual');
            $table->integer('orden')->default(0);
            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('participaciones', function (Blueprint $table) {
            $table->increments('id_participacion');
            $table->unsignedInteger('id_usuario');
            $table->unsignedInteger('id_proyecto');
            $table->string('rol')->nullable();
            $table->text('descripcion_aporte')->nullable();
            $table->boolean('es_propietario')->default(false);
            $table->boolean('participacion_validada')->default(false);
            $table->string('estado_participacion')->default('activo');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->string('visibilidad');
            $table->softDeletes();
        });

        Schema::create('participacion_repositorios', function (Blueprint $table) {
            $table->increments('id_participacion_repositorio');
            $table->unsignedInteger('id_participacion');
            $table->unsignedInteger('id_proyecto_repositorio');
            $table->boolean('validado')->default(false);
            $table->boolean('es_propietario')->default(false);
            $table->timestamps();
        });

        Schema::create('tecnologias', function (Blueprint $table) {
            $table->increments('id_tecnologia');
            $table->string('nombre');
            $table->string('tipo');
            $table->text('icono_url')->nullable();
            $table->string('color')->nullable();
            $table->text('descripcion')->nullable();
            $table->softDeletes();
        });

        Schema::create('uso_tecnologias', function (Blueprint $table) {
            $table->increments('id_uso_tecnologia');
            $table->unsignedInteger('id_proyecto');
            $table->unsignedInteger('id_tecnologia');
            $table->boolean('es_principal')->default(false);
            $table->boolean('es_visible')->default(true);
            $table->string('version_usada')->nullable();
            $table->decimal('porcentaje_uso', 5, 2)->nullable();
            $table->softDeletes();
        });

        Schema::create('proyecto_evidencias', function (Blueprint $table) {
            $table->increments('id_evidencia');
            $table->unsignedInteger('id_proyecto');
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('tipo');
            $table->text('url')->nullable();
            $table->text('archivo_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('tamanio_bytes')->nullable();
            $table->boolean('es_portada')->default(false);
            $table->boolean('es_visible')->default(true);
            $table->integer('orden')->default(0);
            $table->softDeletes();
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->increments('id_usuario');
            $table->string('nombre');
            $table->string('apellido');
            $table->string('correo');
            $table->string('estado');
        });

        Schema::create('perfiles', function (Blueprint $table) {
            $table->increments('id_perfil');
            $table->unsignedInteger('usuario_id');
            $table->text('foto_perfil')->nullable();
        });

        Schema::create('cuentas_oauth', function (Blueprint $table) {
            $table->increments('id_cuenta_oauth');
            $table->unsignedInteger('usuario_id');
            $table->string('provider');
            $table->string('nombre')->nullable();
            $table->text('foto_url')->nullable();
        });

        Schema::create('proyecto_repositorios', function (Blueprint $table) {
            $table->increments('id_proyecto_repositorio');
            $table->unsignedInteger('id_proyecto');
            $table->string('nombre')->nullable();
            $table->string('tipo')->nullable();
            $table->string('proveedor')->nullable();
            $table->text('url_repositorio')->nullable();
            $table->text('descripcion')->nullable();
            $table->softDeletes();
        });

        Schema::create('repositorio_github', function (Blueprint $table) {
            $table->increments('id_repositorio_github');
            $table->unsignedInteger('id_proyecto_repositorio');
            $table->string('github_owner')->nullable();
            $table->string('github_repo_name')->nullable();
            $table->text('github_description')->nullable();
            $table->text('github_homepage')->nullable();
            $table->string('default_branch')->nullable();
            $table->boolean('is_fork')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->integer('stars_count')->default(0);
            $table->integer('forks_count')->default(0);
            $table->integer('open_issues_count')->default(0);
            $table->integer('commits_count')->default(0);
            $table->integer('contributors_count')->default(0);
            $table->text('last_commit_message')->nullable();
            $table->timestamp('last_commit_date')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('repo_created_at')->nullable();
            $table->timestamp('repo_updated_at')->nullable();
            $table->text('readme_resumen')->nullable();
            $table->timestamp('last_sync_at')->nullable();
        });
    }
}
