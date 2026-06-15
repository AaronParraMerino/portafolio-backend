# Despliegue HTTP integrado

Dominio de produccion:

```text
http://sparky.tis.cs.umss.edu.bo
```

## URLs que deben registrarse en los proveedores

Google:

```text
Origen JavaScript: http://sparky.tis.cs.umss.edu.bo
Callback OAuth:     http://sparky.tis.cs.umss.edu.bo/api/auth/google/callback
```

GitHub:

```text
Homepage URL:       http://sparky.tis.cs.umss.edu.bo
Callback URL:       http://sparky.tis.cs.umss.edu.bo/api/auth/github/callback
```

GitLab:

```text
Redirect URI:       http://sparky.tis.cs.umss.edu.bo/api/auth/gitlab/callback
```

Discord:

```text
Redirect URI:       http://sparky.tis.cs.umss.edu.bo/api/auth/discord/callback
```

Los callbacks del backend se forman automaticamente usando `APP_URL`.

Google puede rechazar un origen HTTP publico. La opcion permanece configurada,
pero su funcionamiento final depende de las restricciones de Google.

## Preparar el frontend

El build de produccion toma sus URLs desde:

```text
portafolio-frontend/.env.production
```

Generar el build:

```powershell
cd portafolio-frontend
npm.cmd run build
```

Copiar `build/index.html` a:

```text
portafolio-backend/resources/views/index.html
```

Copiar el resto del contenido de `build/` a:

```text
portafolio-backend/public/
```

## Preparar el servidor

Usar `.env.production.example` como base para el `.env` del servidor. Completar
`APP_KEY`, base de datos, correo y secretos OAuth.

En el servidor, la estructura debe mantener `public_html` al mismo nivel que
`app`, `bootstrap`, `storage` y `vendor`. Copiar el contenido de `public/`
dentro de `public_html/`.

Crear el enlace de archivos publicos:

```bash
ln -s ../storage/app/public public_html/storage
```

Finalmente:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Verificar:

```text
http://sparky.tis.cs.umss.edu.bo/up
http://sparky.tis.cs.umss.edu.bo/api/ping
http://sparky.tis.cs.umss.edu.bo/
http://sparky.tis.cs.umss.edu.bo/auth/login
```
