<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traducciones_contenido', function (Blueprint $table) {
            $table->id('id_traduccion');

            $table->unsignedBigInteger('usuario_id')->nullable();

            $table->string('entidad_tipo', 50);
            $table->unsignedBigInteger('entidad_id');

            $table->string('campo', 80);
            $table->string('idioma', 5);

            $table->text('texto_traducido');

            $table->string('origen', 30)->default('manual');
            $table->string('estado', 30)->default('revisado');

            $table->timestamps();

            $table->unique(
                ['entidad_tipo', 'entidad_id', 'campo', 'idioma'],
                'traducciones_contenido_unique'
            );

            $table->index(['usuario_id', 'idioma'], 'traducciones_contenido_usuario_idioma_idx');
            $table->index(['entidad_tipo', 'entidad_id'], 'traducciones_contenido_entidad_idx');

            $table->foreign('usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traducciones_contenido');
    }
};