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
        Schema::create('perfiles', function (Blueprint $table) {
            $table->id('id_perfil');

            $table->foreignId('usuario_id')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->text('biografia')->nullable();
            $table->string('ciudad')->nullable();
            $table->string('pais')->nullable();
            $table->string('foto_perfil')->nullable();
            $table->string('foto_fondo')->nullable();

            $table->boolean('es_publico')->default(true);
            $table->timestamp('fecha_modificacion')->nullable();
            $table->unique('usuario_id');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perfiles');
    }
};
