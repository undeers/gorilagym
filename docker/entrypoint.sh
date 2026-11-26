#!/bin/sh
# GorilaGym · arranque del contenedor
set -e
APP=/var/www/html
STORAGE=$APP/private/storage

# 1) Volúmenes: crear carpetas y restaurar .htaccess si el volumen llegó vacío
for d in db backups imports logs cache; do mkdir -p "$STORAGE/$d"; done
[ -f "$STORAGE/.htaccess" ] || cp /opt/gorilagym/storage/.htaccess "$STORAGE/.htaccess"
for d in avatars branding ejercicios maquinas; do mkdir -p "$APP/media/$d"; done
[ -f "$APP/media/.htaccess" ] || cp /opt/gorilagym/media/.htaccess "$APP/media/.htaccess"

# 2) config.php vive en el volumen (GG_CONFIG_FILE) para sobrevivir a cada redeploy
CFG_FILE="${GG_CONFIG_FILE:-$STORAGE/config.php}"
if [ -f "$APP/private/config.php" ] && [ ! -L "$APP/private/config.php" ] && [ ! -f "$CFG_FILE" ]; then
  mv "$APP/private/config.php" "$CFG_FILE"
fi
chown -R www-data:www-data "$STORAGE" "$APP/media"

# 3) Correo: mail() -> msmtp con las variables SMTP_*
if [ -n "$SMTP_HOST" ]; then
  cat > /etc/msmtprc <<CFG
defaults
auth           ${SMTP_AUTH:-on}
tls            ${SMTP_TLS:-on}
tls_starttls   ${SMTP_STARTTLS:-on}
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile        $STORAGE/logs/msmtp.log
account        default
host           $SMTP_HOST
port           ${SMTP_PORT:-587}
user           ${SMTP_USER:-}
password       ${SMTP_PASSWORD:-}
from           ${SMTP_FROM:-%U@localhost}
allow_from_override on
set_from_header auto
CFG
  chown www-data:www-data /etc/msmtprc
  chmod 600 /etc/msmtprc
else
  echo "[gorilagym] SMTP_HOST no definido: los correos no saldrán (usa 'mail_log_only' => true en config para pruebas)."
fi

# 4) Cron interno: cron.php dura ~55 s y revisa avisos cada 5 s
if [ "${GG_CRON:-1}" = "1" ]; then
  (
    while true; do
      su -s /bin/sh www-data -c "php $APP/private/cron/cron.php" >/dev/null 2>&1 || true
      sleep 5
    done
  ) &
fi

exec docker-php-entrypoint "$@"
