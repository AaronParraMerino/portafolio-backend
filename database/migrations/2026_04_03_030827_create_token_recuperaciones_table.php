<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('token_recuperaciones', function (Blueprint $table) {
            $table->id('id_tokenR');

            $table->foreignId('usuario_id')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('token_hash');
            $table->enum('estado', ['inactivo','activo', 'usado', 'expirado']);

            $table->timestamp('fecha_expiracion');
            $table->timestamp('fecha_creacion')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('token_recuperaciones');
    }
};
