<?php
/* Copy environment secrets to /etc/issabel/gestion_clientes.conf.php. */
$gcConfig = array(
    'db_dsn' => 'mysql:host=127.0.0.1;dbname=gestion_clientes;charset=utf8',
    'db_user' => 'gestion_clientes',
    'db_password' => '',
    'timezone' => 'America/Guayaquil',
    'claim_ttl_seconds' => 900,
    'seat_session_ttl_seconds' => 43200,
    'upload_dir' => '/var/lib/asterisk/gestion_clientes/uploads',
    'max_upload_bytes' => 10485760,
    'supervisor_users' => array('admin'),
    'issabel_user_db_path' => '/var/www/db/acl.db',
    'issabel_user_table' => 'acl_user',
    'issabel_user_id_column' => 'id',
    'issabel_username_column' => 'name',
    'issabel_user_label_column' => 'description',
    'ami_host' => '127.0.0.1',
    'ami_port' => 5038,
    'ami_username' => 'gestion_clientes',
    'ami_secret_file' => '/etc/issabel/gestion_clientes_ami.secret',
    'ami_timeout_seconds' => 5,
    'sip_technology' => 'SIP',
    'dial_context' => 'gestion-clientes-outbound',
    'outbound_context' => 'from-internal',
    'cdr_dsn' => 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8',
    'cdr_user' => 'gestion_clientes_cdr',
    'cdr_password' => '',
    'cdr_table' => 'cdr',
    'cdr_linkedid_column' => 'linkedid',
    'cdr_timezone' => 'America/Guayaquil'
);

$gcLocalConfig = '/etc/issabel/gestion_clientes.conf.php';
if (is_readable($gcLocalConfig)) {
    $gcOverride = include $gcLocalConfig;
    if (is_array($gcOverride)) {
        $gcConfig = array_merge($gcConfig, $gcOverride);
    }
}

return $gcConfig;
