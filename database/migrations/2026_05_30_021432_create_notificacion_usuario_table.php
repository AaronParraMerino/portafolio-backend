<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations
     */
    public function up(): void
    {
        Schema::create('notificacion_usuario', function (Blueprint $table) {
            $table->id('id_notificacion_usuario');

            $table->foreignId('id_notificacion')
                ->constrained('notificaciones', 'id_notificacion')
                ->cascadeOnDelete();

            $table->foreignId('id_usuario')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            // null = no leida, fecha = leida
            $table->timestamp('leido_en')->nullable();

            $table->timestamps();

            $table->unique(['id_notificacion', 'id_usuario']);

            $table->index(['id_usuario', 'leido_en']);
            $table->index(['id_usuario', 'created_at']);
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('notificacion_usuario');
    }
};