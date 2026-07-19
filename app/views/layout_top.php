<?php /** @var string $title */ ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title ?? 'dnsmasq-web') ?> — dnsmasq-web</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(base_path()) ?>/assets/favicon.svg">
    <link rel="stylesheet" href="<?= h(base_path()) ?>/assets/app.css">
</head>
<body>
<?php $u = current_user(); if ($u): $route = current_route(); ?>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">🌐 dnsmasq-web</div>
        <nav>
            <?php
            $nav = [
                'dashboard' => ['Tableau de bord', '📊'],
                'hosts'     => ['DNS locaux',      '🖥️'],
                'cnames'    => ['Alias CNAME',      '🔗'],
                'dhcp'      => ['Réservations DHCP','📌'],
                'leases'    => ['Baux actifs',      '📋'],
                'blocklist' => ['Filtrage DNS',     '🛡️'],
                'settings'  => ['Paramètres',       '⚙️'],
            ];
            foreach ($nav as $key => [$label, $icon]):
                $active = $route === $key ? ' class="active"' : '';
            ?>
                <a href="<?= h(base_path()) ?>/<?= $key ?>"<?= $active ?>><span><?= $icon ?></span><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <span><?= h($u['username']) ?> (<?= h($u['role']) ?>)</span>
            <a href="<?= h(base_path()) ?>/logout" class="logout">Se déconnecter</a>
        </div>
    </aside>
    <main class="content">
        <?php foreach (flash_take() as $f): ?>
            <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
        <?php endforeach; ?>
<?php else: ?>
<div class="auth-wrap">
    <?php foreach (flash_take() as $f): ?>
        <div class="flash flash-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
