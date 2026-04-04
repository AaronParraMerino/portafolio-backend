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
        Schema::create('experiencias', function (Blueprint $table) {
        $table->id('id_experiencia');

        $table->foreignId('usuario_id')
            ->constrained('usuarios', 'id_usuario')
            ->cascadeOnDelete();

        $table->enum('tipo', ['laboral', 'academica']);
        $table->string('institucion');
        $table->string('cargo');
        $table->text('descripcion')->nullable();

        $table->date('fecha_inicio');
        $table->date('fecha_fin')->nullable();

        $table->boolean('es_actual')->default(false);
        $table->boolean('es_publico')->default(true);

        $table->timestamp('fecha_modificacion')->nullable();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('experiencias');
    }
};
