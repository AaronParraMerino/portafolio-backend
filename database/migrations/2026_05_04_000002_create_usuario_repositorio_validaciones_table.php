<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_repositorio_validaciones', function (Blueprint $table) {
            $table->id('id_usuario_repositorio_validacion');

            $table->unsignedBigInteger('id_usuario');
            $table->unsignedBigInteger('id_repositorio_github');
            $table->unsignedBigInteger('id_cuenta_oauth')->nullable();

            $table->enum('relacion_github', [
                'owner',
                'collaborator',
                'contributor',
                'member',
                'maintainer',
                'unknown',
            ])->default('unknown');

            $table->boolean('es_propietario')->default(false);
            $table->boolean('validado')->default(false);

            $table->timestamp('validado_at')->nullable();
            $table->timestamp('ultima_verificacion_at')->nullable();

            $table->json('permisos_github')->nullable();
            $table->text('detalle_validacion')->nullable();

            $table->timestamps();

            $table->unique(['id_usuario', 'id_repositorio_github'], 'uq_usuario_repositorio_validaciones_usuario_repo');
            $table->index(['id_usuario', 'validado'], 'ix_usuario_repositorio_validaciones_usuario_validado');
            $table->index('ultima_verificacion_at', 'ix_usuario_repositorio_validaciones_ultima_verificacion');

            $table->foreign('id_usuario')
                ->references('id_usuario')
                ->on('usuarios')
                ->cascadeOnDelete();

            $table->foreign('id_repositorio_github')
                ->references('id_repositorio_github')
                ->on('repositorio_github')
                ->cascadeOnDelete();

            $table->foreign('id_cuenta_oauth')
                ->references('id_cuenta_oauth')
                ->on('cuentas_oauth')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_repositorio_validaciones');
    }
};
