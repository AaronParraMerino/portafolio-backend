<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('denuncias', 'asunto')) {
            DB::statement('ALTER TABLE denuncias ALTER COLUMN asunto DROP DEFAULT');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('denuncias', 'asunto')) {
            DB::statement("ALTER TABLE denuncias ALTER COLUMN asunto SET DEFAULT 'Asunto denunciado'");
        }
    }
};
