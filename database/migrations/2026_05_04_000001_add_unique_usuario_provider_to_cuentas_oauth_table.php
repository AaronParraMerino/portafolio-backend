<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_oauth', function (Blueprint $table) {
            $table->unique(['usuario_id', 'provider'], 'cuentas_oauth_usuario_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_oauth', function (Blueprint $table) {
            $table->dropUnique('cuentas_oauth_usuario_provider_unique');
        });
    }
};
