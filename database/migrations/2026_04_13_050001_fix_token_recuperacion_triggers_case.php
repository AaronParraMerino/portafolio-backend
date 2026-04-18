<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("
            CREATE OR REPLACE FUNCTION fn_bitacora_token_recuperacion_solicitado()
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
                    'solicitar_recuperacion', 'Codigo de recuperacion solicitado',
                    v_ip, v_ua, NOW(), 'token_recuperaciones', (to_jsonb(NEW)->>'id_tokenR')::bigint
                );

                RETURN NEW;
            END;
            $$;
        ");

        DB::unprepared("
            CREATE OR REPLACE FUNCTION fn_bitacora_token_recuperacion_actualizado()
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
                        v_desc   := 'Codigo de recuperacion verificado';
                    WHEN 'usado' THEN
                        v_accion := 'usar_recuperacion';
                        v_desc   := 'Contrasena restablecida con codigo de recuperacion';
                    WHEN 'expirado' THEN
                        v_accion := 'expirar_recuperacion';
                        v_desc   := 'Codigo de recuperacion expirado';
                    ELSE
                        v_accion := 'update_token_recuperacion';
                        v_desc   := 'Estado del token de recuperacion actualizado';
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
    }

    public function down(): void
    {
        // No-op: the previous migration defines these functions; this hotfix is intentionally forward-only.
    }
};
