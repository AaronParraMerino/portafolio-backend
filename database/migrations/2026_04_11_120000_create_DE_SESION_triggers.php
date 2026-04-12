<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_inicio_sesion ON personal_access_tokens;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_cierre_sesion ON personal_access_tokens;');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_inicio_sesion();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_cierre_sesion();');

        DB::unprepared("
            CREATE FUNCTION fn_bitacora_inicio_sesion()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.tokenable_type LIKE '%Usuario' THEN
                    INSERT INTO bitacoras (usuario_id, accion, descripcion, ip_address, user_agent, fecha)
                    VALUES (NEW.tokenable_id, 'inicio_sesion', 'Token creado por login', '0.0.0.0', NULL, NOW());
                END IF;

                RETURN NEW;
            END;
            $$;
        ");

        DB::unprepared("
            CREATE FUNCTION fn_bitacora_cierre_sesion()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.tokenable_type LIKE '%Usuario' THEN
                    INSERT INTO bitacoras (usuario_id, accion, descripcion, ip_address, user_agent, fecha)
                    VALUES (OLD.tokenable_id, 'cierre_sesion', 'Token eliminado por logout', '0.0.0.0', NULL, NOW());
                END IF;

                RETURN OLD;
            END;
            $$;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_inicio_sesion
            AFTER INSERT ON personal_access_tokens
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_inicio_sesion();
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_cierre_sesion
            AFTER DELETE ON personal_access_tokens
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_cierre_sesion();
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_inicio_sesion ON personal_access_tokens;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_cierre_sesion ON personal_access_tokens;');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_inicio_sesion();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_cierre_sesion();');
    }
};
