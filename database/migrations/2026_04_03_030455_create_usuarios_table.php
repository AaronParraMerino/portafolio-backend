<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id('id_usuario');

            $table->string('nombre');
            $table->string('apellido');
            $table->string('correo')->unique();
            $table->string('password');
            $table->string('telefono')->nullable();

            $table->enum('rol', ['admin', 'usuario']);
            $table->enum('estado', ['activo', 'bloqueado']);

            $table->integer('intentos_fallidos')->default(0);
            $table->timestamp('fecha_bloqueo')->nullable();

            $table->enum('proveedor_oauth', ['google', 'facebook'])->nullable();
            $table->string('oauth_id')->nullable();

            $table->string('idioma_preferido')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};