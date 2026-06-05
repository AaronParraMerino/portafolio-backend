<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos', function (Blueprint $table) {
            $table->id('id_aviso');

            $table->unsignedBigInteger('id_usuario_actor')->nullable();

            $table->string('tipo', 50);
            $table->string('titulo', 150);
            $table->text('mensaje');

            $table->timestamp('visible_desde')->nullable();
            $table->timestamp('visible_hasta')->nullable();

            $table->string('estado', 30)->default('activo');
            $table->string('prioridad', 30)->default('normal');

            $table->timestamps();

            $table->foreign('id_usuario_actor')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->index(['estado', 'prioridad']);
            $table->index(['estado', 'visible_desde', 'visible_hasta']);
            $table->index('tipo');
            $table->index('created_at');
        });

        DB::statement("
            ALTER TABLE avisos
            ADD CONSTRAINT avisos_estado_check
            CHECK (estado IN ('activo', 'inactivo', 'eliminado'))
        ");

        DB::statement("
            ALTER TABLE avisos
            ADD CONSTRAINT avisos_prioridad_check
            CHECK (prioridad IN ('baja', 'normal', 'alta', 'critica'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos');
    }
};