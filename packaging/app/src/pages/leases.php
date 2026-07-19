<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

$leases = read_leases($config['leases_file']);
// IPs déjà réservées (pour proposer un raccourci "réserver").
$reserved = array_column($pdo->query('SELECT mac FROM dhcp_reservations')->fetchAll(), 'mac');
$reserved = array_map('normalize_mac', $reserved);

usort($leases, fn($a, $b) => strcmp($a['ip'], $b['ip']));

$title = 'Baux actifs';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head"><h1>Baux DHCP actifs</h1></div>
<p class="muted">Lecture directe de <code><?= h($config['leases_file']) ?></code> (<?= count($leases) ?> bail·s).</p>

<div class="card">
    <table class="table">
        <thead><tr><th>IP</th><th>MAC</th><th>Nom</th><th>Expire</th><th></th></tr></thead>
        <tbody>
        <?php if (!$leases): ?>
            <tr><td colspan="5" class="muted">Aucun bail actif (ou fichier illisible).</td></tr>
        <?php else: foreach ($leases as $l):
            $isReserved = in_array(normalize_mac($l['mac']), $reserved, true); ?>
            <tr>
                <td><code><?= h($l['ip']) ?></code></td>
                <td><code><?= h($l['mac']) ?></code></td>
                <td><?= h($l['hostname'] ?: '—') ?></td>
                <td><?= $l['expires'] > 0 ? h(date('Y-m-d H:i', $l['expires'])) : 'permanent' ?></td>
                <td class="actions">
                    <?php if ($isReserved): ?>
                        <span class="tag tag-allow">Réservé</span>
                    <?php else: ?>
                        <form method="post" action="<?= h(base_path()) ?>/dhcp" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="mac" value="<?= h($l['mac']) ?>">
                            <input type="hidden" name="ip" value="<?= h($l['ip']) ?>">
                            <input type="hidden" name="hostname" value="<?= h($l['hostname']) ?>">
                            <button class="btn btn-sm">📌 Réserver cette IP</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php require dirname(__DIR__, 2) . '/views/layout_bottom.php'; ?>
