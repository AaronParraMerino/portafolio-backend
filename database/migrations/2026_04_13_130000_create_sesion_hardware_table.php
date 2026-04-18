<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesion_hardware', function (Blueprint $table) {
            $table->id();

            // Relacion 1:1 con sesion_base
            $table->unsignedInteger('id_rastreo_base')->unique();
            $table->foreign('id_rastreo_base')
                ->references('id_rastreo_interno')
                ->on('sesion_base')
                ->onDelete('cascade');

            // Huella avanzada del dispositivo (solo si acepta cookies)
            $table->string('gpu_renderer')->nullable();
            $table->unsignedTinyInteger('cpu_nucleos')->nullable();
            $table->decimal('ram_estimada', 5, 2)->nullable();
            $table->boolean('hdr_soporte')->nullable();
            $table->string('bateria_nivel', 20)->nullable();

            // Identificador persistente (cookie de larga vida)
            $table->uuid('uuid_persistente')->index();

            // Evidencia de consentimiento
            $table->timestamp('consentimiento_fecha')->nullable();
            $table->string('consentimiento_version', 20)->nullable();
            $table->string('consentimiento_ip', 45)->nullable();
            $table->text('consentimiento_user_agent')->nullable();
            $table->char('consentimiento_firma', 64)->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sesion_hardware');
    }
};