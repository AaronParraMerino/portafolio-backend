<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =========================
        // LIMPIEZA PREVIA
        // =========================
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_token_recuperacion_solicitado ON token_recuperaciones;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_token_recuperacion_actualizado ON token_recuperaciones;');

        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_token_recuperacion_solicitado();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_token_recuperacion_actualizado();');

        // =========================
        // TOKEN RECUPERACIONES — INSERT
        // Fired on: solicitar (TokenRecuperacion::create)
        // Lookup: most recent sesion_base for this user (token not yet linked at INSERT time).
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_token_recuperacion_solicitado()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_ip  VARCHAR(45);
                v_ua  TEXT;
            BEGIN
                SELECT ip_address,
                       NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                INTO   v_ip, v_ua
                FROM   sesion_base
                WHERE  usuario_id = NEW.usuario_id
                ORDER  BY ultima_actividad DESC
                LIMIT  1;

                INSERT INTO bitacoras (
                    usuario_id, usuario_referencia_id, accion, descripcion,
                    ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                ) VALUES (
                    NEW.usuario_id, NEW.usuario_id,
                    'solicitar_recuperacion', 'Código de recuperación solicitado',
                    v_ip, v_ua, NOW(), 'token_recuperaciones', (to_jsonb(NEW)->>'id_tokenR')::bigint
                );

                RETURN NEW;
            END;
            $$;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_token_recuperacion_solicitado
            AFTER INSERT ON token_recuperaciones
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_token_recuperacion_solicitado();
        ");

        // =========================
        // TOKEN RECUPERACIONES — UPDATE
        // Fired on: activar (inactivo→activo), restablecer (activo→usado), expirar (→expirado).
        // Skips rows where estado did not change (e.g. touch-only updates).
        // Lookup:
        //   1. Precise — sesion_base.token_recuperacion_id (linked during solicitar step).
        //   2. Fallback — most recent sesion_base for this user.
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_token_recuperacion_actualizado()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_ip     VARCHAR(45);
                v_ua     TEXT;
                v_accion VARCHAR(100);
                v_desc   TEXT;
            BEGIN
                IF OLD.estado = NEW.estado THEN
                    RETURN NEW;
                END IF;

                CASE NEW.estado
                    WHEN 'activo' THEN
                        v_accion := 'activar_recuperacion';
                        v_desc   := 'Código de recuperación verificado';
                    WHEN 'usado' THEN
                        v_accion := 'usar_recuperacion';
                        v_desc   := 'Contraseña restablecida con código de recuperación';
                    WHEN 'expirado' THEN
                        v_accion := 'expirar_recuperacion';
                        v_desc   := 'Código de recuperación expirado';
                    ELSE
                        v_accion := 'update_token_recuperacion';
                        v_desc   := 'Estado del token de recuperación actualizado';
                END CASE;

                IF EXISTS (SELECT 1 FROM sesion_base WHERE token_recuperacion_id = (to_jsonb(NEW)->>'id_tokenR')::bigint) THEN
                    SELECT ip_address,
                           NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                    INTO   v_ip, v_ua
                    FROM   sesion_base
                    WHERE  token_recuperacion_id = (to_jsonb(NEW)->>'id_tokenR')::bigint
                    ORDER  BY ultima_actividad DESC
                    LIMIT  1;
                ELSE
                    SELECT ip_address,
                           NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                    INTO   v_ip, v_ua
                    FROM   sesion_base
                    WHERE  usuario_id = NEW.usuario_id
                    ORDER  BY ultima_actividad DESC
                    LIMIT  1;
                END IF;

                INSERT INTO bitacoras (
                    usuario_id, usuario_referencia_id, accion, descripcion,
                    ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                ) VALUES (
                    CASE WHEN EXISTS (SELECT 1 FROM usuarios WHERE id_usuario = NEW.usuario_id)
                         THEN NEW.usuario_id ELSE NULL END,
                    NEW.usuario_id,
                    v_accion, v_desc,
                    v_ip, v_ua, NOW(), 'token_recuperaciones', (to_jsonb(NEW)->>'id_tokenR')::bigint
                );

                RETURN NEW;
            END;
            $$;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_token_recuperacion_actualizado
            AFTER UPDATE ON token_recuperaciones
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_token_recuperacion_actualizado();
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_token_recuperacion_solicitado ON token_recuperaciones;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_token_recuperacion_actualizado ON token_recuperaciones;');

        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_token_recuperacion_solicitado();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_token_recuperacion_actualizado();');
    }
};
