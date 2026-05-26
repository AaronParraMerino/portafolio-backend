<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id('id_notificacion');

            $table->unsignedBigInteger('id_usuario_destino');
            $table->unsignedBigInteger('id_usuario_actor')->nullable();

            $table->string('tipo', 80);
            $table->string('modulo', 50);

            $table->string('titulo', 50);
            $table->text('contenido')->nullable();

            $table->string('referencia_tipo', 50)->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();

            $table->json('data')->nullable();

            $table->string('event_key')->nullable()->unique();

            $table->timestamp('leida_en')->nullable();

            $table->timestamps();

            $table->foreign('id_usuario_destino')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();

            $table->foreign('id_usuario_actor')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['id_usuario_destino', 'leida_en']);
            $table->index(['id_usuario_destino', 'created_at']);
            $table->index(['modulo', 'tipo']);
            $table->index(['referencia_tipo', 'referencia_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};