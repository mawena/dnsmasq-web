<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

// Action : réappliquer la configuration manuellement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'apply') {
        apply_changes($pdo, $config);
        log_action($pdo, 'apply', 'Application manuelle depuis le tableau de bord');
    }
    redirect('/dashboard');
}

$counts = [
    'hosts'     => (int) $pdo->query('SELECT COUNT(*) FROM dns_hosts')->fetchColumn(),
    'cnames'    => (int) $pdo->query('SELECT COUNT(*) FROM dns_cnames')->fetchColumn(),
    'dhcp'      => (int) $pdo->query('SELECT COUNT(*) FROM dhcp_reservations')->fetchColumn(),
    'blocklist' => (int) $pdo->query("SELECT COUNT(*) FROM blocklist WHERE mode='block'")->fetchColumn(),
];
$leases = read_leases($config['leases_file']);
$status = dnsmasq_status();
$recent = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();

$title = 'Tableau de bord';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head">
    <h1>Tableau de bord</h1>
    <form method="post" action="<?= h(base_path()) ?>/dashboard">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply">
        <button class="btn btn-primary" type="submit">🔄 Réappliquer la configuration</button>
    </form>
</div>

<div class="stat-grid">
    <div class="stat">
        <div class="stat-label">Service dnsmasq</div>
        <div class="stat-value <?= $status === 'active' ? 'ok' : 'bad' ?>"><?= h($status) ?></div>
    </div>
    <div class="stat"><div class="stat-label">DNS locaux</div><div class="stat-value"><?= $counts['hosts'] ?></div></div>
    <div class="stat"><div class="stat-label">Alias CNAME</div><div class="stat-value"><?= $counts['cnames'] ?></div></div>
    <div class="stat"><div class="stat-label">Réservations DHCP</div><div class="stat-value"><?= $counts['dhcp'] ?></div></div>
    <div class="stat"><div class="stat-label">Domaines bloqués</div><div class="stat-value"><?= $counts['blocklist'] ?></div></div>
    <div class="stat"><div class="stat-label">Baux actifs</div><div class="stat-value"><?= count($leases) ?></div></div>
</div>

<div class="card">
    <h2>Activité récente</h2>
    <table class="table">
        <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Détail</th></tr></thead>
        <tbody>
        <?php if (!$recent): ?>
            <tr><td colspan="4" class="muted">Aucune activité.</td></tr>
        <?php else: foreach ($recent as $r): ?>
            <tr>
                <td><?= h($r['created_at']) ?></td>
                <td><?= h($r['username'] ?? '—') ?></td>
                <td><span class="tag"><?= h($r['action']) ?></span></td>
                <td class="muted"><?= h($r['detail']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php require dirname(__DIR__, 2) . '/views/layout_bottom.php'; ?>
