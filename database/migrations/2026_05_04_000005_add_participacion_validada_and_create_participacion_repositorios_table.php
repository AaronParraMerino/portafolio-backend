<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participaciones', function (Blueprint $table) {
            $table->boolean('participacion_validada')->default(false)->after('descripcion_aporte');
        });

        Schema::create('participacion_repositorios', function (Blueprint $table) {
            $table->id('id_participacion_repositorio');

            $table->unsignedBigInteger('id_participacion');
            $table->unsignedBigInteger('id_proyecto_repositorio');

            $table->boolean('validado')->default(false);
            $table->boolean('es_propietario')->default(false);
            $table->timestamp('validado_at')->nullable();

            $table->timestamps();

            $table->unique(['id_participacion', 'id_proyecto_repositorio'], 'uq_participacion_repo');

            $table->foreign('id_participacion')
                ->references('id_participacion')
                ->on('participaciones')
                ->cascadeOnDelete();

            $table->foreign('id_proyecto_repositorio')
                ->references('id_proyecto_repositorio')
                ->on('proyecto_repositorios')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participacion_repositorios');

        Schema::table('participaciones', function (Blueprint $table) {
            $table->dropColumn('participacion_validada');
        });
    }
};
