# Desplegar GorilaGym en Dokploy

La imagen (`Dockerfile`) trae PHP 8.3 + Apache con todo lo que pide la app (`pdo_sqlite`, `curl`, `zip`, `gd`), el cron interno (no hay que configurar nada) y `mail()` enviando por SMTP con `msmtp`.

Lo que persiste entre despliegues vive en dos volúmenes:

| Volumen | Ruta en el contenedor | Contenido |
|---|---|---|
| `gorilagym-storage` | `/var/www/html/private/storage` | base de datos, `config.php`, respaldos, logs |
| `gorilagym-media` | `/var/www/html/media` | fotos, videos, avatares, logos |

---

## 1. DNS (en el proveedor de `gorilagym.cl`)

Crea un registro **A** para el subdominio apuntando a la IP pública del servidor de Dokploy:

| Tipo | Nombre | Valor |
|---|---|---|
| A | `app` | `IP.DEL.SERVIDOR` |

(Cambia `app` por el subdominio que quieras: `app.gorilagym.cl`, `entrena.gorilagym.cl`, etc.)

Si usas **Cloudflare**: deja la nube en gris (solo DNS) hasta que Dokploy emita el certificado; después puedes activarla con SSL en modo **Full (strict)**.

Comprueba: `dig +short app.gorilagym.cl` debe devolver la IP del servidor.

## 2. Crear el servicio en Dokploy

1. **Projects → Create Project** → `gorilagym`.
2. **Create Service → Compose**.
3. **Provider: GitHub** → repositorio `gorilagym-trainer`, rama (`main` o la que uses), **Compose Path:** `./docker-compose.yml`.
4. **Environment** (opcional pero necesario para que lleguen los códigos por correo):

   ```env
   SMTP_HOST=smtp.tu-proveedor.com
   SMTP_PORT=587
   SMTP_USER=no-reply@gorilagym.cl
   SMTP_PASSWORD=********
   SMTP_FROM=no-reply@gorilagym.cl
   ```

   Para puerto 465 (SSL directo) agrega `SMTP_STARTTLS=off`.
   Sirve cualquier SMTP: el del hosting del correo, Google Workspace, Brevo, Resend, Amazon SES, etc.

5. **Deploy**.

## 3. Dominio y HTTPS

En el servicio → pestaña **Domains → Add Domain**:

- **Service Name:** `gorilagym`
- **Host:** `app.gorilagym.cl`
- **Path:** `/`
- **Container Port:** `80`
- **HTTPS:** activado · **Certificate:** Let's Encrypt

Guarda y vuelve a hacer **Deploy** para que Traefik tome la regla. En 1–2 minutos `https://app.gorilagym.cl` debe responder con candado.

## 4. Instalar la app

1. Abre `https://app.gorilagym.cl/install/`.
2. **URL base:** `https://app.gorilagym.cl` (sin barra final).
3. **Correo remitente:** el mismo que `SMTP_FROM`.
4. Completa el super admin y termina.
5. **Ignora el paso del cron** que muestra el instalador: el contenedor ya lo corre solo.

El instalador queda bloqueado después de usarlo. La configuración generada queda en el volumen (`private/storage/config.php`) y no se pierde al redeplegar.

## 5. Después de instalar

- **Marca y ajustes → correo de prueba** para confirmar el SMTP. Si falla, revisa `private/storage/logs/msmtp.log` (pestaña **Logs/Terminal** del servicio en Dokploy).
- Configura **SPF, DKIM y DMARC** de `gorilagym.cl` para el proveedor SMTP que uses o los correos llegarán a spam.
- **Respaldos:** la app hace uno diario en `private/storage/backups/`. Además, en Dokploy puedes programar respaldos del volumen `gorilagym-storage` (Volume Backups) a S3.

## Actualizar versión

Haz push al repositorio y pulsa **Deploy** (o activa **Auto Deploy**). Las migraciones de la base de datos se aplican solas en la primera visita. Los volúmenes no se tocan.

## Variables disponibles

| Variable | Por defecto | Uso |
|---|---|---|
| `SMTP_HOST` | — | Servidor SMTP. Sin él no salen correos. |
| `SMTP_PORT` | `587` | Puerto. |
| `SMTP_USER` / `SMTP_PASSWORD` | — | Credenciales. |
| `SMTP_FROM` | `no-reply@gorilagym.cl` | Remitente (igual al de la app). |
| `SMTP_TLS` / `SMTP_STARTTLS` | `on` / `on` | `SMTP_STARTTLS=off` para puerto 465. |
| `SMTP_AUTH` | `on` | `off` para relays sin autenticación. |
| `GG_CRON` | `1` | `0` desactiva el cron interno (si lo corres aparte). |

## Probar en local

```bash
docker compose up --build
```

Queda en el puerto 80 del contenedor; para abrirlo en tu equipo cambia `expose` por `ports: ["8080:80"]` en `docker-compose.yml` y entra a `http://localhost:8080/install/`.
