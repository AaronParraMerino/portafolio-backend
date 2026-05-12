


SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;


CREATE SCHEMA IF NOT EXISTS "public";


ALTER SCHEMA "public" OWNER TO "pg_database_owner";


COMMENT ON SCHEMA "public" IS 'standard public schema';



CREATE OR REPLACE FUNCTION "public"."fn_bitacora_cierre_sesion"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_cierre_sesion"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."fn_bitacora_inicio_sesion"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_inicio_sesion"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."fn_bitacora_perfil"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_perfil"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."fn_bitacora_token_recuperacion_actualizado"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_token_recuperacion_actualizado"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."fn_bitacora_token_recuperacion_solicitado"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_token_recuperacion_solicitado"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."fn_bitacora_usuarios"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_usuarios"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."fn_bitacora_visibilidad"() RETURNS "trigger"
    LANGUAGE "plpgsql"
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


ALTER FUNCTION "public"."fn_bitacora_visibilidad"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."rls_auto_enable"() RETURNS "event_trigger"
    LANGUAGE "plpgsql" SECURITY DEFINER
    SET "search_path" TO 'pg_catalog'
    AS $$
DECLARE
  cmd record;
BEGIN
  FOR cmd IN
    SELECT *
    FROM pg_event_trigger_ddl_commands()
    WHERE command_tag IN ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
      AND object_type IN ('table','partitioned table')
  LOOP
     IF cmd.schema_name IS NOT NULL AND cmd.schema_name IN ('public') AND cmd.schema_name NOT IN ('pg_catalog','information_schema') AND cmd.schema_name NOT LIKE 'pg_toast%' AND cmd.schema_name NOT LIKE 'pg_temp%' THEN
      BEGIN
        EXECUTE format('alter table if exists %s enable row level security', cmd.object_identity);
        RAISE LOG 'rls_auto_enable: enabled RLS on %', cmd.object_identity;
      EXCEPTION
        WHEN OTHERS THEN
          RAISE LOG 'rls_auto_enable: failed to enable RLS on %', cmd.object_identity;
      END;
     ELSE
        RAISE LOG 'rls_auto_enable: skip % (either system schema or not in enforced list: %.)', cmd.object_identity, cmd.schema_name;
     END IF;
  END LOOP;
END;
$$;


ALTER FUNCTION "public"."rls_auto_enable"() OWNER TO "postgres";

SET default_tablespace = '';

SET default_table_access_method = "heap";


CREATE TABLE IF NOT EXISTS "public"."bitacoras" (
    "id_bitacora" bigint NOT NULL,
    "usuario_id" bigint,
    "usuario_referencia_id" bigint,
    "accion" character varying(100) NOT NULL,
    "descripcion" "text",
    "ip_address" character varying(45),
    "user_agent" "text",
    "tabla_afectada" character varying(100),
    "registro_afectado_id" bigint,
    "fecha" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE "public"."bitacoras" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."bitacoras_id_bitacora_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."bitacoras_id_bitacora_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."bitacoras_id_bitacora_seq" OWNED BY "public"."bitacoras"."id_bitacora";



CREATE TABLE IF NOT EXISTS "public"."cache" (
    "key" character varying(255) NOT NULL,
    "value" "text" NOT NULL,
    "expiration" integer NOT NULL
);


ALTER TABLE "public"."cache" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."cache_locks" (
    "key" character varying(255) NOT NULL,
    "owner" character varying(255) NOT NULL,
    "expiration" integer NOT NULL
);


ALTER TABLE "public"."cache_locks" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."cuentas_oauth" (
    "id_cuenta_oauth" bigint NOT NULL,
    "usuario_id" bigint NOT NULL,
    "provider" character varying(255) NOT NULL,
    "provider_user_id" character varying(255) NOT NULL,
    "email" character varying(255) NOT NULL,
    "nombre" character varying(255),
    "foto_url" character varying(255),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "access_token" "text",
    "refresh_token" "text",
    "token_scopes" "text",
    "token_expires_at" timestamp(0) without time zone,
    "token_updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."cuentas_oauth" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."cuentas_oauth_id_cuenta_oauth_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."cuentas_oauth_id_cuenta_oauth_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."cuentas_oauth_id_cuenta_oauth_seq" OWNED BY "public"."cuentas_oauth"."id_cuenta_oauth";



CREATE TABLE IF NOT EXISTS "public"."enlaces" (
    "id_enlace" bigint NOT NULL,
    "id_usuario" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "link" character varying(255) NOT NULL,
    "descripcion" "text",
    "es_visible" boolean DEFAULT true NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."enlaces" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."enlaces_id_enlace_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."enlaces_id_enlace_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."enlaces_id_enlace_seq" OWNED BY "public"."enlaces"."id_enlace";



CREATE TABLE IF NOT EXISTS "public"."experiencias" (
    "id_experiencia" bigint NOT NULL,
    "usuario_id" bigint NOT NULL,
    "tipo" character varying(255) NOT NULL,
    "institucion" character varying(255) NOT NULL,
    "cargo" character varying(255) NOT NULL,
    "descripcion" "text",
    "fecha_inicio" "date" NOT NULL,
    "fecha_fin" "date",
    "es_actual" boolean DEFAULT false NOT NULL,
    "es_publico" boolean DEFAULT true NOT NULL,
    "fecha_modificacion" timestamp(0) without time zone,
    CONSTRAINT "experiencias_tipo_check" CHECK ((("tipo")::"text" = ANY ((ARRAY['laboral'::character varying, 'academica'::character varying])::"text"[])))
);


ALTER TABLE "public"."experiencias" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."experiencias_id_experiencia_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."experiencias_id_experiencia_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."experiencias_id_experiencia_seq" OWNED BY "public"."experiencias"."id_experiencia";



CREATE TABLE IF NOT EXISTS "public"."failed_jobs" (
    "id" bigint NOT NULL,
    "uuid" character varying(255) NOT NULL,
    "connection" "text" NOT NULL,
    "queue" "text" NOT NULL,
    "payload" "text" NOT NULL,
    "exception" "text" NOT NULL,
    "failed_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE "public"."failed_jobs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."failed_jobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNED BY "public"."failed_jobs"."id";



CREATE TABLE IF NOT EXISTS "public"."habilidades" (
    "id_habilidad" bigint NOT NULL,
    "nombre" character varying(100) NOT NULL,
    "nombre_normalizado" character varying(100) NOT NULL,
    "tipo" character varying(255) NOT NULL,
    "descripcion" character varying(255),
    "estado" boolean DEFAULT true NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "habilidades_tipo_check" CHECK ((("tipo")::"text" = ANY ((ARRAY['tecnica'::character varying, 'blanda'::character varying])::"text"[])))
);


ALTER TABLE "public"."habilidades" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."habilidades_id_habilidad_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."habilidades_id_habilidad_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."habilidades_id_habilidad_seq" OWNED BY "public"."habilidades"."id_habilidad";



CREATE TABLE IF NOT EXISTS "public"."habilidades_usuario" (
    "id_habilidad_usuario" bigint NOT NULL,
    "usuario_id" bigint NOT NULL,
    "habilidad_id" bigint NOT NULL,
    "nivel" character varying(255) NOT NULL,
    "es_visible" boolean DEFAULT true NOT NULL,
    "fecha_modificacion" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "habilidades_usuario_nivel_check" CHECK ((("nivel")::"text" = ANY ((ARRAY['basico'::character varying, 'intermedio'::character varying, 'avanzado'::character varying, 'experto'::character varying])::"text"[])))
);


ALTER TABLE "public"."habilidades_usuario" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."habilidades_usuario_id_habilidad_usuario_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."habilidades_usuario_id_habilidad_usuario_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."habilidades_usuario_id_habilidad_usuario_seq" OWNED BY "public"."habilidades_usuario"."id_habilidad_usuario";



CREATE TABLE IF NOT EXISTS "public"."job_batches" (
    "id" character varying(255) NOT NULL,
    "name" character varying(255) NOT NULL,
    "total_jobs" integer NOT NULL,
    "pending_jobs" integer NOT NULL,
    "failed_jobs" integer NOT NULL,
    "failed_job_ids" "text" NOT NULL,
    "options" "text",
    "cancelled_at" integer,
    "created_at" integer NOT NULL,
    "finished_at" integer
);


ALTER TABLE "public"."job_batches" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."jobs" (
    "id" bigint NOT NULL,
    "queue" character varying(255) NOT NULL,
    "payload" "text" NOT NULL,
    "attempts" smallint NOT NULL,
    "reserved_at" integer,
    "available_at" integer NOT NULL,
    "created_at" integer NOT NULL
);


ALTER TABLE "public"."jobs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."jobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."jobs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."jobs_id_seq" OWNED BY "public"."jobs"."id";



CREATE TABLE IF NOT EXISTS "public"."migrations" (
    "id" integer NOT NULL,
    "migration" character varying(255) NOT NULL,
    "batch" integer NOT NULL
);


ALTER TABLE "public"."migrations" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."migrations_id_seq"
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."migrations_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."migrations_id_seq" OWNED BY "public"."migrations"."id";



CREATE TABLE IF NOT EXISTS "public"."participacion_repositorios" (
    "id_participacion_repositorio" bigint NOT NULL,
    "id_participacion" bigint NOT NULL,
    "id_proyecto_repositorio" bigint NOT NULL,
    "validado" boolean DEFAULT false NOT NULL,
    "es_propietario" boolean DEFAULT false NOT NULL,
    "validado_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."participacion_repositorios" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."participacion_repositorios_id_participacion_repositorio_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."participacion_repositorios_id_participacion_repositorio_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."participacion_repositorios_id_participacion_repositorio_seq" OWNED BY "public"."participacion_repositorios"."id_participacion_repositorio";



CREATE TABLE IF NOT EXISTS "public"."participaciones" (
    "id_participacion" bigint NOT NULL,
    "id_usuario" bigint NOT NULL,
    "id_proyecto" bigint NOT NULL,
    "rol" character varying(100),
    "descripcion_aporte" "text",
    "es_propietario" boolean DEFAULT false NOT NULL,
    "visibilidad" character varying(255) DEFAULT 'publico'::character varying NOT NULL,
    "estado_participacion" character varying(255) DEFAULT 'activo'::character varying NOT NULL,
    "fecha_inicio" "date",
    "fecha_fin" "date",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "deleted_at" timestamp(0) without time zone,
    "participacion_validada" boolean DEFAULT false NOT NULL,
    CONSTRAINT "participaciones_estado_participacion_check" CHECK ((("estado_participacion")::"text" = ANY ((ARRAY['activo'::character varying, 'finalizado'::character varying, 'retirado'::character varying, 'pendiente'::character varying])::"text"[]))),
    CONSTRAINT "participaciones_visibilidad_check" CHECK ((("visibilidad")::"text" = ANY ((ARRAY['publico'::character varying, 'privado'::character varying])::"text"[])))
);


ALTER TABLE "public"."participaciones" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."participaciones_id_participacion_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."participaciones_id_participacion_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."participaciones_id_participacion_seq" OWNED BY "public"."participaciones"."id_participacion";



CREATE TABLE IF NOT EXISTS "public"."password_reset_tokens" (
    "email" character varying(255) NOT NULL,
    "token" character varying(255) NOT NULL,
    "created_at" timestamp(0) without time zone
);


ALTER TABLE "public"."password_reset_tokens" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."perfiles" (
    "id_perfil" bigint NOT NULL,
    "usuario_id" bigint NOT NULL,
    "biografia" "text",
    "ciudad" character varying(255),
    "pais" character varying(255),
    "profesion" character varying(255),
    "foto_perfil" character varying(255),
    "foto_fondo" character varying(255),
    "es_publico" boolean DEFAULT true NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."perfiles" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."perfiles_id_perfil_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."perfiles_id_perfil_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."perfiles_id_perfil_seq" OWNED BY "public"."perfiles"."id_perfil";



CREATE TABLE IF NOT EXISTS "public"."personal_access_tokens" (
    "id" bigint NOT NULL,
    "tokenable_type" character varying(255) DEFAULT 'App\Models\Usuario'::character varying NOT NULL,
    "tokenable_id" bigint NOT NULL,
    "name" "text" NOT NULL,
    "token" character varying(64) NOT NULL,
    "abilities" "text",
    "last_used_at" timestamp(0) without time zone,
    "expires_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."personal_access_tokens" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."personal_access_tokens_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."personal_access_tokens_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."personal_access_tokens_id_seq" OWNED BY "public"."personal_access_tokens"."id";



CREATE TABLE IF NOT EXISTS "public"."proyecto_evidencias" (
    "id_evidencia" bigint NOT NULL,
    "id_proyecto" bigint NOT NULL,
    "titulo" character varying(150) NOT NULL,
    "descripcion" "text",
    "tipo" character varying(255) DEFAULT 'otro'::character varying NOT NULL,
    "url" "text",
    "archivo_path" "text",
    "mime_type" character varying(100),
    "tamanio_bytes" bigint,
    "es_portada" boolean DEFAULT false NOT NULL,
    "es_visible" boolean DEFAULT true NOT NULL,
    "orden" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "deleted_at" timestamp(0) without time zone,
    CONSTRAINT "proyecto_evidencias_tipo_check" CHECK ((("tipo")::"text" = ANY ((ARRAY['imagen'::character varying, 'captura'::character varying, 'video'::character varying, 'pdf'::character varying, 'documento'::character varying, 'link'::character varying, 'repositorio'::character varying, 'demo'::character varying, 'documentacion'::character varying, 'figma'::character varying, 'presentacion'::character varying, 'api'::character varying, 'otro'::character varying])::"text"[])))
);


ALTER TABLE "public"."proyecto_evidencias" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."proyecto_evidencias_id_evidencia_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."proyecto_evidencias_id_evidencia_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."proyecto_evidencias_id_evidencia_seq" OWNED BY "public"."proyecto_evidencias"."id_evidencia";



CREATE TABLE IF NOT EXISTS "public"."proyecto_repositorios" (
    "id_proyecto_repositorio" bigint NOT NULL,
    "id_proyecto" bigint,
    "nombre" character varying(150),
    "tipo" character varying(255) DEFAULT 'otro'::character varying NOT NULL,
    "proveedor" character varying(255) DEFAULT 'github'::character varying NOT NULL,
    "url_repositorio" "text",
    "descripcion" "text",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "deleted_at" timestamp(0) without time zone,
    CONSTRAINT "proyecto_repositorios_proveedor_check" CHECK ((("proveedor")::"text" = ANY ((ARRAY['github'::character varying, 'gitlab'::character varying, 'bitbucket'::character varying, 'manual'::character varying, 'otro'::character varying])::"text"[]))),
    CONSTRAINT "proyecto_repositorios_tipo_check" CHECK ((("tipo")::"text" = ANY ((ARRAY['backend'::character varying, 'frontend'::character varying, 'fullstack'::character varying, 'mobile'::character varying, 'desktop'::character varying, 'api'::character varying, 'docs'::character varying, 'infraestructura'::character varying, 'otro'::character varying])::"text"[])))
);


ALTER TABLE "public"."proyecto_repositorios" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."proyecto_repositorios_id_proyecto_repositorio_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."proyecto_repositorios_id_proyecto_repositorio_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."proyecto_repositorios_id_proyecto_repositorio_seq" OWNED BY "public"."proyecto_repositorios"."id_proyecto_repositorio";



CREATE TABLE IF NOT EXISTS "public"."proyectos" (
    "id_proyecto" bigint NOT NULL,
    "titulo" character varying(200) NOT NULL,
    "descripcion" "text",
    "plataforma_objetivo" character varying(255) DEFAULT 'sin_especificar'::character varying NOT NULL,
    "categoria_proyecto" character varying(255) DEFAULT 'sin_especificar'::character varying NOT NULL,
    "estado_publicacion" character varying(255) DEFAULT 'borrador'::character varying NOT NULL,
    "estado_desarrollo" character varying(255) DEFAULT 'sin_especificar'::character varying NOT NULL,
    "fecha_inicio" "date",
    "fecha_fin" "date",
    "origen" character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    "es_destacado" boolean DEFAULT false NOT NULL,
    "orden" integer DEFAULT 0 NOT NULL,
    "publicado_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "deleted_at" timestamp(0) without time zone,
    CONSTRAINT "proyectos_categoria_proyecto_check" CHECK ((("categoria_proyecto")::"text" = ANY ((ARRAY['sin_especificar'::character varying, 'portafolio'::character varying, 'educativo'::character varying, 'financiero'::character varying, 'ecommerce'::character varying, 'marketplace'::character varying, 'videojuego'::character varying, 'salud'::character varying, 'administrativo'::character varying, 'red_social'::character varying, 'dashboard_bi'::character varying, 'gestion_empresarial'::character varying, 'productividad'::character varying, 'seguridad'::character varying, 'entretenimiento'::character varying, 'herramienta_desarrollo'::character varying, 'otro'::character varying])::"text"[]))),
    CONSTRAINT "proyectos_estado_desarrollo_check" CHECK ((("estado_desarrollo")::"text" = ANY ((ARRAY['sin_especificar'::character varying, 'en_desarrollo'::character varying, 'pausado'::character varying, 'terminado'::character varying, 'mantenimiento'::character varying, 'versionado'::character varying, 'cancelado'::character varying])::"text"[]))),
    CONSTRAINT "proyectos_estado_publicacion_check" CHECK ((("estado_publicacion")::"text" = ANY ((ARRAY['borrador'::character varying, 'publicado'::character varying, 'archivado'::character varying])::"text"[]))),
    CONSTRAINT "proyectos_origen_check" CHECK ((("origen")::"text" = ANY ((ARRAY['manual'::character varying, 'github'::character varying, 'manual_github_editado'::character varying])::"text"[]))),
    CONSTRAINT "proyectos_plataforma_objetivo_check" CHECK ((("plataforma_objetivo")::"text" = ANY ((ARRAY['sin_especificar'::character varying, 'web'::character varying, 'movil'::character varying, 'web_movil'::character varying, 'escritorio'::character varying, 'multiplataforma'::character varying, 'api_backend'::character varying, 'datos_ml'::character varying, 'iot'::character varying, 'cli'::character varying, 'otro'::character varying])::"text"[])))
);


ALTER TABLE "public"."proyectos" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."proyectos_id_proyecto_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."proyectos_id_proyecto_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."proyectos_id_proyecto_seq" OWNED BY "public"."proyectos"."id_proyecto";



CREATE TABLE IF NOT EXISTS "public"."repositorio_github" (
    "id_repositorio_github" bigint NOT NULL,
    "id_proyecto_repositorio" bigint NOT NULL,
    "github_repo_id" bigint,
    "github_owner" character varying(100),
    "github_repo_name" character varying(150),
    "github_description" "text",
    "github_homepage" "text",
    "default_branch" character varying(100),
    "is_private" boolean DEFAULT false NOT NULL,
    "is_fork" boolean DEFAULT false NOT NULL,
    "is_archived" boolean DEFAULT false NOT NULL,
    "stars_count" integer DEFAULT 0 NOT NULL,
    "forks_count" integer DEFAULT 0 NOT NULL,
    "open_issues_count" integer DEFAULT 0 NOT NULL,
    "commits_count" integer DEFAULT 0 NOT NULL,
    "contributors_count" integer DEFAULT 0 NOT NULL,
    "last_commit_message" "text",
    "last_commit_date" timestamp(0) without time zone,
    "last_push_at" timestamp(0) without time zone,
    "repo_created_at" timestamp(0) without time zone,
    "repo_updated_at" timestamp(0) without time zone,
    "readme_resumen" "text",
    "sync_status" character varying(255) DEFAULT 'pendiente'::character varying NOT NULL,
    "sync_error" "text",
    "last_sync_at" timestamp(0) without time zone,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "repositorio_github_sync_status_check" CHECK ((("sync_status")::"text" = ANY ((ARRAY['pendiente'::character varying, 'sincronizado'::character varying, 'error'::character varying])::"text"[])))
);


ALTER TABLE "public"."repositorio_github" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."repositorio_github_id_repositorio_github_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."repositorio_github_id_repositorio_github_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."repositorio_github_id_repositorio_github_seq" OWNED BY "public"."repositorio_github"."id_repositorio_github";



CREATE TABLE IF NOT EXISTS "public"."sesion_base" (
    "id_rastreo_interno" integer NOT NULL,
    "session_token" character varying(64) NOT NULL,
    "ip_address" character varying(45),
    "isp_proveedor" character varying(100),
    "pais_codigo" character(2),
    "navegador_nombre" character varying(50),
    "navegador_version" character varying(20),
    "sistema_operativo" character varying(50),
    "es_movil" boolean,
    "resolucion_pantalla" character varying(15),
    "idioma_preferido" character varying(10),
    "zona_horaria" character varying(50),
    "fuente_url" "text",
    "pagina_entrada" "text",
    "fecha_ingreso" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "ultima_actividad" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "consentimiento_legal" boolean DEFAULT false NOT NULL,
    "usuario_id" bigint,
    "personal_access_token_id" bigint,
    "token_recuperacion_id" bigint,
    "bitacora_id" bigint
);


ALTER TABLE "public"."sesion_base" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."sesion_base_id_rastreo_interno_seq"
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."sesion_base_id_rastreo_interno_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."sesion_base_id_rastreo_interno_seq" OWNED BY "public"."sesion_base"."id_rastreo_interno";



CREATE TABLE IF NOT EXISTS "public"."sesion_hardware" (
    "id" bigint NOT NULL,
    "id_rastreo_base" integer NOT NULL,
    "gpu_renderer" character varying(255),
    "cpu_nucleos" smallint,
    "ram_estimada" numeric(5,2),
    "hdr_soporte" boolean,
    "bateria_nivel" character varying(20),
    "uuid_persistente" "uuid" NOT NULL,
    "consentimiento_fecha" timestamp(0) without time zone,
    "consentimiento_version" character varying(20),
    "consentimiento_ip" character varying(45),
    "consentimiento_user_agent" "text",
    "consentimiento_firma" character(64),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone
);


ALTER TABLE "public"."sesion_hardware" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."sesion_hardware_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."sesion_hardware_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."sesion_hardware_id_seq" OWNED BY "public"."sesion_hardware"."id";



CREATE TABLE IF NOT EXISTS "public"."sessions" (
    "id" character varying(255) NOT NULL,
    "user_id" bigint,
    "ip_address" character varying(45),
    "user_agent" "text",
    "payload" "text" NOT NULL,
    "last_activity" integer NOT NULL
);


ALTER TABLE "public"."sessions" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "public"."tecnologias" (
    "id_tecnologia" bigint NOT NULL,
    "nombre" character varying(100) NOT NULL,
    "tipo" character varying(255) DEFAULT 'otro'::character varying NOT NULL,
    "icono_url" "text",
    "color" character varying(20),
    "descripcion" "text",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "deleted_at" timestamp(0) without time zone,
    CONSTRAINT "tecnologias_tipo_check" CHECK ((("tipo")::"text" = ANY ((ARRAY['lenguaje'::character varying, 'framework'::character varying, 'libreria'::character varying, 'base_datos'::character varying, 'herramienta'::character varying, 'servicio'::character varying, 'plataforma'::character varying, 'otro'::character varying])::"text"[])))
);


ALTER TABLE "public"."tecnologias" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."tecnologias_id_tecnologia_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."tecnologias_id_tecnologia_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."tecnologias_id_tecnologia_seq" OWNED BY "public"."tecnologias"."id_tecnologia";



CREATE TABLE IF NOT EXISTS "public"."token_recuperaciones" (
    "id_tokenR" bigint NOT NULL,
    "usuario_id" bigint NOT NULL,
    "token_hash" character varying(255) NOT NULL,
    "estado" character varying(255) NOT NULL,
    "fecha_expiracion" timestamp(0) without time zone NOT NULL,
    "fecha_creacion" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT "token_recuperaciones_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['inactivo'::character varying, 'activo'::character varying, 'usado'::character varying, 'expirado'::character varying])::"text"[])))
);


ALTER TABLE "public"."token_recuperaciones" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."token_recuperaciones_id_tokenR_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."token_recuperaciones_id_tokenR_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."token_recuperaciones_id_tokenR_seq" OWNED BY "public"."token_recuperaciones"."id_tokenR";



CREATE TABLE IF NOT EXISTS "public"."uso_tecnologias" (
    "id_uso_tecnologia" bigint NOT NULL,
    "id_proyecto" bigint NOT NULL,
    "id_tecnologia" bigint NOT NULL,
    "version_usada" character varying(80),
    "porcentaje_uso" numeric(5,2),
    "es_principal" boolean DEFAULT false NOT NULL,
    "es_visible" boolean DEFAULT true NOT NULL,
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    "deleted_at" timestamp(0) without time zone
);


ALTER TABLE "public"."uso_tecnologias" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."uso_tecnologias_id_uso_tecnologia_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."uso_tecnologias_id_uso_tecnologia_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."uso_tecnologias_id_uso_tecnologia_seq" OWNED BY "public"."uso_tecnologias"."id_uso_tecnologia";



CREATE TABLE IF NOT EXISTS "public"."usuario_repositorio_validaciones" (
    "id_usuario_repositorio_validacion" bigint NOT NULL,
    "id_usuario" bigint NOT NULL,
    "id_repositorio_github" bigint NOT NULL,
    "id_cuenta_oauth" bigint,
    "relacion_github" character varying(255) DEFAULT 'unknown'::character varying NOT NULL,
    "es_propietario" boolean DEFAULT false NOT NULL,
    "validado" boolean DEFAULT false NOT NULL,
    "validado_at" timestamp(0) without time zone,
    "ultima_verificacion_at" timestamp(0) without time zone,
    "permisos_github" json,
    "detalle_validacion" "text",
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "usuario_repositorio_validaciones_relacion_github_check" CHECK ((("relacion_github")::"text" = ANY ((ARRAY['owner'::character varying, 'collaborator'::character varying, 'contributor'::character varying, 'member'::character varying, 'maintainer'::character varying, 'unknown'::character varying])::"text"[])))
);


ALTER TABLE "public"."usuario_repositorio_validaciones" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq" OWNED BY "public"."usuario_repositorio_validaciones"."id_usuario_repositorio_validacion";



CREATE TABLE IF NOT EXISTS "public"."usuarios" (
    "id_usuario" bigint NOT NULL,
    "nombre" character varying(255) NOT NULL,
    "apellido" character varying(255) NOT NULL,
    "correo" character varying(255) NOT NULL,
    "password" character varying(255) NOT NULL,
    "telefono" character varying(255),
    "rol" character varying(255) NOT NULL,
    "estado" character varying(255) NOT NULL,
    "intentos_fallidos" integer DEFAULT 0 NOT NULL,
    "fecha_bloqueo" timestamp(0) without time zone,
    "proveedor_oauth" character varying(255),
    "oauth_id" character varying(255),
    "idioma_preferido" character varying(255),
    "created_at" timestamp(0) without time zone,
    "updated_at" timestamp(0) without time zone,
    CONSTRAINT "usuarios_estado_check" CHECK ((("estado")::"text" = ANY ((ARRAY['activo'::character varying, 'bloqueado'::character varying])::"text"[]))),
    CONSTRAINT "usuarios_proveedor_oauth_check" CHECK ((("proveedor_oauth")::"text" = ANY ((ARRAY['google'::character varying, 'facebook'::character varying])::"text"[]))),
    CONSTRAINT "usuarios_rol_check" CHECK ((("rol")::"text" = ANY ((ARRAY['admin'::character varying, 'usuario'::character varying])::"text"[])))
);


ALTER TABLE "public"."usuarios" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."usuarios_id_usuario_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."usuarios_id_usuario_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."usuarios_id_usuario_seq" OWNED BY "public"."usuarios"."id_usuario";



CREATE TABLE IF NOT EXISTS "public"."visibilidad_campos" (
    "id_visibilidad" bigint NOT NULL,
    "usuario_id" bigint NOT NULL,
    "campo" character varying(255) NOT NULL,
    "visible" boolean DEFAULT true NOT NULL,
    CONSTRAINT "visibilidad_campos_campo_check" CHECK ((("campo")::"text" = ANY ((ARRAY['correo'::character varying, 'telefono'::character varying, 'pais'::character varying, 'biografia'::character varying, 'ciudad'::character varying, 'linkedin'::character varying, 'github'::character varying, 'profesion'::character varying])::"text"[])))
);


ALTER TABLE "public"."visibilidad_campos" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."visibilidad_campos_id_visibilidad_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."visibilidad_campos_id_visibilidad_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."visibilidad_campos_id_visibilidad_seq" OWNED BY "public"."visibilidad_campos"."id_visibilidad";



ALTER TABLE ONLY "public"."bitacoras" ALTER COLUMN "id_bitacora" SET DEFAULT "nextval"('"public"."bitacoras_id_bitacora_seq"'::"regclass");



ALTER TABLE ONLY "public"."cuentas_oauth" ALTER COLUMN "id_cuenta_oauth" SET DEFAULT "nextval"('"public"."cuentas_oauth_id_cuenta_oauth_seq"'::"regclass");



ALTER TABLE ONLY "public"."enlaces" ALTER COLUMN "id_enlace" SET DEFAULT "nextval"('"public"."enlaces_id_enlace_seq"'::"regclass");



ALTER TABLE ONLY "public"."experiencias" ALTER COLUMN "id_experiencia" SET DEFAULT "nextval"('"public"."experiencias_id_experiencia_seq"'::"regclass");



ALTER TABLE ONLY "public"."failed_jobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."failed_jobs_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."habilidades" ALTER COLUMN "id_habilidad" SET DEFAULT "nextval"('"public"."habilidades_id_habilidad_seq"'::"regclass");



ALTER TABLE ONLY "public"."habilidades_usuario" ALTER COLUMN "id_habilidad_usuario" SET DEFAULT "nextval"('"public"."habilidades_usuario_id_habilidad_usuario_seq"'::"regclass");



ALTER TABLE ONLY "public"."jobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."jobs_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."migrations" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."migrations_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."participacion_repositorios" ALTER COLUMN "id_participacion_repositorio" SET DEFAULT "nextval"('"public"."participacion_repositorios_id_participacion_repositorio_seq"'::"regclass");



ALTER TABLE ONLY "public"."participaciones" ALTER COLUMN "id_participacion" SET DEFAULT "nextval"('"public"."participaciones_id_participacion_seq"'::"regclass");



ALTER TABLE ONLY "public"."perfiles" ALTER COLUMN "id_perfil" SET DEFAULT "nextval"('"public"."perfiles_id_perfil_seq"'::"regclass");



ALTER TABLE ONLY "public"."personal_access_tokens" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."personal_access_tokens_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."proyecto_evidencias" ALTER COLUMN "id_evidencia" SET DEFAULT "nextval"('"public"."proyecto_evidencias_id_evidencia_seq"'::"regclass");



ALTER TABLE ONLY "public"."proyecto_repositorios" ALTER COLUMN "id_proyecto_repositorio" SET DEFAULT "nextval"('"public"."proyecto_repositorios_id_proyecto_repositorio_seq"'::"regclass");



ALTER TABLE ONLY "public"."proyectos" ALTER COLUMN "id_proyecto" SET DEFAULT "nextval"('"public"."proyectos_id_proyecto_seq"'::"regclass");



ALTER TABLE ONLY "public"."repositorio_github" ALTER COLUMN "id_repositorio_github" SET DEFAULT "nextval"('"public"."repositorio_github_id_repositorio_github_seq"'::"regclass");



ALTER TABLE ONLY "public"."sesion_base" ALTER COLUMN "id_rastreo_interno" SET DEFAULT "nextval"('"public"."sesion_base_id_rastreo_interno_seq"'::"regclass");



ALTER TABLE ONLY "public"."sesion_hardware" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."sesion_hardware_id_seq"'::"regclass");



ALTER TABLE ONLY "public"."tecnologias" ALTER COLUMN "id_tecnologia" SET DEFAULT "nextval"('"public"."tecnologias_id_tecnologia_seq"'::"regclass");



ALTER TABLE ONLY "public"."token_recuperaciones" ALTER COLUMN "id_tokenR" SET DEFAULT "nextval"('"public"."token_recuperaciones_id_tokenR_seq"'::"regclass");



ALTER TABLE ONLY "public"."uso_tecnologias" ALTER COLUMN "id_uso_tecnologia" SET DEFAULT "nextval"('"public"."uso_tecnologias_id_uso_tecnologia_seq"'::"regclass");



ALTER TABLE ONLY "public"."usuario_repositorio_validaciones" ALTER COLUMN "id_usuario_repositorio_validacion" SET DEFAULT "nextval"('"public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq"'::"regclass");



ALTER TABLE ONLY "public"."usuarios" ALTER COLUMN "id_usuario" SET DEFAULT "nextval"('"public"."usuarios_id_usuario_seq"'::"regclass");



ALTER TABLE ONLY "public"."visibilidad_campos" ALTER COLUMN "id_visibilidad" SET DEFAULT "nextval"('"public"."visibilidad_campos_id_visibilidad_seq"'::"regclass");



ALTER TABLE ONLY "public"."bitacoras"
    ADD CONSTRAINT "bitacoras_pkey" PRIMARY KEY ("id_bitacora");



ALTER TABLE ONLY "public"."cache_locks"
    ADD CONSTRAINT "cache_locks_pkey" PRIMARY KEY ("key");



ALTER TABLE ONLY "public"."cache"
    ADD CONSTRAINT "cache_pkey" PRIMARY KEY ("key");



ALTER TABLE ONLY "public"."cuentas_oauth"
    ADD CONSTRAINT "cuentas_oauth_pkey" PRIMARY KEY ("id_cuenta_oauth");



ALTER TABLE ONLY "public"."cuentas_oauth"
    ADD CONSTRAINT "cuentas_oauth_provider_provider_user_id_unique" UNIQUE ("provider", "provider_user_id");



ALTER TABLE ONLY "public"."cuentas_oauth"
    ADD CONSTRAINT "cuentas_oauth_usuario_provider_unique" UNIQUE ("usuario_id", "provider");



ALTER TABLE ONLY "public"."enlaces"
    ADD CONSTRAINT "enlaces_pkey" PRIMARY KEY ("id_enlace");



ALTER TABLE ONLY "public"."experiencias"
    ADD CONSTRAINT "experiencias_pkey" PRIMARY KEY ("id_experiencia");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_uuid_unique" UNIQUE ("uuid");



ALTER TABLE ONLY "public"."habilidades"
    ADD CONSTRAINT "habilidades_nombre_normalizado_unique" UNIQUE ("nombre_normalizado");



ALTER TABLE ONLY "public"."habilidades"
    ADD CONSTRAINT "habilidades_pkey" PRIMARY KEY ("id_habilidad");



ALTER TABLE ONLY "public"."habilidades_usuario"
    ADD CONSTRAINT "habilidades_usuario_pkey" PRIMARY KEY ("id_habilidad_usuario");



ALTER TABLE ONLY "public"."habilidades_usuario"
    ADD CONSTRAINT "habilidades_usuario_usuario_id_habilidad_id_unique" UNIQUE ("usuario_id", "habilidad_id");



ALTER TABLE ONLY "public"."job_batches"
    ADD CONSTRAINT "job_batches_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."jobs"
    ADD CONSTRAINT "jobs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."migrations"
    ADD CONSTRAINT "migrations_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."participacion_repositorios"
    ADD CONSTRAINT "participacion_repositorios_pkey" PRIMARY KEY ("id_participacion_repositorio");



ALTER TABLE ONLY "public"."participaciones"
    ADD CONSTRAINT "participaciones_id_usuario_id_proyecto_unique" UNIQUE ("id_usuario", "id_proyecto");



ALTER TABLE ONLY "public"."participaciones"
    ADD CONSTRAINT "participaciones_pkey" PRIMARY KEY ("id_participacion");



ALTER TABLE ONLY "public"."password_reset_tokens"
    ADD CONSTRAINT "password_reset_tokens_pkey" PRIMARY KEY ("email");



ALTER TABLE ONLY "public"."perfiles"
    ADD CONSTRAINT "perfiles_pkey" PRIMARY KEY ("id_perfil");



ALTER TABLE ONLY "public"."perfiles"
    ADD CONSTRAINT "perfiles_usuario_id_unique" UNIQUE ("usuario_id");



ALTER TABLE ONLY "public"."personal_access_tokens"
    ADD CONSTRAINT "personal_access_tokens_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."personal_access_tokens"
    ADD CONSTRAINT "personal_access_tokens_token_unique" UNIQUE ("token");



ALTER TABLE ONLY "public"."proyecto_evidencias"
    ADD CONSTRAINT "proyecto_evidencias_pkey" PRIMARY KEY ("id_evidencia");



ALTER TABLE ONLY "public"."proyecto_repositorios"
    ADD CONSTRAINT "proyecto_repositorios_pkey" PRIMARY KEY ("id_proyecto_repositorio");



ALTER TABLE ONLY "public"."proyectos"
    ADD CONSTRAINT "proyectos_pkey" PRIMARY KEY ("id_proyecto");



ALTER TABLE ONLY "public"."repositorio_github"
    ADD CONSTRAINT "repositorio_github_id_proyecto_repositorio_unique" UNIQUE ("id_proyecto_repositorio");



ALTER TABLE ONLY "public"."repositorio_github"
    ADD CONSTRAINT "repositorio_github_pkey" PRIMARY KEY ("id_repositorio_github");



ALTER TABLE ONLY "public"."sesion_base"
    ADD CONSTRAINT "sesion_base_pkey" PRIMARY KEY ("id_rastreo_interno");



ALTER TABLE ONLY "public"."sesion_base"
    ADD CONSTRAINT "sesion_base_session_token_unique" UNIQUE ("session_token");



ALTER TABLE ONLY "public"."sesion_hardware"
    ADD CONSTRAINT "sesion_hardware_id_rastreo_base_unique" UNIQUE ("id_rastreo_base");



ALTER TABLE ONLY "public"."sesion_hardware"
    ADD CONSTRAINT "sesion_hardware_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."sessions"
    ADD CONSTRAINT "sessions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."tecnologias"
    ADD CONSTRAINT "tecnologias_nombre_unique" UNIQUE ("nombre");



ALTER TABLE ONLY "public"."tecnologias"
    ADD CONSTRAINT "tecnologias_pkey" PRIMARY KEY ("id_tecnologia");



ALTER TABLE ONLY "public"."token_recuperaciones"
    ADD CONSTRAINT "token_recuperaciones_pkey" PRIMARY KEY ("id_tokenR");



ALTER TABLE ONLY "public"."participacion_repositorios"
    ADD CONSTRAINT "uq_participacion_repo" UNIQUE ("id_participacion", "id_proyecto_repositorio");



ALTER TABLE ONLY "public"."usuario_repositorio_validaciones"
    ADD CONSTRAINT "uq_usuario_repositorio_validaciones_usuario_repo" UNIQUE ("id_usuario", "id_repositorio_github");



ALTER TABLE ONLY "public"."uso_tecnologias"
    ADD CONSTRAINT "uso_tecnologias_id_proyecto_id_tecnologia_unique" UNIQUE ("id_proyecto", "id_tecnologia");



ALTER TABLE ONLY "public"."uso_tecnologias"
    ADD CONSTRAINT "uso_tecnologias_pkey" PRIMARY KEY ("id_uso_tecnologia");



ALTER TABLE ONLY "public"."usuario_repositorio_validaciones"
    ADD CONSTRAINT "usuario_repositorio_validaciones_pkey" PRIMARY KEY ("id_usuario_repositorio_validacion");



ALTER TABLE ONLY "public"."usuarios"
    ADD CONSTRAINT "usuarios_correo_unique" UNIQUE ("correo");



ALTER TABLE ONLY "public"."usuarios"
    ADD CONSTRAINT "usuarios_pkey" PRIMARY KEY ("id_usuario");



ALTER TABLE ONLY "public"."visibilidad_campos"
    ADD CONSTRAINT "visibilidad_campos_pkey" PRIMARY KEY ("id_visibilidad");



ALTER TABLE ONLY "public"."visibilidad_campos"
    ADD CONSTRAINT "visibilidad_campos_usuario_id_campo_unique" UNIQUE ("usuario_id", "campo");



CREATE INDEX "bitacoras_accion_index" ON "public"."bitacoras" USING "btree" ("accion");



CREATE INDEX "bitacoras_fecha_index" ON "public"."bitacoras" USING "btree" ("fecha");



CREATE INDEX "bitacoras_usuario_id_index" ON "public"."bitacoras" USING "btree" ("usuario_id");



CREATE INDEX "bitacoras_usuario_referencia_id_index" ON "public"."bitacoras" USING "btree" ("usuario_referencia_id");



CREATE INDEX "idx_enlaces_id_usuario" ON "public"."enlaces" USING "btree" ("id_usuario");



CREATE INDEX "ix_usuario_repositorio_validaciones_ultima_verificacion" ON "public"."usuario_repositorio_validaciones" USING "btree" ("ultima_verificacion_at");



CREATE INDEX "ix_usuario_repositorio_validaciones_usuario_validado" ON "public"."usuario_repositorio_validaciones" USING "btree" ("id_usuario", "validado");



CREATE INDEX "jobs_queue_index" ON "public"."jobs" USING "btree" ("queue");



CREATE INDEX "personal_access_tokens_expires_at_index" ON "public"."personal_access_tokens" USING "btree" ("expires_at");



CREATE INDEX "sesion_hardware_consentimiento_firma_index" ON "public"."sesion_hardware" USING "btree" ("consentimiento_firma");



CREATE INDEX "sesion_hardware_uuid_persistente_index" ON "public"."sesion_hardware" USING "btree" ("uuid_persistente");



CREATE INDEX "sessions_last_activity_index" ON "public"."sessions" USING "btree" ("last_activity");



CREATE INDEX "sessions_user_id_index" ON "public"."sessions" USING "btree" ("user_id");



CREATE OR REPLACE TRIGGER "trg_bitacora_cierre_sesion" AFTER DELETE ON "public"."personal_access_tokens" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_cierre_sesion"();



CREATE OR REPLACE TRIGGER "trg_bitacora_inicio_sesion" AFTER INSERT ON "public"."personal_access_tokens" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_inicio_sesion"();



CREATE OR REPLACE TRIGGER "trg_bitacora_perfil" AFTER INSERT OR DELETE OR UPDATE ON "public"."perfiles" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_perfil"();



CREATE OR REPLACE TRIGGER "trg_bitacora_token_recuperacion_actualizado" AFTER UPDATE ON "public"."token_recuperaciones" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_token_recuperacion_actualizado"();



CREATE OR REPLACE TRIGGER "trg_bitacora_token_recuperacion_solicitado" AFTER INSERT ON "public"."token_recuperaciones" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_token_recuperacion_solicitado"();



CREATE OR REPLACE TRIGGER "trg_bitacora_usuarios" AFTER INSERT OR DELETE OR UPDATE ON "public"."usuarios" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_usuarios"();



CREATE OR REPLACE TRIGGER "trg_bitacora_visibilidad" AFTER INSERT OR DELETE OR UPDATE ON "public"."visibilidad_campos" FOR EACH ROW EXECUTE FUNCTION "public"."fn_bitacora_visibilidad"();



ALTER TABLE ONLY "public"."bitacoras"
    ADD CONSTRAINT "bitacoras_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."cuentas_oauth"
    ADD CONSTRAINT "cuentas_oauth_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."enlaces"
    ADD CONSTRAINT "enlaces_id_usuario_foreign" FOREIGN KEY ("id_usuario") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."experiencias"
    ADD CONSTRAINT "experiencias_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."habilidades_usuario"
    ADD CONSTRAINT "habilidades_usuario_habilidad_id_foreign" FOREIGN KEY ("habilidad_id") REFERENCES "public"."habilidades"("id_habilidad") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."habilidades_usuario"
    ADD CONSTRAINT "habilidades_usuario_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."participacion_repositorios"
    ADD CONSTRAINT "participacion_repositorios_id_participacion_foreign" FOREIGN KEY ("id_participacion") REFERENCES "public"."participaciones"("id_participacion") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."participacion_repositorios"
    ADD CONSTRAINT "participacion_repositorios_id_proyecto_repositorio_foreign" FOREIGN KEY ("id_proyecto_repositorio") REFERENCES "public"."proyecto_repositorios"("id_proyecto_repositorio") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."participaciones"
    ADD CONSTRAINT "participaciones_id_proyecto_foreign" FOREIGN KEY ("id_proyecto") REFERENCES "public"."proyectos"("id_proyecto") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."participaciones"
    ADD CONSTRAINT "participaciones_id_usuario_foreign" FOREIGN KEY ("id_usuario") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."perfiles"
    ADD CONSTRAINT "perfiles_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."personal_access_tokens"
    ADD CONSTRAINT "personal_access_tokens_tokenable_id_foreign" FOREIGN KEY ("tokenable_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."proyecto_evidencias"
    ADD CONSTRAINT "proyecto_evidencias_id_proyecto_foreign" FOREIGN KEY ("id_proyecto") REFERENCES "public"."proyectos"("id_proyecto") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."proyecto_repositorios"
    ADD CONSTRAINT "proyecto_repositorios_id_proyecto_foreign" FOREIGN KEY ("id_proyecto") REFERENCES "public"."proyectos"("id_proyecto") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."repositorio_github"
    ADD CONSTRAINT "repositorio_github_id_proyecto_repositorio_foreign" FOREIGN KEY ("id_proyecto_repositorio") REFERENCES "public"."proyecto_repositorios"("id_proyecto_repositorio") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."sesion_base"
    ADD CONSTRAINT "sesion_base_bitacora_id_foreign" FOREIGN KEY ("bitacora_id") REFERENCES "public"."bitacoras"("id_bitacora") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."sesion_base"
    ADD CONSTRAINT "sesion_base_personal_access_token_id_foreign" FOREIGN KEY ("personal_access_token_id") REFERENCES "public"."personal_access_tokens"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."sesion_base"
    ADD CONSTRAINT "sesion_base_token_recuperacion_id_foreign" FOREIGN KEY ("token_recuperacion_id") REFERENCES "public"."token_recuperaciones"("id_tokenR") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."sesion_base"
    ADD CONSTRAINT "sesion_base_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."sesion_hardware"
    ADD CONSTRAINT "sesion_hardware_id_rastreo_base_foreign" FOREIGN KEY ("id_rastreo_base") REFERENCES "public"."sesion_base"("id_rastreo_interno") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."token_recuperaciones"
    ADD CONSTRAINT "token_recuperaciones_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."uso_tecnologias"
    ADD CONSTRAINT "uso_tecnologias_id_proyecto_foreign" FOREIGN KEY ("id_proyecto") REFERENCES "public"."proyectos"("id_proyecto") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."uso_tecnologias"
    ADD CONSTRAINT "uso_tecnologias_id_tecnologia_foreign" FOREIGN KEY ("id_tecnologia") REFERENCES "public"."tecnologias"("id_tecnologia") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."usuario_repositorio_validaciones"
    ADD CONSTRAINT "usuario_repositorio_validaciones_id_cuenta_oauth_foreign" FOREIGN KEY ("id_cuenta_oauth") REFERENCES "public"."cuentas_oauth"("id_cuenta_oauth") ON DELETE SET NULL;



ALTER TABLE ONLY "public"."usuario_repositorio_validaciones"
    ADD CONSTRAINT "usuario_repositorio_validaciones_id_repositorio_github_foreign" FOREIGN KEY ("id_repositorio_github") REFERENCES "public"."repositorio_github"("id_repositorio_github") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."usuario_repositorio_validaciones"
    ADD CONSTRAINT "usuario_repositorio_validaciones_id_usuario_foreign" FOREIGN KEY ("id_usuario") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE ONLY "public"."visibilidad_campos"
    ADD CONSTRAINT "visibilidad_campos_usuario_id_foreign" FOREIGN KEY ("usuario_id") REFERENCES "public"."usuarios"("id_usuario") ON DELETE CASCADE;



ALTER TABLE "public"."bitacoras" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."cache" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."cache_locks" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."cuentas_oauth" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."enlaces" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."experiencias" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."failed_jobs" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."habilidades" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."habilidades_usuario" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."job_batches" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."jobs" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."migrations" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."participacion_repositorios" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."participaciones" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."password_reset_tokens" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."perfiles" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."personal_access_tokens" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."proyecto_evidencias" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."proyecto_repositorios" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."proyectos" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."repositorio_github" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."sesion_base" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."sesion_hardware" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."sessions" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."tecnologias" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."token_recuperaciones" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."uso_tecnologias" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."usuario_repositorio_validaciones" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."usuarios" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "public"."visibilidad_campos" ENABLE ROW LEVEL SECURITY;


GRANT USAGE ON SCHEMA "public" TO "postgres";
GRANT USAGE ON SCHEMA "public" TO "anon";
GRANT USAGE ON SCHEMA "public" TO "authenticated";
GRANT USAGE ON SCHEMA "public" TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_cierre_sesion"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_cierre_sesion"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_cierre_sesion"() TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_inicio_sesion"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_inicio_sesion"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_inicio_sesion"() TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_perfil"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_perfil"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_perfil"() TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_token_recuperacion_actualizado"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_token_recuperacion_actualizado"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_token_recuperacion_actualizado"() TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_token_recuperacion_solicitado"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_token_recuperacion_solicitado"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_token_recuperacion_solicitado"() TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_usuarios"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_usuarios"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_usuarios"() TO "service_role";



GRANT ALL ON FUNCTION "public"."fn_bitacora_visibilidad"() TO "anon";
GRANT ALL ON FUNCTION "public"."fn_bitacora_visibilidad"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."fn_bitacora_visibilidad"() TO "service_role";



GRANT ALL ON FUNCTION "public"."rls_auto_enable"() TO "anon";
GRANT ALL ON FUNCTION "public"."rls_auto_enable"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."rls_auto_enable"() TO "service_role";



GRANT ALL ON TABLE "public"."bitacoras" TO "anon";
GRANT ALL ON TABLE "public"."bitacoras" TO "authenticated";
GRANT ALL ON TABLE "public"."bitacoras" TO "service_role";



GRANT ALL ON SEQUENCE "public"."bitacoras_id_bitacora_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."bitacoras_id_bitacora_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."bitacoras_id_bitacora_seq" TO "service_role";



GRANT ALL ON TABLE "public"."cache" TO "anon";
GRANT ALL ON TABLE "public"."cache" TO "authenticated";
GRANT ALL ON TABLE "public"."cache" TO "service_role";



GRANT ALL ON TABLE "public"."cache_locks" TO "anon";
GRANT ALL ON TABLE "public"."cache_locks" TO "authenticated";
GRANT ALL ON TABLE "public"."cache_locks" TO "service_role";



GRANT ALL ON TABLE "public"."cuentas_oauth" TO "anon";
GRANT ALL ON TABLE "public"."cuentas_oauth" TO "authenticated";
GRANT ALL ON TABLE "public"."cuentas_oauth" TO "service_role";



GRANT ALL ON SEQUENCE "public"."cuentas_oauth_id_cuenta_oauth_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."cuentas_oauth_id_cuenta_oauth_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."cuentas_oauth_id_cuenta_oauth_seq" TO "service_role";



GRANT ALL ON TABLE "public"."enlaces" TO "anon";
GRANT ALL ON TABLE "public"."enlaces" TO "authenticated";
GRANT ALL ON TABLE "public"."enlaces" TO "service_role";



GRANT ALL ON SEQUENCE "public"."enlaces_id_enlace_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."enlaces_id_enlace_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."enlaces_id_enlace_seq" TO "service_role";



GRANT ALL ON TABLE "public"."experiencias" TO "anon";
GRANT ALL ON TABLE "public"."experiencias" TO "authenticated";
GRANT ALL ON TABLE "public"."experiencias" TO "service_role";



GRANT ALL ON SEQUENCE "public"."experiencias_id_experiencia_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."experiencias_id_experiencia_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."experiencias_id_experiencia_seq" TO "service_role";



GRANT ALL ON TABLE "public"."failed_jobs" TO "anon";
GRANT ALL ON TABLE "public"."failed_jobs" TO "authenticated";
GRANT ALL ON TABLE "public"."failed_jobs" TO "service_role";



GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."habilidades" TO "anon";
GRANT ALL ON TABLE "public"."habilidades" TO "authenticated";
GRANT ALL ON TABLE "public"."habilidades" TO "service_role";



GRANT ALL ON SEQUENCE "public"."habilidades_id_habilidad_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."habilidades_id_habilidad_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."habilidades_id_habilidad_seq" TO "service_role";



GRANT ALL ON TABLE "public"."habilidades_usuario" TO "anon";
GRANT ALL ON TABLE "public"."habilidades_usuario" TO "authenticated";
GRANT ALL ON TABLE "public"."habilidades_usuario" TO "service_role";



GRANT ALL ON SEQUENCE "public"."habilidades_usuario_id_habilidad_usuario_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."habilidades_usuario_id_habilidad_usuario_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."habilidades_usuario_id_habilidad_usuario_seq" TO "service_role";



GRANT ALL ON TABLE "public"."job_batches" TO "anon";
GRANT ALL ON TABLE "public"."job_batches" TO "authenticated";
GRANT ALL ON TABLE "public"."job_batches" TO "service_role";



GRANT ALL ON TABLE "public"."jobs" TO "anon";
GRANT ALL ON TABLE "public"."jobs" TO "authenticated";
GRANT ALL ON TABLE "public"."jobs" TO "service_role";



GRANT ALL ON SEQUENCE "public"."jobs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."jobs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."jobs_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."migrations" TO "anon";
GRANT ALL ON TABLE "public"."migrations" TO "authenticated";
GRANT ALL ON TABLE "public"."migrations" TO "service_role";



GRANT ALL ON SEQUENCE "public"."migrations_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."migrations_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."migrations_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."participacion_repositorios" TO "anon";
GRANT ALL ON TABLE "public"."participacion_repositorios" TO "authenticated";
GRANT ALL ON TABLE "public"."participacion_repositorios" TO "service_role";



GRANT ALL ON SEQUENCE "public"."participacion_repositorios_id_participacion_repositorio_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."participacion_repositorios_id_participacion_repositorio_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."participacion_repositorios_id_participacion_repositorio_seq" TO "service_role";



GRANT ALL ON TABLE "public"."participaciones" TO "anon";
GRANT ALL ON TABLE "public"."participaciones" TO "authenticated";
GRANT ALL ON TABLE "public"."participaciones" TO "service_role";



GRANT ALL ON SEQUENCE "public"."participaciones_id_participacion_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."participaciones_id_participacion_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."participaciones_id_participacion_seq" TO "service_role";



GRANT ALL ON TABLE "public"."password_reset_tokens" TO "anon";
GRANT ALL ON TABLE "public"."password_reset_tokens" TO "authenticated";
GRANT ALL ON TABLE "public"."password_reset_tokens" TO "service_role";



GRANT ALL ON TABLE "public"."perfiles" TO "anon";
GRANT ALL ON TABLE "public"."perfiles" TO "authenticated";
GRANT ALL ON TABLE "public"."perfiles" TO "service_role";



GRANT ALL ON SEQUENCE "public"."perfiles_id_perfil_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."perfiles_id_perfil_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."perfiles_id_perfil_seq" TO "service_role";



GRANT ALL ON TABLE "public"."personal_access_tokens" TO "anon";
GRANT ALL ON TABLE "public"."personal_access_tokens" TO "authenticated";
GRANT ALL ON TABLE "public"."personal_access_tokens" TO "service_role";



GRANT ALL ON SEQUENCE "public"."personal_access_tokens_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."personal_access_tokens_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."personal_access_tokens_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."proyecto_evidencias" TO "anon";
GRANT ALL ON TABLE "public"."proyecto_evidencias" TO "authenticated";
GRANT ALL ON TABLE "public"."proyecto_evidencias" TO "service_role";



GRANT ALL ON SEQUENCE "public"."proyecto_evidencias_id_evidencia_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."proyecto_evidencias_id_evidencia_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."proyecto_evidencias_id_evidencia_seq" TO "service_role";



GRANT ALL ON TABLE "public"."proyecto_repositorios" TO "anon";
GRANT ALL ON TABLE "public"."proyecto_repositorios" TO "authenticated";
GRANT ALL ON TABLE "public"."proyecto_repositorios" TO "service_role";



GRANT ALL ON SEQUENCE "public"."proyecto_repositorios_id_proyecto_repositorio_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."proyecto_repositorios_id_proyecto_repositorio_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."proyecto_repositorios_id_proyecto_repositorio_seq" TO "service_role";



GRANT ALL ON TABLE "public"."proyectos" TO "anon";
GRANT ALL ON TABLE "public"."proyectos" TO "authenticated";
GRANT ALL ON TABLE "public"."proyectos" TO "service_role";



GRANT ALL ON SEQUENCE "public"."proyectos_id_proyecto_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."proyectos_id_proyecto_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."proyectos_id_proyecto_seq" TO "service_role";



GRANT ALL ON TABLE "public"."repositorio_github" TO "anon";
GRANT ALL ON TABLE "public"."repositorio_github" TO "authenticated";
GRANT ALL ON TABLE "public"."repositorio_github" TO "service_role";



GRANT ALL ON SEQUENCE "public"."repositorio_github_id_repositorio_github_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."repositorio_github_id_repositorio_github_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."repositorio_github_id_repositorio_github_seq" TO "service_role";



GRANT ALL ON TABLE "public"."sesion_base" TO "anon";
GRANT ALL ON TABLE "public"."sesion_base" TO "authenticated";
GRANT ALL ON TABLE "public"."sesion_base" TO "service_role";



GRANT ALL ON SEQUENCE "public"."sesion_base_id_rastreo_interno_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."sesion_base_id_rastreo_interno_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."sesion_base_id_rastreo_interno_seq" TO "service_role";



GRANT ALL ON TABLE "public"."sesion_hardware" TO "anon";
GRANT ALL ON TABLE "public"."sesion_hardware" TO "authenticated";
GRANT ALL ON TABLE "public"."sesion_hardware" TO "service_role";



GRANT ALL ON SEQUENCE "public"."sesion_hardware_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."sesion_hardware_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."sesion_hardware_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."sessions" TO "anon";
GRANT ALL ON TABLE "public"."sessions" TO "authenticated";
GRANT ALL ON TABLE "public"."sessions" TO "service_role";



GRANT ALL ON TABLE "public"."tecnologias" TO "anon";
GRANT ALL ON TABLE "public"."tecnologias" TO "authenticated";
GRANT ALL ON TABLE "public"."tecnologias" TO "service_role";



GRANT ALL ON SEQUENCE "public"."tecnologias_id_tecnologia_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."tecnologias_id_tecnologia_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."tecnologias_id_tecnologia_seq" TO "service_role";



GRANT ALL ON TABLE "public"."token_recuperaciones" TO "anon";
GRANT ALL ON TABLE "public"."token_recuperaciones" TO "authenticated";
GRANT ALL ON TABLE "public"."token_recuperaciones" TO "service_role";



GRANT ALL ON SEQUENCE "public"."token_recuperaciones_id_tokenR_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."token_recuperaciones_id_tokenR_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."token_recuperaciones_id_tokenR_seq" TO "service_role";



GRANT ALL ON TABLE "public"."uso_tecnologias" TO "anon";
GRANT ALL ON TABLE "public"."uso_tecnologias" TO "authenticated";
GRANT ALL ON TABLE "public"."uso_tecnologias" TO "service_role";



GRANT ALL ON SEQUENCE "public"."uso_tecnologias_id_uso_tecnologia_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."uso_tecnologias_id_uso_tecnologia_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."uso_tecnologias_id_uso_tecnologia_seq" TO "service_role";



GRANT ALL ON TABLE "public"."usuario_repositorio_validaciones" TO "anon";
GRANT ALL ON TABLE "public"."usuario_repositorio_validaciones" TO "authenticated";
GRANT ALL ON TABLE "public"."usuario_repositorio_validaciones" TO "service_role";



GRANT ALL ON SEQUENCE "public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."usuario_repositorio_validacio_id_usuario_repositorio_valida_seq" TO "service_role";



GRANT ALL ON TABLE "public"."usuarios" TO "anon";
GRANT ALL ON TABLE "public"."usuarios" TO "authenticated";
GRANT ALL ON TABLE "public"."usuarios" TO "service_role";



GRANT ALL ON SEQUENCE "public"."usuarios_id_usuario_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."usuarios_id_usuario_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."usuarios_id_usuario_seq" TO "service_role";



GRANT ALL ON TABLE "public"."visibilidad_campos" TO "anon";
GRANT ALL ON TABLE "public"."visibilidad_campos" TO "authenticated";
GRANT ALL ON TABLE "public"."visibilidad_campos" TO "service_role";



GRANT ALL ON SEQUENCE "public"."visibilidad_campos_id_visibilidad_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."visibilidad_campos_id_visibilidad_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."visibilidad_campos_id_visibilidad_seq" TO "service_role";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "service_role";







