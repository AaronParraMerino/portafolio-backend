<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =========================
        // DROP TODO
        // =========================
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_usuarios ON usuarios;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_perfil ON perfiles;');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_bitacora_visibilidad ON visibilidad_campos;');

        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_usuarios();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_perfil();');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bitacora_visibilidad();');

        // =========================
        // USERS (INSERT / UPDATE / DELETE)
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_usuarios()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        NEW.id_usuario,
                        'create_usuario',
                        'Se creo el usuario',
                        '0.0.0.0',
                        NULL,
                        NOW()
                    );

                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        NEW.id_usuario,
                        'update_usuario',
                        'Se actualizo la informacion del usuario',
                        '0.0.0.0',
                        NULL,
                        NOW()
                    );

                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        OLD.id_usuario,
                        'delete_usuario',
                        'Se eliminó el usuario',
                        '0.0.0.0',
                        NULL,
                        NOW()
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
        // PERFIL
        // =========================
        DB::unprepared("
            CREATE FUNCTION fn_bitacora_perfil()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        NEW.usuario_id,
                        'create_perfil',
                        'Se creo el perfil',
                        '0.0.0.0',
                        NULL,
                        NOW()
                    );

                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        NEW.usuario_id,
                        'update_perfil',
                        'Se actualizo los datos del perfil',
                        '0.0.0.0',
                        NULL,
                        NOW()
                    );

                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        OLD.usuario_id,
                        'delete_perfil',
                        'Se eliminó el perfil',
                        '0.0.0.0',
                        NULL,
                        NOW()
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
            BEGIN

                IF TG_OP = 'INSERT' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        NEW.usuario_id,
                        'create_visibilidad',
                        'Se creó configuración de visibilidad',
                        '0.0.0.0',
                        NULL,
                        NOW()
                    );

                ELSIF TG_OP = 'UPDATE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        NEW.usuario_id,
                        'update_visibilidad',
                        'Se actualizó configuración de visibilidad',
                        '0.0.0.0',
                        NULL,
                        NOW()
                    );

                ELSIF TG_OP = 'DELETE' THEN
                    INSERT INTO bitacoras (
                        usuario_id, accion, descripcion, ip_address, user_agent, fecha
                    )
                    VALUES (
                        OLD.usuario_id,
                        'delete_visibilidad',
                        'Se eliminó configuración de visibilidad',
                        '0.0.0.0',
                        NULL,
                        NOW()
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