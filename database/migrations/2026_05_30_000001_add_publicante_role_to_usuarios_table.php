<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_check");
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_check CHECK (rol IN ('admin', 'usuario', 'otro', 'publicante'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE usuarios SET rol = 'usuario' WHERE rol = 'publicante'");
        DB::statement("ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_check");
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_check CHECK (rol IN ('admin', 'usuario', 'otro'))");
    }
};
