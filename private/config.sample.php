<?php
// Ejemplo de configuración. El instalador genera private/config.php automáticamente.
return [
    'base_url' => 'https://tudominio.cl/gorilagym',
    'db_path' => PRIVATE_DIR . '/storage/db/gorila.sqlite',
    'timezone' => 'America/Santiago',
    'mail_from' => 'no-reply@tudominio.cl',
    'app_key' => 'generado-por-el-instalador',
    'cron_token' => 'generado-por-el-instalador',
    'vapid_public' => 'generado-por-el-instalador',
    'vapid_private_pem' => "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
    'vapid_subject' => 'mailto:no-reply@tudominio.cl',
    'debug' => false,
    'mail_log_only' => false,
];
