<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_check");
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_check CHECK (rol IN ('admin', 'usuario', 'otro', 'publicante'))");

        Schema::table('admin_eventos', function (Blueprint $table) {
            $table->string('imagen_portada_path', 255)->nullable()->after('ubicacion');
            $table->string('imagen_portada_url', 500)->nullable()->after('imagen_portada_path');
        });

        DB::statement("ALTER TABLE admin_eventos DROP CONSTRAINT IF EXISTS admin_eventos_estado_check");
        DB::statement("ALTER TABLE admin_eventos ADD CONSTRAINT admin_eventos_estado_check CHECK (estado IN ('activo', 'programado', 'borrador', 'cancelado', 'pausado', 'suspendido', 'eliminado'))");

        Schema::create('publicante_solicitudes', function (Blueprint $table) {
            $table->id('id_solicitud');
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('admin_revisor_id')->nullable();
            $table->string('documento', 50);
            $table->string('telefono_actual', 30);
            $table->string('telefono_referencia', 30);
            $table->string('correo_respaldo', 150);
            $table->string('organizacion', 150);
            $table->string('cargo', 100);
            $table->text('motivo');
            $table->text('experiencia');
            $table->text('enlaces');
            $table->enum('estado', ['pendiente', 'aprobada', 'rechazada'])->default('pendiente');
            $table->text('motivo_revision')->nullable();
            $table->timestamp('revisada_en')->nullable();
            $table->timestamps();

            $table->foreign('usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();
            $table->foreign('admin_revisor_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['estado', 'created_at']);
            $table->index('usuario_id');
        });

        Schema::create('admin_evento_acciones', function (Blueprint $table) {
            $table->id('id_accion');
            $table->unsignedBigInteger('evento_id');
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('usuario_publicante_id')->nullable();
            $table->enum('accion', ['activar', 'pausar', 'suspender', 'eliminar']);
            $table->string('estado_anterior', 50)->nullable();
            $table->string('estado_nuevo', 50)->nullable();
            $table->text('motivo');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('evento_id')
                ->references('id_evento')
                ->on('admin_eventos')
                ->cascadeOnDelete();
            $table->foreign('admin_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();
            $table->foreign('usuario_publicante_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['evento_id', 'created_at']);
            $table->index(['accion', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_evento_acciones');
        Schema::dropIfExists('publicante_solicitudes');

        DB::statement("UPDATE admin_eventos SET estado = 'cancelado' WHERE estado IN ('pausado', 'suspendido', 'eliminado')");
        DB::statement("ALTER TABLE admin_eventos DROP CONSTRAINT IF EXISTS admin_eventos_estado_check");
        DB::statement("ALTER TABLE admin_eventos ADD CONSTRAINT admin_eventos_estado_check CHECK (estado IN ('activo', 'programado', 'borrador', 'cancelado'))");

        Schema::table('admin_eventos', function (Blueprint $table) {
            $table->dropColumn(['imagen_portada_path', 'imagen_portada_url']);
        });

        DB::statement("UPDATE usuarios SET rol = 'usuario' WHERE rol = 'publicante'");
        DB::statement("ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_check");
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_check CHECK (rol IN ('admin', 'usuario', 'otro'))");
    }
};
