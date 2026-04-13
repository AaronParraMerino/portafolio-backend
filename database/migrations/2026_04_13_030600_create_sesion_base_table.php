<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesion_base', function (Blueprint $table) {
            $table->increments('id_rastreo_interno');

            // Identificador temporal
            $table->string('session_token', 64)->unique();

            // Datos de red
            $table->string('ip_address', 45)->nullable();
            $table->string('isp_proveedor', 100)->nullable();
            $table->char('pais_codigo', 2)->nullable();

            // Datos del dispositivo
            $table->string('navegador_nombre', 50)->nullable();
            $table->string('navegador_version', 20)->nullable();
            $table->string('sistema_operativo', 50)->nullable();
            $table->boolean('es_movil')->nullable();

            // Datos de visualizacion
            $table->string('resolucion_pantalla', 15)->nullable();
            $table->string('idioma_preferido', 10)->nullable();
            $table->string('zona_horaria', 50)->nullable();

            // Referencia
            $table->text('fuente_url')->nullable();
            $table->text('pagina_entrada')->nullable();

            // Tiempos
            $table->timestamp('fecha_ingreso')->useCurrent();
            $table->timestamp('ultima_actividad')->useCurrent();

            // Consentimiento
            $table->boolean('consentimiento_legal')->default(false);

            // Enlaces opcionales para asociar despues la sesion
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->foreign('usuario_id')
                ->references('id_usuario')
                ->on('usuarios')
                ->nullOnDelete();

            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->foreign('personal_access_token_id')
                ->references('id')
                ->on('personal_access_tokens')
                ->nullOnDelete();

            $table->unsignedBigInteger('token_recuperacion_id')->nullable();
            $table->foreign('token_recuperacion_id')
                ->references('id_tokenR')
                ->on('token_recuperaciones')
                ->nullOnDelete();

            $table->unsignedBigInteger('bitacora_id')->nullable();
            $table->foreign('bitacora_id')
                ->references('id_bitacora')
                ->on('bitacoras')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesion_base');
    }
};
