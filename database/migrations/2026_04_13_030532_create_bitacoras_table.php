<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bitacoras', function (Blueprint $table) {
            $table->id('id_bitacora');

            // Usuario "vivo" relacionado al evento.
            // Nullable para que la bitácora no falle cuando el usuario ya fue eliminado.
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->foreign('usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            // Referencia histórica del usuario involucrado, sin FK.
            $table->unsignedBigInteger('usuario_referencia_id')->nullable();

            $table->string('accion', 100);
            $table->text('descripcion')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Para saber qué tabla y qué registro disparó el evento
            $table->string('tabla_afectada', 100)->nullable();
            $table->unsignedBigInteger('registro_afectado_id')->nullable();

            $table->timestamp('fecha')->useCurrent();

            $table->index('usuario_id');
            $table->index('usuario_referencia_id');
            $table->index('accion');
            $table->index('fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bitacoras');
    }
};