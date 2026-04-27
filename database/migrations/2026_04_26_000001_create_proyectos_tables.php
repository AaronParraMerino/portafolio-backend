<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_proyecto', function (Blueprint $table) {
            $table->id('id_tipo_proyecto');

            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->text('icono_url')->nullable();
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);

            $table->timestamps();
        });

        Schema::create('proyectos', function (Blueprint $table) {
            $table->id('id_proyecto');

            $table->string('titulo', 200);
            $table->text('descripcion')->nullable();

            $table->unsignedBigInteger('id_tipo_proyecto')->nullable();

            $table->enum('estado_publicacion', [
                'borrador',
                'publicado',
                'archivado',
            ])->default('borrador');

            $table->enum('estado_desarrollo', [
                'sin_especificar',
                'en_desarrollo',
                'pausado',
                'terminado',
                'mantenimiento',
                'versionado',
                'cancelado',
            ])->default('sin_especificar');

            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->enum('origen', [
                'manual',
                'github',
                'manual_github_editado',
            ])->default('manual');

            $table->boolean('es_destacado')->default(false);
            $table->timestamp('publicado_at')->nullable();
            $table->integer('orden')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('id_tipo_proyecto')
                ->references('id_tipo_proyecto')
                ->on('tipos_proyecto')
                ->nullOnDelete();
        });

        Schema::create('participaciones', function (Blueprint $table) {
            $table->id('id_participacion');

            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_proyecto');

            $table->string('rol', 100)->nullable();
            $table->text('descripcion_aporte')->nullable();

            $table->boolean('es_propietario')->default(false);

            $table->enum('visibilidad', [
                'publico',
                'privado',
            ])->default('publico');

            $table->enum('estado_participacion', [
                'activo',
                'finalizado',
                'retirado',
                'pendiente',
            ])->default('activo');

            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id_usuario', 'id_proyecto']);

            $table->foreign('id_usuario')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();

            $table->foreign('id_proyecto')
                ->references('id_proyecto')
                ->on('proyectos')
                ->cascadeOnDelete();
        });

        Schema::create('proyecto_github', function (Blueprint $table) {
            $table->id('id_proyecto_github');

            $table->unsignedBigInteger('id_proyecto')->unique();

            $table->bigInteger('github_repo_id')->nullable();
            $table->string('github_owner', 100)->nullable();
            $table->string('github_repo_name', 150)->nullable();
            $table->text('github_url')->nullable();

            $table->text('github_description')->nullable();
            $table->text('github_homepage')->nullable();

            $table->string('default_branch', 100)->nullable();

            $table->boolean('is_private')->default(false);
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

            $table->timestamp('github_created_at')->nullable();
            $table->timestamp('github_updated_at')->nullable();

            $table->text('readme_resumen')->nullable();

            $table->enum('sync_status', [
                'pendiente',
                'sincronizado',
                'error',
            ])->default('pendiente');
            $table->text('sync_error')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->timestamps();

            $table->foreign('id_proyecto')
                ->references('id_proyecto')
                ->on('proyectos')
                ->cascadeOnDelete();
        });

        Schema::create('proyecto_evidencias', function (Blueprint $table) {
            $table->id('id_evidencia');

            $table->unsignedBigInteger('id_proyecto');

            $table->string('titulo', 150);
            $table->text('descripcion')->nullable();

            $table->enum('tipo', [
                'imagen',
                'captura',
                'video',
                'pdf',
                'documento',
                'link',
                'repositorio',
                'demo',
                'documentacion',
                'figma',
                'presentacion',
                'api',
                'otro',
            ])->default('otro');

            $table->text('url')->nullable();
            $table->text('archivo_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('tamanio_bytes')->nullable();

            $table->boolean('es_portada')->default(false);
            $table->boolean('es_visible')->default(true);
            $table->integer('orden')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('id_proyecto')
                ->references('id_proyecto')
                ->on('proyectos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_evidencias');
        Schema::dropIfExists('proyecto_github');
        Schema::dropIfExists('participaciones');
        Schema::dropIfExists('proyectos');
        Schema::dropIfExists('tipos_proyecto');
    }
};
