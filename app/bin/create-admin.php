<?php
declare(strict_types=1);

/**
 * create-admin.php — Crée (ou met à jour) un utilisateur administrateur.
 *
 * Usage (sur le serveur) :
 *     php bin/create-admin.php <utilisateur> [role]
 *
 * Le mot de passe est demandé de façon interactive (masqué).
 * role : admin (défaut) ou viewer.
 */

if (PHP_SAPI !== 'cli') {
    exit("À exécuter en ligne de commande uniquement.\n");
}

$configFile = getenv('DNSMASQ_WEB_CONFIG') ?: '/etc/dnsmasq-web/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Config introuvable : $configFile\n");
    exit(1);
}
$config = require $configFile;
require dirname(__DIR__) . '/src/functions.php';

$username = $argv[1] ?? null;
$role     = ($argv[2] ?? 'admin') === 'viewer' ? 'viewer' : 'admin';
if (!$username) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <utilisateur> [admin|viewer]\n");
    exit(1);
}

// Saisie masquée du mot de passe.
fwrite(STDOUT, "Mot de passe pour «{$username}» : ");
system('stty -echo 2>/dev/null');
$password = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
fwrite(STDOUT, "\n");

if (strlen($password) < 6) {
    fwrite(STDERR, "Mot de passe trop court (min. 6 caractères).\n");
    exit(1);
}

$pdo  = db_connect($config['db']);
$hash = password_hash($password, PASSWORD_DEFAULT);

$st = $pdo->prepare(
    'INSERT INTO users (username, password_hash, role) VALUES (?,?,?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)'
);
$st->execute([$username, $hash, $role]);

fwrite(STDOUT, "Utilisateur «{$username}» ({$role}) enregistré.\n");
