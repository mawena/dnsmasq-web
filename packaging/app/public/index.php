<?php
declare(strict_types=1);

/**
 * index.php — Front controller.
 * nginx : try_files $uri $uri/ /index.php?$query_string;
 * On route sur le chemin de l'URL (ex: /hosts → src/pages/hosts.php).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

$route = current_route();

// Pages publiques (sans authentification).
$public = ['login'];

if (!in_array($route, $public, true)) {
    require_login();
}

// Whitelist des routes → fichiers de page.
$routes = [
    'login'     => 'login.php',
    'logout'    => 'logout.php',
    'dashboard' => 'dashboard.php',
    'hosts'     => 'hosts.php',
    'cnames'    => 'cnames.php',
    'dhcp'      => 'dhcp.php',
    'leases'    => 'leases.php',
    'blocklist' => 'blocklist.php',
    'settings'  => 'settings.php',
];

$page = $routes[$route] ?? null;
if ($page === null) {
    http_response_code(404);
    $title = 'Introuvable';
    require dirname(__DIR__) . '/views/layout_top.php';
    echo '<h1>404</h1><p>Page introuvable.</p>';
    require dirname(__DIR__) . '/views/layout_bottom.php';
    exit;
}

require dirname(__DIR__) . '/src/pages/' . $page;
