<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_eventos', function (Blueprint $table) {
            $table->json('dias_activos')->nullable()->after('fecha_fin');
        });
    }

    public function down(): void
    {
        Schema::table('admin_eventos', function (Blueprint $table) {
            $table->dropColumn('dias_activos');
        });
    }
};
