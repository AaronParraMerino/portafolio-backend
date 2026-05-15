<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proyecto_configuraciones', function (Blueprint $table) {
            $table->id('id_configuracion');
            $table->unsignedBigInteger('id_proyecto')->unique();

            $table->enum('modo_union', [
                'cerrado',
                'por_solicitud',
                'enlace_autenticado',
                'github_validado',
            ])->default('github_validado');
            $table->boolean('requiere_aprobacion_union')->default(true);
            $table->boolean('permitir_participantes_sin_validacion')->default(false);

            $table->enum('puede_editar_proyecto', [
                'propietarios',
                'autoridad_github',
                'participantes_validados',
                'participantes',
            ])->default('participantes_validados');
            $table->enum('puede_administrar_proyecto', [
                'propietarios',
                'autoridad_github',
            ])->default('propietarios');

            $table->enum('github_nivel_autoridad', [
                'owner',
                'maintainer',
                'admin_push',
            ])->default('maintainer');
            $table->boolean('github_prevalece_sobre_creador')->default(true);

            $table->boolean('enlace_union_activo')->default(false);
            $table->string('enlace_union_token', 100)->nullable()->unique();
            $table->timestamp('enlace_union_expira_at')->nullable();

            $table->enum('visibilidad_github_validado_externo', [
                'oculto',
                'visible',
            ])->default('visible');
            $table->enum('visibilidad_github_validado_usuario', [
                'visible',
                'oculto',
            ])->default('visible');
            $table->enum('visibilidad_usuario_sin_validacion', [
                'oculto',
                'visible',
            ])->default('visible');

            $table->boolean('permitir_remover_participantes_sin_validacion')->default(false);

            $table->timestamps();

            $table->foreign('id_proyecto')
                ->references('id_proyecto')
                ->on('proyectos')
                ->cascadeOnDelete();
        });

        $now = now();

        DB::table('proyectos')
            ->select('id_proyecto')
            ->orderBy('id_proyecto')
            ->chunk(100, function ($proyectos) use ($now) {
                $rows = $proyectos->map(fn ($proyecto) => [
                    'id_proyecto' => $proyecto->id_proyecto,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if (! empty($rows)) {
                    DB::table('proyecto_configuraciones')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyecto_configuraciones');
    }
};
