# GorilaGym · v1.6.0

Web app (PWA) para Gorila Gym: rutinas semipersonalizadas, registro de pesos, ocupación de máquinas, QR/NFC, reportes, calificación de coaches y muro social.

PHP 8.1+ · SQLite (WAL) · Tailwind (compilado) · Hugeicons · sin Composer ni dependencias externas.

---

## 1. Instalación (llegar y descomprimir)

> **¿Docker / Dokploy?** Mira [DOKPLOY.md](DOKPLOY.md): la imagen incluye el cron y el envío por SMTP.

1. Sube el zip a la raíz pública del hosting (ej: `public_html/`) y descomprímelo. Queda la carpeta `gorilagym/`.
2. Abre `https://tudominio.cl/gorilagym/install/`.
3. El instalador revisa requisitos, crea la base de datos, genera las claves (app, cron, notificaciones push) y crea el super admin.
4. Entra en `https://tudominio.cl/gorilagym/` con tu RUT y la contraseña que definiste en el instalador.

El instalador se bloquea solo después de usarlo (`private/storage/install.lock`).

### Cron (obligatorio para avisos de descanso y respaldos)

Cada minuto:

```
* * * * * php /ruta/completa/gorilagym/private/cron/cron.php >/dev/null 2>&1
```

Cada ejecución dura ~55 s y revisa notificaciones cada 5 s, así el aviso de fin de descanso llega a tiempo.
Si el hosting solo permite cron por URL, usa la que aparece en **Gestión → Marca y ajustes → Sistema**.

### Correo

Los códigos (crear o recuperar contraseña, cambiar correo) salen con `mail()` nativo desde el correo remitente configurado.
Configura **SPF, DKIM y DMARC** del dominio o los códigos llegarán a spam. Hay un botón de "correo de prueba" en Marca y ajustes.

---

## 2. Requisitos

- PHP 8.1 o superior con: `pdo_sqlite`, `openssl`, `mbstring`, `fileinfo`, `json`
- Recomendado: `curl` (push), `zip` (leer .xlsx)
- SQLite 3.27+
- HTTPS (obligatorio para PWA, notificaciones y NFC)

---

## 3. Seguridad de carpetas

Todo lo sensible vive en `private/` (config, lógica, base de datos, respaldos, imports, logs).

- **Apache:** los `.htaccess` incluidos bloquean `private/`, archivos `.sqlite/.log/.md` y la ejecución de código en `media/`. El instalador verifica por HTTP que `private/` responda 403.
- **Nginx:** agrega al `server` (ajusta la ruta):

```nginx
location ~ ^/gorilagym/(private|install)/ { deny all; return 404; }
location ~ ^/gorilagym/media/.*\.(php|phtml|phar|svg|html?)$ { deny all; }
location ~ \.(sqlite|sqlite-wal|sqlite-shm|log|md)$ { deny all; }
```

(Bloquea `install/` recién después de instalar.)

Si el hosting permite dejar `private/` fuera del webroot, puedes moverla y ajustar la ruta en `private/app/core.php` (`PRIVATE_DIR`) y en `index.php`.

---

## 4. Mover a otro hosting o dominio

1. Copia la carpeta `gorilagym/` completa (incluye `private/storage/db/gorila.sqlite`). Idealmente con el sitio detenido o tras crear un respaldo.
2. Edita `private/config.php`: cambia `base_url` y `mail_from`.
3. Configura el cron en el nuevo hosting.
4. Configura SPF/DKIM del nuevo dominio.
5. **Reimprime los QR y vuelve a grabar los tags NFC**: llevan la URL completa del dominio.

Funciona en raíz (`tudominio.cl/`) o en subcarpeta (`tudominio.cl/gorilagym/`); no hay rutas fijas en el código.

---

## 5. Primeros pasos después de instalar

1. **Marca y ajustes:** ubicación del gimnasio (botón "usar mi ubicación" estando en el gym), revisar el cuestionario de salud.
2. **Usuarios:** crear jefes de turno y coaches (necesitan correo).
3. **Permisos:** ajustar qué puede hacer cada rol (super admin).
4. **Máquinas y QR:** crear máquinas y zonas (Mancuernas, Peso corporal).
5. **Ejercicios:** instrucción, foto, video, alternativas, restricciones. Todo opcional: se puede completar después.
6. **Plantillas:** rutinas base del gimnasio (~75 min).
7. **Importar Excel:** alumnos y vigencias (.xlsx o .csv; se mapean las columnas en pantalla).
8. **Imprimir QR** (entrada + máquinas) y grabar tags NFC.

### Probar en vivo (datos de prueba)

En **Marca y ajustes → Datos de prueba** (solo super admin) se crea un alumno (RUT 11.111.111-1) y un coach (RUT 22.222.222-2) que entran con **RUT + contraseña**, sin correo. Las contraseñas se generan al azar y se muestran una sola vez. Si el catálogo está vacío, se agregan también máquinas, ejercicios y 2 plantillas de ejemplo. El botón "Eliminar datos de prueba" borra todo eso (el catálogo de ejemplo que ya usen rutinas reales solo se desactiva).

---

## 6. Estructura

```
gorilagym/
├── index.php            ← front controller (?r=ruta)
├── sw.js                ← service worker (PWA, offline, push)
├── assets/              ← css, js, fuentes, íconos y logos
├── media/               ← fotos y videos subidos (sin ejecución de código)
├── install/             ← instalador (se bloquea solo)
└── private/             ← NO público
    ├── config.php       ← generado por el instalador
    ├── app/             ← núcleo, dominio, controladores, push, import, cron
    ├── views/           ← plantillas PHP
    ├── migrations/      ← SQL versionado
    ├── cron/cron.php
    └── storage/         ← db/, backups/, imports/, logs/, cache/
```

---

## 7. Respaldos

- Automático diario (cron, después de las 03:00), se guardan los últimos 30 en `private/storage/backups/`.
- Manual y descarga desde **Gestión → Respaldos**.
- Restaurar: reemplazar `private/storage/db/gorila.sqlite` por el respaldo y borrar los `-wal`/`-shm`.

---

## 8. Notas técnicas

- **Push sin payload:** el servidor envía un push vacío firmado con VAPID; el service worker consulta qué mostrar. Sin librerías externas.
- **iPhone:** las notificaciones solo funcionan con la app agregada a la pantalla de inicio (iOS 16.4+). La app lo explica en el primer ingreso.
- **NFC:** los tags llevan una URL. Funcionan en iPhone y Android sin app. Grabar desde la web solo es posible en Chrome Android (botón "Grabar tag"); en iPhone se usa una app como NFC Tools.
- **Bienvenida por ubicación:** solo con la app abierta (una web no puede leer la ubicación en segundo plano). La forma principal es el tag de la entrada.
- **Acceso según plan (v1.6):** los planes "Con coach" (semipersonalizados) tienen la app completa. Los planes "Libre (sin coach)" entran solo a los entrenos predeterminados del gimnasio (plantillas oficiales, sin editarlas: solo cambiar por una alternativa o dejar un ejercicio para el final), al progreso y al muro; ningún coach les arma rutina. En Marca y ajustes → General se puede cambiar a "No usan la app". Alumnos sin plan registrado no entran. Se mira el plan vigente o, si está vencido, el último.
- **Canciones en la Jungla:** al publicar, el alumno puede buscar y elegir una canción (opcional, junto al estado o sola). Con credenciales de Spotify (Marca y ajustes → Música) los resultados vienen de Spotify; sin ellas, o si Spotify falla, se usa el catálogo público de Apple y el botón abre la canción en Spotify. La cola de canciones de la sala se quitó en v1.4.
- **Contraseñas (v1.4):** todos entran con RUT + contraseña. La primera vez (o si la olvidan) piden un código al correo registrado y crean la contraseña; quien entra sin contraseña no puede usar la app hasta crearla. Desde Perfil → Seguridad se cambia la contraseña y el correo (el correo nuevo se confirma con código y se avisa al anterior). Los formularios usan `autocomplete` estándar para que el celular ofrezca guardarla. El código de 6 dígitos entra solo al pegarlo.
- **Muro (v1.4):** cada alumno puede borrar lo que publica. Publicaciones por día configurables en Marca y ajustes → Muro (por defecto 1; las borradas cuentan, el estado automático de la rutina no). 
- **Serie automática (v1.4):** si el alumno no toca "Serie hecha", al pasar descanso + duración de una serie + 20 s se marca sola con las mismas reps y peso de la anterior (queda con etiqueta "auto", se puede editar o borrar y no cuenta para récords). Solo desde el celular del alumno con la app abierta, desde la segunda serie en adelante y nunca tras 15 min sin actividad.
- **Actualizar versión:** sube los archivos nuevos encima (sin tocar `private/config.php` ni `private/storage/`). Las migraciones de base de datos se aplican solas en la primera visita.
- **Offline:** si se cae la señal, las series se guardan en el celular y se envían al volver la conexión.
- **Modo pruebas:** en `private/config.php`, `'mail_log_only' => true` escribe los correos en `private/storage/logs/mail.log` en vez de enviarlos; `'debug' => true` muestra errores.

---

## 9. Pendientes por definir con Gorila

- Si **Jungla** es plan libre (sin coach). Hoy está configurado como libre: se cambia en Planes.
- Precio del **Pase Diario**.
- **Pagos**: de dónde sale la vigencia (hoy viene del Excel).
- **Turnos** reales del staff.
- Validar el **cuestionario de salud** con un profesional y el tratamiento de datos con asesoría legal (Ley 21.719).
