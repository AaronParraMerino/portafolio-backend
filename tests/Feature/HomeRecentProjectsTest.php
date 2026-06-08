<?php

namespace Tests\Feature;

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
            $table->timestamp('publicado_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('participaciones', function (Blueprint $table) {
            $table->increments('id_participacion');
            $table->unsignedInteger('id_usuario');
            $table->unsignedInteger('id_proyecto');
            $table->string('visibilidad');
            $table->softDeletes();
        });

        Schema::create('tecnologias', function (Blueprint $table) {
            $table->increments('id_tecnologia');
            $table->string('nombre');
            $table->string('tipo');
            $table->text('icono_url')->nullable();
            $table->string('color')->nullable();
            $table->softDeletes();
        });

        Schema::create('uso_tecnologias', function (Blueprint $table) {
            $table->increments('id_uso_tecnologia');
            $table->unsignedInteger('id_proyecto');
            $table->unsignedInteger('id_tecnologia');
            $table->boolean('es_principal')->default(false);
            $table->boolean('es_visible')->default(true);
            $table->softDeletes();
        });

        Schema::create('proyecto_evidencias', function (Blueprint $table) {
            $table->increments('id_evidencia');
            $table->unsignedInteger('id_proyecto');
            $table->string('titulo');
            $table->string('tipo');
            $table->text('url')->nullable();
            $table->boolean('es_portada')->default(false);
            $table->boolean('es_visible')->default(true);
            $table->integer('orden')->default(0);
            $table->softDeletes();
        });
    }
}
