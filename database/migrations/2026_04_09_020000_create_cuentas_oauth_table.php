<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    Schema::create('cuentas_oauth', function (Blueprint $table) {
        $table->id('id_cuenta_oauth');

        $table->unsignedBigInteger('usuario_id');
        $table->string('provider');          // 'google', 'github', etc.
        $table->string('provider_user_id');  // el sub/id que da Google
        $table->string('email');
        $table->string('nombre')->nullable();
        $table->string('foto_url')->nullable();

        $table->unique(['provider', 'provider_user_id']); // ← único por par, no solo por id
        $table->timestamps();

        $table->foreign('usuario_id')
              ->references('id_usuario')
              ->on('usuarios')
              ->onDelete('cascade');
    });
}

    public function down(): void
    {
        Schema::dropIfExists('cuentas_oauth');
    }
};