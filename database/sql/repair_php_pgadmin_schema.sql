-- repair_php_pgadmin_schema.sql
-- PostgreSQL 15 / phpPgAdmin
--
-- Objetivo:
-- Alinear una base existente de Sparky/CreaFolio con las reglas que esperan
-- las migraciones actuales de Laravel, sin recrear tablas ni borrar datos de negocio.
--
-- Antes de ejecutar:
-- 1. Exporta un respaldo desde phpPgAdmin.
-- 2. Ejecuta este archivo completo, o por bloques si el panel corta el tiempo.
-- 3. Si un CREATE UNIQUE INDEX falla, revisa la consulta de duplicados
--    indicada por la tabla y decide manualmente que fila conservar.

BEGIN;

-- ============================================================
-- 1. Unicos esperados por la aplicacion
-- ============================================================
-- Mantiene la fila con mayor id interno cuando encuentra duplicados.

DELETE FROM visibilidad_campos a
USING visibilidad_campos b
WHERE a.usuario_id = b.usuario_id
  AND a.campo = b.campo
  AND a.id_visibilidad < b.id_visibilidad;

CREATE UNIQUE INDEX IF NOT EXISTS visibilidad_campos_usuario_id_campo_unique
ON visibilidad_campos (usuario_id, campo);

DELETE FROM habilidades_usuario a
USING habilidades_usuario b
WHERE a.usuario_id = b.usuario_id
  AND a.habilidad_id = b.habilidad_id
  AND a.id_habilidad_usuario < b.id_habilidad_usuario;

CREATE UNIQUE INDEX IF NOT EXISTS habilidades_usuario_usuario_id_habilidad_id_unique
ON habilidades_usuario (usuario_id, habilidad_id);

DELETE FROM participaciones a
USING participaciones b
WHERE a.id_usuario = b.id_usuario
  AND a.id_proyecto = b.id_proyecto
  AND a.id_participacion < b.id_participacion;

CREATE UNIQUE INDEX IF NOT EXISTS participaciones_id_usuario_id_proyecto_unique
ON participaciones (id_usuario, id_proyecto);

DELETE FROM uso_tecnologias a
USING uso_tecnologias b
WHERE a.id_proyecto = b.id_proyecto
  AND a.id_tecnologia = b.id_tecnologia
  AND a.id_uso_tecnologia < b.id_uso_tecnologia;

CREATE UNIQUE INDEX IF NOT EXISTS uso_tecnologias_id_proyecto_id_tecnologia_unique
ON uso_tecnologias (id_proyecto, id_tecnologia);

DELETE FROM participacion_repositorios a
USING participacion_repositorios b
WHERE a.id_participacion = b.id_participacion
  AND a.id_proyecto_repositorio = b.id_proyecto_repositorio
  AND a.id_participacion_repositorio < b.id_participacion_repositorio;

CREATE UNIQUE INDEX IF NOT EXISTS uq_participacion_repo
ON participacion_repositorios (id_participacion, id_proyecto_repositorio);

DELETE FROM usuario_repositorio_validaciones a
USING usuario_repositorio_validaciones b
WHERE a.id_usuario = b.id_usuario
  AND a.id_repositorio_github = b.id_repositorio_github
  AND a.id_usuario_repositorio_validacion < b.id_usuario_repositorio_validacion;

CREATE UNIQUE INDEX IF NOT EXISTS uq_usuario_repositorio_validaciones_usuario_repo
ON usuario_repositorio_validaciones (id_usuario, id_repositorio_github);

DELETE FROM notificacion_usuario a
USING notificacion_usuario b
WHERE a.id_notificacion = b.id_notificacion
  AND a.id_usuario = b.id_usuario
  AND a.id_notificacion_usuario < b.id_notificacion_usuario;

CREATE UNIQUE INDEX IF NOT EXISTS notificacion_usuario_id_notificacion_id_usuario_unique
ON notificacion_usuario (id_notificacion, id_usuario);

DELETE FROM cuentas_oauth a
USING cuentas_oauth b
WHERE a.provider = b.provider
  AND a.provider_user_id = b.provider_user_id
  AND a.id_cuenta_oauth < b.id_cuenta_oauth;

CREATE UNIQUE INDEX IF NOT EXISTS cuentas_oauth_provider_provider_user_id_unique
ON cuentas_oauth (provider, provider_user_id);

DELETE FROM cuentas_oauth a
USING cuentas_oauth b
WHERE a.usuario_id = b.usuario_id
  AND a.provider = b.provider
  AND a.id_cuenta_oauth < b.id_cuenta_oauth;

CREATE UNIQUE INDEX IF NOT EXISTS cuentas_oauth_usuario_provider_unique
ON cuentas_oauth (usuario_id, provider);

DELETE FROM chat_privado_pares a
USING chat_privado_pares b
WHERE a.id_usuario_menor = b.id_usuario_menor
  AND a.id_usuario_mayor = b.id_usuario_mayor
  AND a.id_chat_privado_par < b.id_chat_privado_par;

CREATE UNIQUE INDEX IF NOT EXISTS chat_privado_pares_usuarios_unique
ON chat_privado_pares (id_usuario_menor, id_usuario_mayor);

DELETE FROM chat_lecturas a
USING chat_lecturas b
WHERE a.id_chat = b.id_chat
  AND a.id_usuario = b.id_usuario
  AND a.id_chat_lectura < b.id_chat_lectura;

CREATE UNIQUE INDEX IF NOT EXISTS chat_lecturas_id_chat_id_usuario_unique
ON chat_lecturas (id_chat, id_usuario);

DELETE FROM traducciones_contenido a
USING traducciones_contenido b
WHERE a.entidad_tipo = b.entidad_tipo
  AND a.entidad_id = b.entidad_id
  AND a.campo = b.campo
  AND a.idioma = b.idioma
  AND a.id_traduccion < b.id_traduccion;

CREATE UNIQUE INDEX IF NOT EXISTS traducciones_contenido_unique
ON traducciones_contenido (entidad_tipo, entidad_id, campo, idioma);

-- ============================================================
-- 2. Foreign keys criticas con ON DELETE correcto
-- ============================================================
-- El dump del servidor tenia muchas FKs sin accion de borrado.
-- Este bloque las reconstruye segun las migraciones del repo.

ALTER TABLE personal_access_tokens
DROP CONSTRAINT IF EXISTS personal_access_tokens_tokenable_id_foreign;
ALTER TABLE personal_access_tokens
ADD CONSTRAINT personal_access_tokens_tokenable_id_foreign
FOREIGN KEY (tokenable_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE sesion_base
DROP CONSTRAINT IF EXISTS sesion_base_usuario_id_foreign;
ALTER TABLE sesion_base
ADD CONSTRAINT sesion_base_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE sesion_base
DROP CONSTRAINT IF EXISTS sesion_base_personal_access_token_id_foreign;
ALTER TABLE sesion_base
ADD CONSTRAINT sesion_base_personal_access_token_id_foreign
FOREIGN KEY (personal_access_token_id) REFERENCES personal_access_tokens(id)
ON DELETE SET NULL;

ALTER TABLE sesion_base
DROP CONSTRAINT IF EXISTS sesion_base_token_recuperacion_id_foreign;
ALTER TABLE sesion_base
ADD CONSTRAINT sesion_base_token_recuperacion_id_foreign
FOREIGN KEY (token_recuperacion_id) REFERENCES token_recuperaciones("id_tokenR")
ON DELETE SET NULL;

ALTER TABLE sesion_base
DROP CONSTRAINT IF EXISTS sesion_base_bitacora_id_foreign;
ALTER TABLE sesion_base
ADD CONSTRAINT sesion_base_bitacora_id_foreign
FOREIGN KEY (bitacora_id) REFERENCES bitacoras(id_bitacora)
ON DELETE SET NULL;

ALTER TABLE bitacoras
DROP CONSTRAINT IF EXISTS bitacoras_usuario_id_foreign;
ALTER TABLE bitacoras
ADD CONSTRAINT bitacoras_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE perfiles
DROP CONSTRAINT IF EXISTS perfiles_usuario_id_foreign;
ALTER TABLE perfiles
ADD CONSTRAINT perfiles_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE visibilidad_campos
DROP CONSTRAINT IF EXISTS visibilidad_campos_usuario_id_foreign;
ALTER TABLE visibilidad_campos
ADD CONSTRAINT visibilidad_campos_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE experiencias
DROP CONSTRAINT IF EXISTS experiencias_usuario_id_foreign;
ALTER TABLE experiencias
ADD CONSTRAINT experiencias_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE enlaces
DROP CONSTRAINT IF EXISTS enlaces_id_usuario_foreign;
ALTER TABLE enlaces
ADD CONSTRAINT enlaces_id_usuario_foreign
FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE habilidades_usuario
DROP CONSTRAINT IF EXISTS habilidades_usuario_usuario_id_foreign;
ALTER TABLE habilidades_usuario
ADD CONSTRAINT habilidades_usuario_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE habilidades_usuario
DROP CONSTRAINT IF EXISTS habilidades_usuario_habilidad_id_foreign;
ALTER TABLE habilidades_usuario
ADD CONSTRAINT habilidades_usuario_habilidad_id_foreign
FOREIGN KEY (habilidad_id) REFERENCES habilidades(id_habilidad)
ON DELETE CASCADE;

ALTER TABLE cuentas_oauth
DROP CONSTRAINT IF EXISTS cuentas_oauth_usuario_id_foreign;
ALTER TABLE cuentas_oauth
ADD CONSTRAINT cuentas_oauth_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE token_recuperaciones
DROP CONSTRAINT IF EXISTS token_recuperaciones_usuario_id_foreign;
ALTER TABLE token_recuperaciones
ADD CONSTRAINT token_recuperaciones_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE personalizaciones_portafolio
DROP CONSTRAINT IF EXISTS personalizaciones_portafolio_usuario_id_foreign;
ALTER TABLE personalizaciones_portafolio
ADD CONSTRAINT personalizaciones_portafolio_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE eventos_personales
DROP CONSTRAINT IF EXISTS eventos_personales_usuario_id_foreign;
ALTER TABLE eventos_personales
ADD CONSTRAINT eventos_personales_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE participaciones
DROP CONSTRAINT IF EXISTS participaciones_id_usuario_foreign;
ALTER TABLE participaciones
ADD CONSTRAINT participaciones_id_usuario_foreign
FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE participaciones
DROP CONSTRAINT IF EXISTS participaciones_id_proyecto_foreign;
ALTER TABLE participaciones
ADD CONSTRAINT participaciones_id_proyecto_foreign
FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto)
ON DELETE CASCADE;

ALTER TABLE proyecto_repositorios
DROP CONSTRAINT IF EXISTS proyecto_repositorios_id_proyecto_foreign;
ALTER TABLE proyecto_repositorios
ADD CONSTRAINT proyecto_repositorios_id_proyecto_foreign
FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto)
ON DELETE CASCADE;

ALTER TABLE repositorio_github
DROP CONSTRAINT IF EXISTS repositorio_github_id_proyecto_repositorio_foreign;
ALTER TABLE repositorio_github
ADD CONSTRAINT repositorio_github_id_proyecto_repositorio_foreign
FOREIGN KEY (id_proyecto_repositorio) REFERENCES proyecto_repositorios(id_proyecto_repositorio)
ON DELETE CASCADE;

ALTER TABLE proyecto_evidencias
DROP CONSTRAINT IF EXISTS proyecto_evidencias_id_proyecto_foreign;
ALTER TABLE proyecto_evidencias
ADD CONSTRAINT proyecto_evidencias_id_proyecto_foreign
FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto)
ON DELETE CASCADE;

ALTER TABLE uso_tecnologias
DROP CONSTRAINT IF EXISTS uso_tecnologias_id_proyecto_foreign;
ALTER TABLE uso_tecnologias
ADD CONSTRAINT uso_tecnologias_id_proyecto_foreign
FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto)
ON DELETE CASCADE;

ALTER TABLE uso_tecnologias
DROP CONSTRAINT IF EXISTS uso_tecnologias_id_tecnologia_foreign;
ALTER TABLE uso_tecnologias
ADD CONSTRAINT uso_tecnologias_id_tecnologia_foreign
FOREIGN KEY (id_tecnologia) REFERENCES tecnologias(id_tecnologia)
ON DELETE CASCADE;

ALTER TABLE proyecto_configuraciones
DROP CONSTRAINT IF EXISTS proyecto_configuraciones_id_proyecto_foreign;
ALTER TABLE proyecto_configuraciones
ADD CONSTRAINT proyecto_configuraciones_id_proyecto_foreign
FOREIGN KEY (id_proyecto) REFERENCES proyectos(id_proyecto)
ON DELETE CASCADE;

ALTER TABLE participacion_repositorios
DROP CONSTRAINT IF EXISTS participacion_repositorios_id_participacion_foreign;
ALTER TABLE participacion_repositorios
ADD CONSTRAINT participacion_repositorios_id_participacion_foreign
FOREIGN KEY (id_participacion) REFERENCES participaciones(id_participacion)
ON DELETE CASCADE;

ALTER TABLE participacion_repositorios
DROP CONSTRAINT IF EXISTS participacion_repositorios_id_proyecto_repositorio_foreign;
ALTER TABLE participacion_repositorios
ADD CONSTRAINT participacion_repositorios_id_proyecto_repositorio_foreign
FOREIGN KEY (id_proyecto_repositorio) REFERENCES proyecto_repositorios(id_proyecto_repositorio)
ON DELETE CASCADE;

ALTER TABLE usuario_repositorio_validaciones
DROP CONSTRAINT IF EXISTS usuario_repositorio_validaciones_id_usuario_foreign;
ALTER TABLE usuario_repositorio_validaciones
ADD CONSTRAINT usuario_repositorio_validaciones_id_usuario_foreign
FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE usuario_repositorio_validaciones
DROP CONSTRAINT IF EXISTS usuario_repositorio_validaciones_id_repositorio_github_foreign;
ALTER TABLE usuario_repositorio_validaciones
ADD CONSTRAINT usuario_repositorio_validaciones_id_repositorio_github_foreign
FOREIGN KEY (id_repositorio_github) REFERENCES repositorio_github(id_repositorio_github)
ON DELETE CASCADE;

ALTER TABLE usuario_repositorio_validaciones
DROP CONSTRAINT IF EXISTS usuario_repositorio_validaciones_id_cuenta_oauth_foreign;
ALTER TABLE usuario_repositorio_validaciones
ADD CONSTRAINT usuario_repositorio_validaciones_id_cuenta_oauth_foreign
FOREIGN KEY (id_cuenta_oauth) REFERENCES cuentas_oauth(id_cuenta_oauth)
ON DELETE SET NULL;

ALTER TABLE notificaciones
DROP CONSTRAINT IF EXISTS notificaciones_id_usuario_actor_foreign;
ALTER TABLE notificaciones
ADD CONSTRAINT notificaciones_id_usuario_actor_foreign
FOREIGN KEY (id_usuario_actor) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE notificaciones
DROP CONSTRAINT IF EXISTS notificaciones_accion_respuesta_usuario_id_foreign;
ALTER TABLE notificaciones
ADD CONSTRAINT notificaciones_accion_respuesta_usuario_id_foreign
FOREIGN KEY (accion_respuesta_usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE notificacion_usuario
DROP CONSTRAINT IF EXISTS notificacion_usuario_id_notificacion_foreign;
ALTER TABLE notificacion_usuario
ADD CONSTRAINT notificacion_usuario_id_notificacion_foreign
FOREIGN KEY (id_notificacion) REFERENCES notificaciones(id_notificacion)
ON DELETE CASCADE;

ALTER TABLE notificacion_usuario
DROP CONSTRAINT IF EXISTS notificacion_usuario_id_usuario_foreign;
ALTER TABLE notificacion_usuario
ADD CONSTRAINT notificacion_usuario_id_usuario_foreign
FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE admin_eventos
DROP CONSTRAINT IF EXISTS admin_eventos_usuario_creador_id_foreign;
ALTER TABLE admin_eventos
ADD CONSTRAINT admin_eventos_usuario_creador_id_foreign
FOREIGN KEY (usuario_creador_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE admin_eventos
DROP CONSTRAINT IF EXISTS admin_eventos_usuario_actualizador_id_foreign;
ALTER TABLE admin_eventos
ADD CONSTRAINT admin_eventos_usuario_actualizador_id_foreign
FOREIGN KEY (usuario_actualizador_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE evento_inscripciones
DROP CONSTRAINT IF EXISTS evento_inscripciones_evento_id_foreign;
ALTER TABLE evento_inscripciones
ADD CONSTRAINT evento_inscripciones_evento_id_foreign
FOREIGN KEY (evento_id) REFERENCES admin_eventos(id_evento)
ON DELETE CASCADE;

ALTER TABLE evento_inscripciones
DROP CONSTRAINT IF EXISTS evento_inscripciones_usuario_id_foreign;
ALTER TABLE evento_inscripciones
ADD CONSTRAINT evento_inscripciones_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE publicante_solicitudes
DROP CONSTRAINT IF EXISTS publicante_solicitudes_usuario_id_foreign;
ALTER TABLE publicante_solicitudes
ADD CONSTRAINT publicante_solicitudes_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE publicante_solicitudes
DROP CONSTRAINT IF EXISTS publicante_solicitudes_admin_revisor_id_foreign;
ALTER TABLE publicante_solicitudes
ADD CONSTRAINT publicante_solicitudes_admin_revisor_id_foreign
FOREIGN KEY (admin_revisor_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE admin_evento_acciones
DROP CONSTRAINT IF EXISTS admin_evento_acciones_evento_id_foreign;
ALTER TABLE admin_evento_acciones
ADD CONSTRAINT admin_evento_acciones_evento_id_foreign
FOREIGN KEY (evento_id) REFERENCES admin_eventos(id_evento)
ON DELETE CASCADE;

ALTER TABLE admin_evento_acciones
DROP CONSTRAINT IF EXISTS admin_evento_acciones_admin_id_foreign;
ALTER TABLE admin_evento_acciones
ADD CONSTRAINT admin_evento_acciones_admin_id_foreign
FOREIGN KEY (admin_id) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE admin_evento_acciones
DROP CONSTRAINT IF EXISTS admin_evento_acciones_usuario_publicante_id_foreign;
ALTER TABLE admin_evento_acciones
ADD CONSTRAINT admin_evento_acciones_usuario_publicante_id_foreign
FOREIGN KEY (usuario_publicante_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE admin_usuario_plantillas
DROP CONSTRAINT IF EXISTS admin_usuario_plantillas_usuario_actualizador_id_foreign;
ALTER TABLE admin_usuario_plantillas
ADD CONSTRAINT admin_usuario_plantillas_usuario_actualizador_id_foreign
FOREIGN KEY (usuario_actualizador_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE avisos
DROP CONSTRAINT IF EXISTS avisos_id_usuario_actor_foreign;
ALTER TABLE avisos
ADD CONSTRAINT avisos_id_usuario_actor_foreign
FOREIGN KEY (id_usuario_actor) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE traducciones_contenido
DROP CONSTRAINT IF EXISTS traducciones_contenido_usuario_id_foreign;
ALTER TABLE traducciones_contenido
ADD CONSTRAINT traducciones_contenido_usuario_id_foreign
FOREIGN KEY (usuario_id) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

-- Chat y denuncias.
ALTER TABLE chats
DROP CONSTRAINT IF EXISTS chats_id_usuario_creador_foreign;
ALTER TABLE chats
ADD CONSTRAINT chats_id_usuario_creador_foreign
FOREIGN KEY (id_usuario_creador) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE chat_privado_pares
DROP CONSTRAINT IF EXISTS chat_privado_pares_id_chat_foreign;
ALTER TABLE chat_privado_pares
ADD CONSTRAINT chat_privado_pares_id_chat_foreign
FOREIGN KEY (id_chat) REFERENCES chats(id_chat)
ON DELETE CASCADE;

ALTER TABLE chat_privado_pares
DROP CONSTRAINT IF EXISTS chat_privado_pares_id_usuario_menor_foreign;
ALTER TABLE chat_privado_pares
ADD CONSTRAINT chat_privado_pares_id_usuario_menor_foreign
FOREIGN KEY (id_usuario_menor) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_privado_pares
DROP CONSTRAINT IF EXISTS chat_privado_pares_id_usuario_mayor_foreign;
ALTER TABLE chat_privado_pares
ADD CONSTRAINT chat_privado_pares_id_usuario_mayor_foreign
FOREIGN KEY (id_usuario_mayor) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_participantes
DROP CONSTRAINT IF EXISTS chat_participantes_id_chat_foreign;
ALTER TABLE chat_participantes
ADD CONSTRAINT chat_participantes_id_chat_foreign
FOREIGN KEY (id_chat) REFERENCES chats(id_chat)
ON DELETE CASCADE;

ALTER TABLE chat_participantes
DROP CONSTRAINT IF EXISTS chat_participantes_id_usuario_foreign;
ALTER TABLE chat_participantes
ADD CONSTRAINT chat_participantes_id_usuario_foreign
FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_solicitudes
DROP CONSTRAINT IF EXISTS chat_solicitudes_id_chat_foreign;
ALTER TABLE chat_solicitudes
ADD CONSTRAINT chat_solicitudes_id_chat_foreign
FOREIGN KEY (id_chat) REFERENCES chats(id_chat)
ON DELETE CASCADE;

ALTER TABLE chat_solicitudes
DROP CONSTRAINT IF EXISTS chat_solicitudes_id_solicitante_foreign;
ALTER TABLE chat_solicitudes
ADD CONSTRAINT chat_solicitudes_id_solicitante_foreign
FOREIGN KEY (id_solicitante) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_solicitudes
DROP CONSTRAINT IF EXISTS chat_solicitudes_id_destinatario_foreign;
ALTER TABLE chat_solicitudes
ADD CONSTRAINT chat_solicitudes_id_destinatario_foreign
FOREIGN KEY (id_destinatario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_invitaciones
DROP CONSTRAINT IF EXISTS chat_invitaciones_id_chat_foreign;
ALTER TABLE chat_invitaciones
ADD CONSTRAINT chat_invitaciones_id_chat_foreign
FOREIGN KEY (id_chat) REFERENCES chats(id_chat)
ON DELETE CASCADE;

ALTER TABLE chat_invitaciones
DROP CONSTRAINT IF EXISTS chat_invitaciones_id_invitador_foreign;
ALTER TABLE chat_invitaciones
ADD CONSTRAINT chat_invitaciones_id_invitador_foreign
FOREIGN KEY (id_invitador) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE chat_invitaciones
DROP CONSTRAINT IF EXISTS chat_invitaciones_id_invitado_foreign;
ALTER TABLE chat_invitaciones
ADD CONSTRAINT chat_invitaciones_id_invitado_foreign
FOREIGN KEY (id_invitado) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_mensajes
DROP CONSTRAINT IF EXISTS chat_mensajes_id_chat_foreign;
ALTER TABLE chat_mensajes
ADD CONSTRAINT chat_mensajes_id_chat_foreign
FOREIGN KEY (id_chat) REFERENCES chats(id_chat)
ON DELETE CASCADE;

ALTER TABLE chat_mensajes
DROP CONSTRAINT IF EXISTS chat_mensajes_id_usuario_emisor_foreign;
ALTER TABLE chat_mensajes
ADD CONSTRAINT chat_mensajes_id_usuario_emisor_foreign
FOREIGN KEY (id_usuario_emisor) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

ALTER TABLE chat_lecturas
DROP CONSTRAINT IF EXISTS chat_lecturas_id_chat_foreign;
ALTER TABLE chat_lecturas
ADD CONSTRAINT chat_lecturas_id_chat_foreign
FOREIGN KEY (id_chat) REFERENCES chats(id_chat)
ON DELETE CASCADE;

ALTER TABLE chat_lecturas
DROP CONSTRAINT IF EXISTS chat_lecturas_id_usuario_foreign;
ALTER TABLE chat_lecturas
ADD CONSTRAINT chat_lecturas_id_usuario_foreign
FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE chat_lecturas
DROP CONSTRAINT IF EXISTS chat_lecturas_ultimo_mensaje_leido_id_foreign;
ALTER TABLE chat_lecturas
ADD CONSTRAINT chat_lecturas_ultimo_mensaje_leido_id_foreign
FOREIGN KEY (ultimo_mensaje_leido_id) REFERENCES chat_mensajes(id_chat_mensaje)
ON DELETE SET NULL;

ALTER TABLE denuncias
DROP CONSTRAINT IF EXISTS denuncias_id_denunciante_foreign;
ALTER TABLE denuncias
ADD CONSTRAINT denuncias_id_denunciante_foreign
FOREIGN KEY (id_denunciante) REFERENCES usuarios(id_usuario)
ON DELETE CASCADE;

ALTER TABLE denuncias
DROP CONSTRAINT IF EXISTS denuncias_id_usuario_revisor_foreign;
ALTER TABLE denuncias
ADD CONSTRAINT denuncias_id_usuario_revisor_foreign
FOREIGN KEY (id_usuario_revisor) REFERENCES usuarios(id_usuario)
ON DELETE SET NULL;

COMMIT;

-- ============================================================
-- 3. Verificaciones posteriores
-- ============================================================
-- Deben devolver 0 filas si no quedan duplicados relevantes.
--
-- SELECT usuario_id, campo, COUNT(*) FROM visibilidad_campos GROUP BY usuario_id, campo HAVING COUNT(*) > 1;
-- SELECT usuario_id, habilidad_id, COUNT(*) FROM habilidades_usuario GROUP BY usuario_id, habilidad_id HAVING COUNT(*) > 1;
-- SELECT id_usuario, id_proyecto, COUNT(*) FROM participaciones GROUP BY id_usuario, id_proyecto HAVING COUNT(*) > 1;
-- SELECT id_proyecto, id_tecnologia, COUNT(*) FROM uso_tecnologias GROUP BY id_proyecto, id_tecnologia HAVING COUNT(*) > 1;
-- SELECT id_notificacion, id_usuario, COUNT(*) FROM notificacion_usuario GROUP BY id_notificacion, id_usuario HAVING COUNT(*) > 1;
--
-- Consulta para revisar FKs y acciones de borrado:
-- SELECT conname, conrelid::regclass AS tabla, pg_get_constraintdef(oid) AS definicion
-- FROM pg_constraint
-- WHERE contype = 'f'
-- ORDER BY tabla::text, conname;
