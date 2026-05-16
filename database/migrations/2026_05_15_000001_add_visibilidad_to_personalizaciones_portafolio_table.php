<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personalizaciones_portafolio', function (Blueprint $table) {
            $table->json('visibilidad')->nullable()->after('disponible');
        });
    }

    public function down(): void
    {
        Schema::table('personalizaciones_portafolio', function (Blueprint $table) {
            $table->dropColumn('visibilidad');
        });
    }
};
