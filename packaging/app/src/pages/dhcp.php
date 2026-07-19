<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $mac      = normalize_mac((string) ($_POST['mac'] ?? ''));
        $ip       = trim((string) ($_POST['ip'] ?? ''));
        $hostname = trim((string) ($_POST['hostname'] ?? ''));
        if (!valid_mac($mac)) {
            flash_set('err', 'Adresse MAC invalide.');
        } elseif (!valid_ipv4($ip)) {
            flash_set('err', 'Adresse IPv4 invalide.');
        } elseif ($hostname !== '' && !valid_hostname($hostname)) {
            flash_set('err', 'Nom d\'hôte invalide.');
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO dhcp_reservations (mac, ip, hostname, comment) VALUES (?,?,?,?)');
                $st->execute([$mac, $ip, $hostname ?: null, trim((string) ($_POST['comment'] ?? '')) ?: null]);
                log_action($pdo, 'dhcp.add', "$mac → $ip");
                apply_changes($pdo, $config);
            } catch (PDOException $e) {
                flash_set('err', 'Cette adresse MAC est déjà réservée.');
            }
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE dhcp_reservations SET enabled = 1 - enabled WHERE id = ?')->execute([(int) $_POST['id']]);
        apply_changes($pdo, $config);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM dhcp_reservations WHERE id = ?')->execute([(int) $_POST['id']]);
        log_action($pdo, 'dhcp.delete', 'id=' . (int) $_POST['id']);
        apply_changes($pdo, $config);
    }
    redirect('/dhcp');
}

$rows = $pdo->query('SELECT * FROM dhcp_reservations ORDER BY INET_ATON(ip)')->fetchAll();
$title = 'Réservations DHCP';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head"><h1>Réservations DHCP</h1></div>
<p class="muted">Attribue toujours la même IP à une machine identifiée par sa MAC.
   <?php if (setting($pdo, 'dhcp_enabled') !== '1'): ?>
   <strong>⚠️ Le serveur DHCP est désactivé</strong> — active-le dans <a href="<?= h(base_path()) ?>/settings">Paramètres</a>.
   <?php endif; ?>
</p>

<form class="card form-inline" method="post" action="<?= h(base_path()) ?>/dhcp">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>Adresse MAC<input type="text" name="mac" placeholder="aa:bb:cc:dd:ee:ff" required></label>
    <label>Adresse IP<input type="text" name="ip" placeholder="192.168.0.60" required></label>
    <label>Nom d'hôte<input type="text" name="hostname" placeholder="(optionnel)"></label>
    <label>Commentaire<input type="text" name="comment" placeholder="(optionnel)"></label>
    <button class="btn btn-primary" type="submit">Ajouter</button>
</form>

<div class="card">
    <table class="table">
        <thead><tr><th>MAC</th><th>IP</th><th>Nom</th><th>Commentaire</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted">Aucune réservation.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="<?= $r['enabled'] ? '' : 'row-off' ?>">
                <td><code><?= h($r['mac']) ?></code></td>
                <td><code><?= h($r['ip']) ?></code></td>
                <td><?= h($r['hostname']) ?></td>
                <td class="muted"><?= h($r['comment']) ?></td>
                <td>
                    <form method="post" action="<?= h(base_path()) ?>/dhcp" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="badge <?= $r['enabled'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $r['enabled'] ? 'Actif' : 'Inactif' ?>
                        </button>
                    </form>
                </td>
                <td class="actions">
                    <form method="post" action="<?= h(base_path()) ?>/dhcp" class="inline"
                          onsubmit="return confirm('Supprimer la réservation <?= h($r['mac']) ?> ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-danger btn-sm">Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php require dirname(__DIR__, 2) . '/views/layout_bottom.php'; ?>
