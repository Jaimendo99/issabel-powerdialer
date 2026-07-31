<?php
/* Copy environment secrets to /etc/issabel/gestion_clientes.conf.php. */
$gcConfig = array(
    'db_dsn' => 'mysql:host=127.0.0.1;dbname=gestion_clientes;charset=utf8',
    'db_user' => 'gestion_clientes',
    'db_password' => '',
    'timezone' => 'America/Guayaquil',
    'claim_ttl_seconds' => 900,
    'upload_dir' => '/var/lib/issabel/gestion_clientes/uploads',
    'max_upload_bytes' => 10485760,
    'supervisor_users' => array('admin'),
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
    'cdr_table' => 'cdr'
);

$gcLocalConfig = '/etc/issabel/gestion_clientes.conf.php';
if (is_readable($gcLocalConfig)) {
    $gcOverride = include $gcLocalConfig;
    if (is_array($gcOverride)) {
        $gcConfig = array_merge($gcConfig, $gcOverride);
    }
}

return $gcConfig;
