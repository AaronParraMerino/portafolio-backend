<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habilidades_usuario', function (Blueprint $table) {
            $table->bigIncrements('id_habilidad_usuario');

            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('habilidad_id');

            $table->enum('nivel', ['basico', 'intermedio', 'avanzado', 'experto']);
            $table->boolean('es_visible')->default(true);
            $table->timestamp('fecha_modificacion')->nullable();

            $table->timestamps();

            $table->foreign('usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->onDelete('cascade');

            $table->foreign('habilidad_id')
                ->references('id_habilidad')
                ->on('habilidades')
                ->onDelete('cascade');

            $table->unique(['usuario_id', 'habilidad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habilidades_usuario');
    }
};