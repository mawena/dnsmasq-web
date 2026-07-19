<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $alias  = trim((string) ($_POST['alias'] ?? ''));
        $target = trim((string) ($_POST['target'] ?? ''));
        if (!valid_hostname($alias)) {
            flash_set('err', 'Alias invalide.');
        } elseif (!valid_hostname($target)) {
            flash_set('err', 'Cible invalide.');
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO dns_cnames (alias, target, comment) VALUES (?,?,?)');
                $st->execute([$alias, $target, trim((string) ($_POST['comment'] ?? '')) ?: null]);
                log_action($pdo, 'cname.add', "$alias → $target");
                apply_changes($pdo, $config);
            } catch (PDOException $e) {
                flash_set('err', 'Cet alias existe déjà.');
            }
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE dns_cnames SET enabled = 1 - enabled WHERE id = ?')->execute([(int) $_POST['id']]);
        apply_changes($pdo, $config);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM dns_cnames WHERE id = ?')->execute([(int) $_POST['id']]);
        log_action($pdo, 'cname.delete', 'id=' . (int) $_POST['id']);
        apply_changes($pdo, $config);
    }
    redirect('/cnames');
}

$rows = $pdo->query('SELECT * FROM dns_cnames ORDER BY alias')->fetchAll();
$title = 'Alias CNAME';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head"><h1>Alias CNAME</h1></div>
<p class="muted">Fait pointer un nom vers un autre nom (ex : <code>www.local → nas.local</code>).</p>

<form class="card form-inline" method="post" action="<?= h(base_path()) ?>/cnames">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label>Alias<input type="text" name="alias" placeholder="www.local" required></label>
    <label>Cible<input type="text" name="target" placeholder="nas.local" required></label>
    <label>Commentaire<input type="text" name="comment" placeholder="(optionnel)"></label>
    <button class="btn btn-primary" type="submit">Ajouter</button>
</form>

<div class="card">
    <table class="table">
        <thead><tr><th>Alias</th><th>Cible</th><th>Commentaire</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted">Aucun alias.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="<?= $r['enabled'] ? '' : 'row-off' ?>">
                <td><?= h($r['alias']) ?></td>
                <td><?= h($r['target']) ?></td>
                <td class="muted"><?= h($r['comment']) ?></td>
                <td>
                    <form method="post" action="<?= h(base_path()) ?>/cnames" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="badge <?= $r['enabled'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $r['enabled'] ? 'Actif' : 'Inactif' ?>
                        </button>
                    </form>
                </td>
                <td class="actions">
                    <form method="post" action="<?= h(base_path()) ?>/cnames" class="inline"
                          onsubmit="return confirm('Supprimer <?= h($r['alias']) ?> ?');">
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
