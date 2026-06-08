<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('admin_evento_plantillas');
    }

    public function down(): void
    {
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
    }
};
