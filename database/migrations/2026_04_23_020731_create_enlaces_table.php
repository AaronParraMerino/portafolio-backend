<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enlaces', function (Blueprint $table) {
            $table->id('id_enlace');

            // usuario al que pertenece el enlace
            $table->unsignedBigInteger('id_usuario');

            $table->string('nombre');
            $table->string('link');
            $table->text('descripcion')->nullable();
            $table->boolean('es_visible')->default(true);

            $table->timestamps();

            // llave foranea
            $table->foreign('id_usuario')
                  ->references('id_usuario')
                  ->on('usuarios')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enlaces');
    }
};