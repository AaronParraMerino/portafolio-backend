<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE proyecto_repositorios DROP CONSTRAINT IF EXISTS proyecto_repositorios_id_proyecto_foreign');
            DB::statement('ALTER TABLE proyecto_repositorios ALTER COLUMN id_proyecto DROP NOT NULL');
            DB::statement('ALTER TABLE proyecto_repositorios ADD CONSTRAINT proyecto_repositorios_id_proyecto_foreign FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto) ON DELETE SET NULL');
            return;
        }

        DB::statement('ALTER TABLE proyecto_repositorios DROP FOREIGN KEY proyecto_repositorios_id_proyecto_foreign');
        DB::statement('ALTER TABLE proyecto_repositorios MODIFY id_proyecto BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE proyecto_repositorios ADD CONSTRAINT proyecto_repositorios_id_proyecto_foreign FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto) ON DELETE SET NULL');
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        DB::table('proyecto_repositorios')->whereNull('id_proyecto')->delete();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE proyecto_repositorios DROP CONSTRAINT IF EXISTS proyecto_repositorios_id_proyecto_foreign');
            DB::statement('ALTER TABLE proyecto_repositorios ALTER COLUMN id_proyecto SET NOT NULL');
            DB::statement('ALTER TABLE proyecto_repositorios ADD CONSTRAINT proyecto_repositorios_id_proyecto_foreign FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto) ON DELETE CASCADE');
            return;
        }

        DB::statement('ALTER TABLE proyecto_repositorios DROP FOREIGN KEY proyecto_repositorios_id_proyecto_foreign');
        DB::statement('ALTER TABLE proyecto_repositorios MODIFY id_proyecto BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE proyecto_repositorios ADD CONSTRAINT proyecto_repositorios_id_proyecto_foreign FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto) ON DELETE CASCADE');
    }
};
