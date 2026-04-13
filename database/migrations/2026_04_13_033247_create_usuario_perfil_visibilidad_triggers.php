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
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_usuarios ON usuarios;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_perfil ON perfiles;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_visibilidad ON visibilidad_campos;');

        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_usuarios();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_perfil();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_visibilidad();');

        // =========================
        // USUARIOS
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_usuarios()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_uid BIGINT;
                v_ip  VARCHAR(45);
                v_ua  TEXT;
            BEGIN
                v_uid := CASE TG_OP WHEN 'DELETE' THEN OLD.id_usuario ELSE NEW.id_usuario END;

                SELECT ip_address,
                       NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                INTO   v_ip, v_ua
                FROM   sesion_base
                WHERE  usuario_id = v_uid
                ORDER  BY ultima_actividad DESC
                LIMIT  1;

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.id_usuario, NEW.id_usuario,
                        'create_usuario', 'Se creó el usuario',
                        v_ip, v_ua, NOW(), 'usuarios', NEW.id_usuario
                    );

                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.id_usuario, NEW.id_usuario,
                        'update_usuario', 'Se actualizó la información del usuario',
                        v_ip, v_ua, NOW(), 'usuarios', NEW.id_usuario
                    );

                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NULL, OLD.id_usuario,
                        'delete_usuario', 'Se eliminó el usuario',
                        v_ip, v_ua, NOW(), 'usuarios', OLD.id_usuario
                    );
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_usuarios
            AFTER INSERT OR UPDATE OR DELETE ON usuarios
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_usuarios();
        ");

        // =========================
        // PERFILES
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_perfil()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_uid BIGINT;
                v_ip  VARCHAR(45);
                v_ua  TEXT;
            BEGIN
                v_uid := CASE TG_OP WHEN 'DELETE' THEN OLD.usuario_id ELSE NEW.usuario_id END;

                SELECT ip_address,
                       NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                INTO   v_ip, v_ua
                FROM   sesion_base
                WHERE  usuario_id = v_uid
                ORDER  BY ultima_actividad DESC
                LIMIT  1;

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.usuario_id, NEW.usuario_id,
                        'create_perfil', 'Se creó el perfil',
                        v_ip, v_ua, NOW(), 'perfiles', NEW.id_perfil
                    );

                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.usuario_id, NEW.usuario_id,
                        'update_perfil', 'Se actualizó el perfil',
                        v_ip, v_ua, NOW(), 'perfiles', NEW.id_perfil
                    );

                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        CASE WHEN EXISTS (SELECT 1 FROM usuarios WHERE id_usuario = OLD.usuario_id)
                             THEN OLD.usuario_id ELSE NULL END,
                        OLD.usuario_id,
                        'delete_perfil', 'Se eliminó el perfil',
                        v_ip, v_ua, NOW(), 'perfiles', OLD.id_perfil
                    );
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_perfil
            AFTER INSERT OR UPDATE OR DELETE ON perfiles
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_perfil();
        ");

        // =========================
        // VISIBILIDAD
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_visibilidad()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_uid BIGINT;
                v_ip  VARCHAR(45);
                v_ua  TEXT;
            BEGIN
                v_uid := CASE TG_OP WHEN 'DELETE' THEN OLD.usuario_id ELSE NEW.usuario_id END;

                SELECT ip_address,
                       NULLIF(CONCAT_WS('/', navegador_nombre, navegador_version), '')
                INTO   v_ip, v_ua
                FROM   sesion_base
                WHERE  usuario_id = v_uid
                ORDER  BY ultima_actividad DESC
                LIMIT  1;

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.usuario_id, NEW.usuario_id,
                        'create_visibilidad', 'Se creó configuración de visibilidad',
                        v_ip, v_ua, NOW(), 'visibilidad_campos', NEW.id_visibilidad
                    );

                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        NEW.usuario_id, NEW.usuario_id,
                        'update_visibilidad', 'Se actualizó configuración de visibilidad',
                        v_ip, v_ua, NOW(), 'visibilidad_campos', NEW.id_visibilidad
                    );

                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, usuario_referencia_id, accion, descripcion,
                        ip_address, user_agent, fecha, tabla_afectada, registro_afectado_id
                    ) VALUES (
                        CASE WHEN EXISTS (SELECT 1 FROM usuarios WHERE id_usuario = OLD.usuario_id)
                             THEN OLD.usuario_id ELSE NULL END,
                        OLD.usuario_id,
                        'delete_visibilidad', 'Se eliminó configuración de visibilidad',
                        v_ip, v_ua, NOW(), 'visibilidad_campos', OLD.id_visibilidad
                    );
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_bitacora_visibilidad
            AFTER INSERT OR UPDATE OR DELETE ON visibilidad_campos
            FOR EACH ROW
            EXECUTE FUNCTION fn_bitacora_visibilidad();
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_usuarios ON usuarios;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_perfil ON perfiles;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_visibilidad ON visibilidad_campos;');

        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_usuarios();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_perfil();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_visibilidad();');
    }
};