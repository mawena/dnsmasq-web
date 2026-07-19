<?php
declare(strict_types=1);

/**
 * apply.php — Régénère les fichiers .conf depuis MySQL et recharge dnsmasq.
 * Utilisé par « dwc apply » (et après une restauration).
 *
 * Usage : php bin/apply.php
 */

if (PHP_SAPI !== 'cli') {
    exit("À exécuter en ligne de commande uniquement.\n");
}

$configFile = getenv('DNSMASQ_WEB_CONFIG') ?: '/etc/dnsmasq-webui/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Config introuvable : $configFile\n");
    exit(1);
}
$config = require $configFile;
$config += [
    'webui_dir'   => '/etc/dnsmasq.d/webui',
    'leases_file' => '/var/lib/misc/dnsmasq.leases',
    'apply_cmd'   => 'sudo /usr/sbin/dnsmasq-webui-apply',
];

require dirname(__DIR__) . '/src/functions.php';
require dirname(__DIR__) . '/src/validate.php';
require dirname(__DIR__) . '/src/generator.php';

$pdo = db_connect($config['db']);
[$ok, $out] = generate_config($pdo, $config);
fwrite($ok ? STDOUT : STDERR, $out . "\n");
exit($ok ? 0 : 1);
