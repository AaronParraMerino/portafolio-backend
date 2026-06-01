<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_inscripciones', function (Blueprint $table) {
            $table->id('id_inscripcion');

            $table->unsignedBigInteger('evento_id');
            $table->unsignedBigInteger('usuario_id');

            $table->string('estado', 30)->default('inscrito');

            $table->timestamp('fecha_inscripcion')->useCurrent();
            $table->timestamp('fecha_desinscripcion')->nullable();

            $table->timestamps();

            $table->foreign('evento_id')
                ->references('id_evento')
                ->on('admin_eventos')
                ->cascadeOnDelete();

            $table->foreign('usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();

            $table->index(['evento_id', 'estado']);
            $table->index(['usuario_id', 'estado']);
            $table->index(['evento_id', 'usuario_id']);
            $table->index('fecha_inscripcion');
            $table->index('fecha_desinscripcion');
        });

        DB::statement("ALTER TABLE evento_inscripciones ADD CONSTRAINT evento_inscripciones_estado_check CHECK (estado IN ('inscrito', 'desinscrito'))");

        DB::statement("
            CREATE UNIQUE INDEX evento_inscripciones_unica_activa
            ON evento_inscripciones (evento_id, usuario_id)
            WHERE fecha_desinscripcion IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS evento_inscripciones_unica_activa");

        Schema::dropIfExists('evento_inscripciones');
    }
};