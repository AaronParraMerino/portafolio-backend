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
        Schema::create('visibilidad_campos', function (Blueprint $table) {
            $table->id('id_visibilidad');

            $table->foreignId('usuario_id')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->enum('campo', ['correo', 'telefono', 'proyectos']);
            $table->boolean('visible')->default(true);

            $table->unique(['usuario_id', 'campo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visibilidad_campos');
    }
};
