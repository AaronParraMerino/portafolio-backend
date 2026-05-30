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
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id('id_notificacion');

            // Usuario que genero la accion
            $table->foreignId('id_usuario_actor')
                ->nullable()
                ->constrained('usuarios', 'id_usuario')
                ->nullOnDelete();

            // proyectos, eventos, administracion
            $table->string('modulo', 50);

            // proyecto, evento_personal, evento_inscrito, admin_directo
            $table->string('contexto_tipo', 50)->nullable();

            // proyecto_35, evento_41, personales, admin, etc
            $table->string('contexto_referencia', 100)->nullable();

            // Proyecto Sistema Web, Personales, etc
            $table->string('grupo_titulo', 150)->nullable();

            // proyecto_modificado, evento_actualizado
            $table->string('tipo', 80);

            $table->text('mensaje');

            $table->timestamps();

            $table->index(['modulo', 'tipo']);
            $table->index(['modulo', 'contexto_tipo']);
            $table->index('contexto_referencia');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};