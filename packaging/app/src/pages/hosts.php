<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $hostname = trim((string) ($_POST['hostname'] ?? ''));
        $ip       = trim((string) ($_POST['ip'] ?? ''));
        $comment  = trim((string) ($_POST['comment'] ?? ''));
        if (!valid_hostname($hostname)) {
            flash_set('err', 'Nom d\'hôte invalide.');
        } elseif (!valid_ip($ip)) {
            flash_set('err', 'Adresse IP invalide.');
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO dns_hosts (hostname, ip, comment) VALUES (?,?,?)');
                $st->execute([$hostname, $ip, $comment ?: null]);
                log_action($pdo, 'host.add', "$hostname → $ip");
                apply_changes($pdo, $config);
            } catch (PDOException $e) {
                flash_set('err', 'Ce couple nom/IP existe déjà.');
            }
        }
    } elseif ($action === 'toggle') {
        $st = $pdo->prepare('UPDATE dns_hosts SET enabled = 1 - enabled WHERE id = ?');
        $st->execute([(int) $_POST['id']]);
        apply_changes($pdo, $config);
    } elseif ($action === 'delete') {
        $st = $pdo->prepare('DELETE FROM dns_hosts WHERE id = ?');
        $st->execute([(int) $_POST['id']]);
        log_action($pdo, 'host.delete', 'id=' . (int) $_POST['id']);
        apply_changes($pdo, $config);
    }
    redirect('/hosts');
}

$rows = $pdo->query('SELECT * FROM dns_hosts ORDER BY hostname')->fetchAll();
$title = 'DNS locaux';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head"><h1>Enregistrements DNS locaux</h1></div>
<p class="muted">Associe un nom à une IP sur ton réseau (ex : <code>nas.local → 192.168.0.50</code>).</p>

<form class="card form-inline" method="post" action="<?= h(base_path()) ?>/hosts">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>Nom d'hôte<input type="text" name="hostname" placeholder="nas.local" required></label>
    <label>Adresse IP<input type="text" name="ip" placeholder="192.168.0.50" required></label>
    <label>Commentaire<input type="text" name="comment" placeholder="(optionnel)"></label>
    <button class="btn btn-primary" type="submit">Ajouter</button>
</form>

<div class="card">
    <table class="table">
        <thead><tr><th>Nom</th><th>IP</th><th>Commentaire</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted">Aucun enregistrement.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="<?= $r['enabled'] ? '' : 'row-off' ?>">
                <td><?= h($r['hostname']) ?></td>
                <td><code><?= h($r['ip']) ?></code></td>
                <td class="muted"><?= h($r['comment']) ?></td>
                <td>
                    <form method="post" action="<?= h(base_path()) ?>/hosts" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="badge <?= $r['enabled'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $r['enabled'] ? 'Actif' : 'Inactif' ?>
                        </button>
                    </form>
                </td>
                <td class="actions">
                    <form method="post" action="<?= h(base_path()) ?>/hosts" class="inline"
                          onsubmit="return confirm('Supprimer <?= h($r['hostname']) ?> ?');">
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
