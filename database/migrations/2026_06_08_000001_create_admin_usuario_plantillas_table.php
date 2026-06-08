<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_usuario_plantillas', function (Blueprint $table) {
            $table->id('id_plantilla');
            $table->foreignId('usuario_creador_id')->constrained('usuarios', 'id_usuario');
            $table->foreignId('usuario_actualizador_id')->nullable()->constrained('usuarios', 'id_usuario')->nullOnDelete();
            $table->string('titulo', 100);
            $table->text('cuerpo');
            $table->string('tipo', 40);
            $table->string('urgencia', 20)->default('baja');
            $table->json('canales');
            $table->unsignedInteger('usadas')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_usuario_plantillas');
    }
};
