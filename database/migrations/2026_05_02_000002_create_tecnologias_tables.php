<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tecnologias', function (Blueprint $table) {
            $table->id('id_tecnologia');

            $table->string('nombre', 100)->unique();

            $table->enum('tipo', [
                'lenguaje',
                'framework',
                'libreria',
                'base_datos',
                'herramienta',
                'servicio',
                'plataforma',
                'otro',
            ])->default('otro');

            $table->text('icono_url')->nullable();
            $table->string('color', 20)->nullable();
            $table->text('descripcion')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('uso_tecnologias', function (Blueprint $table) {
            $table->id('id_uso_tecnologia');

            $table->unsignedBigInteger('id_proyecto');
            $table->unsignedBigInteger('id_tecnologia');

            $table->string('version_usada', 80)->nullable();
            $table->decimal('porcentaje_uso', 5, 2)->nullable();

            $table->boolean('es_principal')->default(false);
            $table->boolean('es_visible')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['id_proyecto', 'id_tecnologia']);

            $table->foreign('id_proyecto')
                ->references('id_proyecto')
                ->on('proyectos')
                ->cascadeOnDelete();

            $table->foreign('id_tecnologia')
                ->references('id_tecnologia')
                ->on('tecnologias')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uso_tecnologias');
        Schema::dropIfExists('tecnologias');
    }
};
