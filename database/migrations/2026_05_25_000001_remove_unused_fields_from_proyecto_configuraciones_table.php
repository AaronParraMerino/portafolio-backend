<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proyecto_configuraciones', function (Blueprint $table) {
            $table->dropColumn([
                'modo_union',
                'requiere_aprobacion_union',
                'enlace_union_activo',
                'enlace_union_token',
                'enlace_union_expira_at',
                'visibilidad_github_validado_externo',
                'visibilidad_github_validado_usuario',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('proyecto_configuraciones', function (Blueprint $table) {
            $table->enum('modo_union', [
                'cerrado',
                'por_solicitud',
                'enlace_autenticado',
                'github_validado',
            ])->default('github_validado');
            $table->boolean('requiere_aprobacion_union')->default(true);
            $table->boolean('enlace_union_activo')->default(false);
            $table->string('enlace_union_token', 100)->nullable()->unique();
            $table->timestamp('enlace_union_expira_at')->nullable();
            $table->enum('visibilidad_github_validado_externo', [
                'oculto',
                'visible',
            ])->default('visible');
            $table->enum('visibilidad_github_validado_usuario', [
                'visible',
                'oculto',
            ])->default('visible');
        });
    }
};
