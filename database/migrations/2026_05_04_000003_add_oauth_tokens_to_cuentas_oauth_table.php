<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_oauth', function (Blueprint $table) {
            $table->text('access_token')->nullable()->after('foto_url');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->text('token_scopes')->nullable()->after('refresh_token');
            $table->timestamp('token_expires_at')->nullable()->after('token_scopes');
            $table->timestamp('token_updated_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('cuentas_oauth', function (Blueprint $table) {
            $table->dropColumn([
                'access_token',
                'refresh_token',
                'token_scopes',
                'token_expires_at',
                'token_updated_at',
            ]);
        });
    }
};
