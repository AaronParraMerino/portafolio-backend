<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personalizaciones_portafolio')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE personalizaciones_portafolio DROP CONSTRAINT IF EXISTS personalizaciones_portafolio_hero_pattern_check");
            DB::statement("ALTER TABLE personalizaciones_portafolio ADD CONSTRAINT personalizaciones_portafolio_hero_pattern_check CHECK (hero_pattern IN ('dots', 'grid', 'hex', 'waves', 'none'))");
            DB::statement("ALTER TABLE personalizaciones_portafolio ALTER COLUMN hero_pattern SET DEFAULT 'none'");
            DB::statement("ALTER TABLE personalizaciones_portafolio ALTER COLUMN frame_id SET DEFAULT 'none'");
            DB::statement("ALTER TABLE personalizaciones_portafolio ALTER COLUMN disponible SET DEFAULT true");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE personalizaciones_portafolio MODIFY hero_pattern ENUM('dots', 'grid', 'hex', 'waves', 'none') NOT NULL DEFAULT 'none'");
            DB::statement("ALTER TABLE personalizaciones_portafolio MODIFY frame_id ENUM('thick', 'mac', 'linux', 'windows', 'none') NOT NULL DEFAULT 'none'");
            DB::statement('ALTER TABLE personalizaciones_portafolio MODIFY disponible BOOLEAN NOT NULL DEFAULT true');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('personalizaciones_portafolio')) {
            return;
        }

        DB::table('personalizaciones_portafolio')
            ->where('hero_pattern', 'waves')
            ->update(['hero_pattern' => 'none']);

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE personalizaciones_portafolio DROP CONSTRAINT IF EXISTS personalizaciones_portafolio_hero_pattern_check");
            DB::statement("ALTER TABLE personalizaciones_portafolio ADD CONSTRAINT personalizaciones_portafolio_hero_pattern_check CHECK (hero_pattern IN ('dots', 'grid', 'hex', 'none'))");
            DB::statement("ALTER TABLE personalizaciones_portafolio ALTER COLUMN hero_pattern SET DEFAULT 'dots'");
            DB::statement("ALTER TABLE personalizaciones_portafolio ALTER COLUMN frame_id SET DEFAULT 'mac'");
            DB::statement("ALTER TABLE personalizaciones_portafolio ALTER COLUMN disponible SET DEFAULT false");

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE personalizaciones_portafolio MODIFY hero_pattern ENUM('dots', 'grid', 'hex', 'none') NOT NULL DEFAULT 'dots'");
            DB::statement("ALTER TABLE personalizaciones_portafolio MODIFY frame_id ENUM('thick', 'mac', 'linux', 'windows', 'none') NOT NULL DEFAULT 'mac'");
            DB::statement('ALTER TABLE personalizaciones_portafolio MODIFY disponible BOOLEAN NOT NULL DEFAULT false');
        }
    }
};
