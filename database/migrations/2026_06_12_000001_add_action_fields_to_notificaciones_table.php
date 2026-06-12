<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->string('accion_estado', 30)->nullable()->after('mensaje');
            $table->text('accion_respuesta')->nullable()->after('accion_estado');
            $table->unsignedBigInteger('accion_respuesta_usuario_id')->nullable()->after('accion_respuesta');
            $table->timestamp('accion_respondida_at')->nullable()->after('accion_respuesta_usuario_id');
            $table->timestamp('accion_disponible_nuevamente_at')->nullable()->after('accion_respondida_at');
            $table->json('metadata')->nullable()->after('accion_disponible_nuevamente_at');

            $table->foreign('accion_respuesta_usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['tipo', 'accion_estado'], 'ix_notificaciones_tipo_accion_estado');
            $table->index('accion_disponible_nuevamente_at', 'ix_notificaciones_accion_disponible');
        });
    }

    public function down(): void
    {
        Schema::table('notificaciones', function (Blueprint $table) {
            $table->dropForeign(['accion_respuesta_usuario_id']);
            $table->dropIndex('ix_notificaciones_tipo_accion_estado');
            $table->dropIndex('ix_notificaciones_accion_disponible');
            $table->dropColumn([
                'accion_estado',
                'accion_respuesta',
                'accion_respuesta_usuario_id',
                'accion_respondida_at',
                'accion_disponible_nuevamente_at',
                'metadata',
            ]);
        });
    }
};
