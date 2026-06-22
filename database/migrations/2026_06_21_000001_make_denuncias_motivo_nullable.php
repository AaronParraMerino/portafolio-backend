<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('denuncias', 'motivo')) {
            DB::statement('ALTER TABLE denuncias ALTER COLUMN motivo DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('denuncias', 'motivo')) {
            DB::statement("UPDATE denuncias SET motivo = 'general' WHERE motivo IS NULL");
            DB::statement('ALTER TABLE denuncias ALTER COLUMN motivo SET NOT NULL');
        }
    }
};
