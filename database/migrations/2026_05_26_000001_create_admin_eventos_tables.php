<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_eventos', function (Blueprint $table) {
            $table->id('id_evento');
            $table->unsignedBigInteger('usuario_creador_id')->nullable();
            $table->unsignedBigInteger('usuario_actualizador_id')->nullable();
            $table->string('titulo', 150);
            $table->text('descripcion')->nullable();
            $table->enum('tipo', [
                'taller',
                'charla',
                'webinar',
                'feria',
                'capacitacion',
                'networking',
                'curso',
                'trabajo',
                'convocatoria',
            ])->default('taller');
            $table->enum('estado', ['activo', 'programado', 'borrador', 'cancelado'])->default('borrador');
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_fin')->nullable();
            $table->timestamp('programado_para')->nullable();
            $table->string('ubicacion', 255)->nullable();
            $table->unsignedInteger('cupo')->default(0);
            $table->unsignedInteger('inscritos')->default(0);
            $table->unsignedInteger('interesados')->default(0);
            $table->unsignedInteger('espera')->default(0);
            $table->unsignedInteger('asistieron')->default(0);
            $table->unsignedInteger('no_asistieron')->default(0);
            $table->enum('target_mode', ['all_users', 'segmented'])->default('all_users');
            $table->json('channels')->nullable();
            $table->json('segments')->nullable();
            $table->json('target_selections')->nullable();
            $table->timestamps();

            $table->foreign('usuario_creador_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();
            $table->foreign('usuario_actualizador_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['estado', 'tipo']);
            $table->index('fecha_inicio');
        });

        Schema::create('admin_evento_comunicaciones', function (Blueprint $table) {
            $table->id('id_comunicacion');
            $table->unsignedBigInteger('evento_id')->nullable();
            $table->unsignedBigInteger('usuario_creador_id')->nullable();
            $table->unsignedBigInteger('usuario_actualizador_id')->nullable();
            $table->string('titulo', 150);
            $table->text('cuerpo')->nullable();
            $table->enum('tipo', [
                'plataforma',
                'oportunidad',
                'seguridad',
                'mantenimiento',
                'comunidad',
                'urgente',
            ])->default('plataforma');
            $table->enum('estado', ['borrador', 'programado', 'enviado', 'archivado'])->default('borrador');
            $table->enum('urgencia', ['baja', 'media', 'alta'])->default('baja');
            $table->unsignedInteger('destinatarios')->default(0);
            $table->timestamp('programado_para')->nullable();
            $table->timestamp('enviado_en')->nullable();
            $table->json('audiences')->nullable();
            $table->json('channels')->nullable();
            $table->json('segments')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            $table->foreign('evento_id')
                ->references('id_evento')
                ->on('admin_eventos')
                ->nullOnDelete();
            $table->foreign('usuario_creador_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();
            $table->foreign('usuario_actualizador_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['estado', 'tipo']);
            $table->index('programado_para');
        });

        Schema::create('admin_evento_plantillas', function (Blueprint $table) {
            $table->id('id_plantilla');
            $table->unsignedBigInteger('usuario_creador_id')->nullable();
            $table->unsignedBigInteger('usuario_actualizador_id')->nullable();
            $table->string('titulo', 150);
            $table->text('cuerpo')->nullable();
            $table->enum('tipo', [
                'plataforma',
                'oportunidad',
                'seguridad',
                'mantenimiento',
                'comunidad',
                'urgente',
            ])->default('plataforma');
            $table->json('channels')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('usadas')->default(0);
            $table->timestamps();

            $table->foreign('usuario_creador_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();
            $table->foreign('usuario_actualizador_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index('tipo');
        });

        Schema::create('admin_evento_historial', function (Blueprint $table) {
            $table->id('id_historial');
            $table->unsignedBigInteger('usuario_actor_id')->nullable();
            $table->string('accion', 50);
            $table->string('entidad_tipo', 50);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->string('titulo', 150);
            $table->text('descripcion')->nullable();
            $table->string('tipo', 50)->default('plataforma');
            $table->string('estado', 50)->default('enviado');
            $table->string('destino', 150)->nullable();
            $table->json('channels')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('usuario_actor_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['entidad_tipo', 'entidad_id']);
            $table->index(['tipo', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_evento_historial');
        Schema::dropIfExists('admin_evento_plantillas');
        Schema::dropIfExists('admin_evento_comunicaciones');
        Schema::dropIfExists('admin_eventos');
    }
};
