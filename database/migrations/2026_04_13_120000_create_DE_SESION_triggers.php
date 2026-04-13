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
            DECLARE
                v_ip  VARCHAR(45);
                v_ua  TEXT;
            BEGIN
                IF NEW.tokenable_type LIKE '%Usuario' THEN
                    SELECT ip_address,
                           NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                    INTO   v_ip, v_ua
                    FROM   sesion_base
                    WHERE  usuario_id = NEW.tokenable_id
                    ORDER  BY ultima_actividad DESC
                    LIMIT  1;

                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.tokenable_id, NEW.tokenable_id,
                        'inicio_sesion', 'Token creado por login',
                        v_ip, v_ua, NOW(), 'personal_access_tokens', NEW.id
                    );
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
            DECLARE
                v_ip  VARCHAR(45);
                v_ua  TEXT;
            BEGIN
                IF OLD.tokenable_type LIKE '%Usuario' THEN
                    IF EXISTS (SELECT 1 FROM sesion_base WHERE personal_access_token_id = OLD.id) THEN
                        SELECT ip_address,
                               NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                        INTO   v_ip, v_ua
                        FROM   sesion_base
                        WHERE  personal_access_token_id = OLD.id
                        LIMIT  1;
                    ELSE
                        SELECT ip_address,
                               NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                        INTO   v_ip, v_ua
                        FROM   sesion_base
                        WHERE  usuario_id = OLD.tokenable_id
                        ORDER  BY ultima_actividad DESC
                        LIMIT  1;
                    END IF;

                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        CASE WHEN EXISTS (SELECT 1 FROM usuarios WHERE id_usuario = OLD.tokenable_id)
                             THEN OLD.tokenable_id ELSE NULL END,
                        OLD.tokenable_id,
                        CASE WHEN EXISTS (SELECT 1 FROM usuarios WHERE id_usuario = OLD.tokenable_id)
                             THEN 'cierre_sesion' ELSE 'delete_token_cascada' END,
                        CASE WHEN EXISTS (SELECT 1 FROM usuarios WHERE id_usuario = OLD.tokenable_id)
                             THEN 'Token eliminado por logout' ELSE 'Token eliminado por cascada al borrar usuario' END,
                        v_ip, v_ua, NOW(), 'personal_access_tokens', OLD.id
                    );
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