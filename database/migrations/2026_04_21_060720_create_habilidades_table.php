<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habilidades', function (Blueprint $table) {
            $table->bigIncrements('id_habilidad');
            $table->string('nombre', 100);
            $table->string('nombre_normalizado', 100)->unique();
            $table->enum('tipo', ['tecnica', 'blanda']);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habilidades');
    }
};