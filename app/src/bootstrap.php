<?php
declare(strict_types=1);

/**
 * bootstrap.php — Point d'entrée commun : config, session, PDO, helpers.
 *
 * La configuration (creds DB + chemins) est écrite par installer.sh dans
 * /etc/dnsmasq-web/config.php. On peut la surcharger via la variable
 * d'environnement DNSMASQ_WEB_CONFIG (utile en dev).
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // pas de fuite d'erreurs vers le navigateur

$configFile = getenv('DNSMASQ_WEB_CONFIG') ?: '/etc/dnsmasq-web/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration introuvable : ' . htmlspecialchars($configFile, ENT_QUOTES));
}

/** @var array $config */
$config = require $configFile;

// Valeurs par défaut si la config est incomplète.
$config += [
    'webui_dir'   => '/etc/dnsmasq.d/webui',
    'leases_file' => '/var/lib/misc/dnsmasq.leases',
    'apply_cmd'   => 'sudo /usr/local/sbin/dnsweb-apply',
];

require __DIR__ . '/functions.php';
require __DIR__ . '/validate.php';
require __DIR__ . '/generator.php';
require __DIR__ . '/leases.php';

// Session durcie.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/** @var PDO $pdo */
$pdo = db_connect($config['db']);
