<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id('id_chat');

            $table->string('tipo', 20);
            $table->string('estado', 30)->default('activo');

            $table->foreignId('id_usuario_creador')
                ->nullable()
                ->constrained('usuarios', 'id_usuario')
                ->nullOnDelete();

            $table->string('nombre', 150)->nullable();
            $table->json('metadata')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['tipo', 'estado']);
            $table->index('id_usuario_creador');
            $table->index('deleted_at');
        });

        Schema::create('chat_privado_pares', function (Blueprint $table) {
            $table->id('id_chat_privado_par');

            $table->foreignId('id_chat')
                ->constrained('chats', 'id_chat')
                ->cascadeOnDelete();

            $table->foreignId('id_usuario_menor')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->foreignId('id_usuario_mayor')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique('id_chat');
            $table->unique(['id_usuario_menor', 'id_usuario_mayor'], 'chat_privado_pares_usuarios_unique');
            $table->index('id_usuario_mayor');
        });

        Schema::create('chat_participantes', function (Blueprint $table) {
            $table->id('id_chat_participante');

            $table->foreignId('id_chat')
                ->constrained('chats', 'id_chat')
                ->cascadeOnDelete();

            $table->foreignId('id_usuario')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('rol', 30)->default('miembro');
            $table->string('estado', 30)->default('activo');
            $table->boolean('archivado')->default(false);
            $table->boolean('bloqueo_saliente')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->index(['id_chat', 'estado']);
            $table->index(['id_usuario', 'estado']);
            $table->index(['id_usuario', 'archivado']);
            $table->index('joined_at');
            $table->index('left_at');
        });

        Schema::create('chat_solicitudes', function (Blueprint $table) {
            $table->id('id_chat_solicitud');

            $table->foreignId('id_chat')
                ->constrained('chats', 'id_chat')
                ->cascadeOnDelete();

            $table->foreignId('id_solicitante')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->foreignId('id_destinatario')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('estado', 30)->default('pendiente');
            $table->text('mensaje_inicial')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();

            $table->timestamps();

            $table->index(['id_solicitante', 'id_destinatario', 'estado'], 'chat_solicitudes_partes_estado_idx');
            $table->index(['id_destinatario', 'estado']);
            $table->index('expires_at');
            $table->index('cooldown_until');
        });

        Schema::create('chat_invitaciones', function (Blueprint $table) {
            $table->id('id_chat_invitacion');

            $table->foreignId('id_chat')
                ->constrained('chats', 'id_chat')
                ->cascadeOnDelete();

            $table->foreignId('id_invitador')
                ->nullable()
                ->constrained('usuarios', 'id_usuario')
                ->nullOnDelete();

            $table->foreignId('id_invitado')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('estado', 30)->default('pendiente');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('respondida_at')->nullable();

            $table->timestamps();

            $table->index(['id_chat', 'estado']);
            $table->index(['id_invitado', 'estado']);
            $table->index('expires_at');
        });

        Schema::create('chat_mensajes', function (Blueprint $table) {
            $table->id('id_chat_mensaje');

            $table->foreignId('id_chat')
                ->constrained('chats', 'id_chat')
                ->cascadeOnDelete();

            $table->foreignId('id_usuario_emisor')
                ->nullable()
                ->constrained('usuarios', 'id_usuario')
                ->nullOnDelete();

            $table->string('tipo', 30)->default('texto');
            $table->text('contenido')->nullable();
            $table->json('metadata')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['id_chat', 'created_at']);
            $table->index('id_usuario_emisor');
            $table->index('deleted_at');
        });

        Schema::create('chat_lecturas', function (Blueprint $table) {
            $table->id('id_chat_lectura');

            $table->foreignId('id_chat')
                ->constrained('chats', 'id_chat')
                ->cascadeOnDelete();

            $table->foreignId('id_usuario')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->foreignId('ultimo_mensaje_leido_id')
                ->nullable()
                ->constrained('chat_mensajes', 'id_chat_mensaje')
                ->nullOnDelete();

            $table->timestamp('leido_at')->nullable();

            $table->timestamps();

            $table->unique(['id_chat', 'id_usuario']);
            $table->index(['id_usuario', 'leido_at']);
        });

        Schema::create('denuncias', function (Blueprint $table) {
            $table->id('id_denuncia');

            $table->foreignId('id_denunciante')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnDelete();

            $table->string('asunto', 180);
            $table->string('motivo', 120);
            $table->text('detalle')->nullable();
            $table->json('evidencia')->nullable();
            $table->json('metadata')->nullable();
            $table->string('estado', 30)->default('pendiente');

            $table->foreignId('id_usuario_revisor')
                ->nullable()
                ->constrained('usuarios', 'id_usuario')
                ->nullOnDelete();

            $table->text('respuesta_admin')->nullable();
            $table->timestamp('revisado_at')->nullable();

            $table->timestamps();

            $table->index('estado');
            $table->index('id_denunciante');
            $table->index('id_usuario_revisor');
            $table->index('created_at');
        });

        DB::statement("
            ALTER TABLE chats
            ADD CONSTRAINT chats_tipo_check
            CHECK (tipo IN ('privado', 'grupo'))
        ");

        DB::statement("
            ALTER TABLE chats
            ADD CONSTRAINT chats_estado_check
            CHECK (estado IN ('solicitud', 'activo', 'cerrado'))
        ");

        DB::statement("
            ALTER TABLE chat_privado_pares
            ADD CONSTRAINT chat_privado_pares_orden_check
            CHECK (id_usuario_menor < id_usuario_mayor)
        ");

        DB::statement("
            ALTER TABLE chat_participantes
            ADD CONSTRAINT chat_participantes_rol_check
            CHECK (rol IN ('owner', 'admin', 'miembro', 'solicitante'))
        ");

        DB::statement("
            ALTER TABLE chat_participantes
            ADD CONSTRAINT chat_participantes_estado_check
            CHECK (estado IN ('pendiente', 'activo', 'salio'))
        ");

        DB::statement("
            ALTER TABLE chat_solicitudes
            ADD CONSTRAINT chat_solicitudes_estado_check
            CHECK (estado IN ('pendiente', 'aceptada', 'rechazada', 'expirada'))
        ");

        DB::statement("
            ALTER TABLE chat_invitaciones
            ADD CONSTRAINT chat_invitaciones_estado_check
            CHECK (estado IN ('pendiente', 'aceptada', 'rechazada', 'expirada'))
        ");

        DB::statement("
            ALTER TABLE chat_mensajes
            ADD CONSTRAINT chat_mensajes_tipo_check
            CHECK (tipo IN ('texto', 'imagen', 'archivo', 'sistema'))
        ");

        DB::statement("
            ALTER TABLE denuncias
            ADD CONSTRAINT denuncias_estado_check
            CHECK (estado IN ('pendiente', 'en_revision', 'resuelta', 'descartada'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('denuncias');
        Schema::dropIfExists('chat_lecturas');
        Schema::dropIfExists('chat_mensajes');
        Schema::dropIfExists('chat_invitaciones');
        Schema::dropIfExists('chat_solicitudes');
        Schema::dropIfExists('chat_participantes');
        Schema::dropIfExists('chat_privado_pares');
        Schema::dropIfExists('chats');
    }
};
