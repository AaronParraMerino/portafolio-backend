<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_personales', function (Blueprint $table) {
            $table->id('id_evento');

            $table->foreignId('usuario_id')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('titulo', 50);
            $table->string('descripcion', 160)->nullable();
            $table->date('fecha');
            $table->time('hora');
            $table->enum('tipo', [
                'personal',
                'academico',
                'trabajo',
                'reunion',
                'entrega',
                'otro',
            ]);
            $table->enum('estado', ['activo', 'eliminado'])->default('activo');

            $table->timestamps();

            $table->index(['usuario_id', 'estado']);
            $table->index(['usuario_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_personales');
    }
};
