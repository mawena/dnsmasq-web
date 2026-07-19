<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $mode   = ($_POST['mode'] ?? 'block') === 'allow' ? 'allow' : 'block';
        if (!valid_domain($domain)) {
            flash_set('err', 'Domaine invalide.');
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO blocklist (domain, mode, comment) VALUES (?,?,?)');
                $st->execute([$domain, $mode, trim((string) ($_POST['comment'] ?? '')) ?: null]);
                log_action($pdo, 'block.add', "$mode $domain");
                apply_changes($pdo, $config);
            } catch (PDOException $e) {
                flash_set('err', 'Ce domaine est déjà dans la liste.');
            }
        }
    } elseif ($action === 'bulk') {
        // Import en masse : un domaine par ligne, mode "block".
        $added = 0;
        $st = $pdo->prepare('INSERT IGNORE INTO blocklist (domain, mode) VALUES (?, "block")');
        foreach (preg_split('/\R/', (string) ($_POST['domains'] ?? '')) as $line) {
            $d = strtolower(trim($line));
            if ($d !== '' && valid_domain($d)) {
                $st->execute([$d]);
                $added += $st->rowCount();
            }
        }
        log_action($pdo, 'block.bulk', "$added domaines importés");
        flash_set('ok', "$added domaine(s) ajouté(s).");
        apply_changes($pdo, $config);
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE blocklist SET enabled = 1 - enabled WHERE id = ?')->execute([(int) $_POST['id']]);
        apply_changes($pdo, $config);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM blocklist WHERE id = ?')->execute([(int) $_POST['id']]);
        log_action($pdo, 'block.delete', 'id=' . (int) $_POST['id']);
        apply_changes($pdo, $config);
    }
    redirect('/blocklist');
}

$rows = $pdo->query('SELECT * FROM blocklist ORDER BY domain')->fetchAll();
$title = 'Filtrage DNS';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head"><h1>Filtrage DNS</h1></div>
<p class="muted"><strong>Bloquer</strong> = le domaine et ses sous-domaines renvoient <code>0.0.0.0</code>.
   <strong>Autoriser</strong> = exception forçant une résolution normale.</p>

<div class="grid-2">
    <form class="card form-inline" method="post" action="<?= h(base_path()) ?>/blocklist">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Domaine<input type="text" name="domain" placeholder="ads.example.com" required></label>
        <label>Mode
            <select name="mode">
                <option value="block">Bloquer</option>
                <option value="allow">Autoriser</option>
            </select>
        </label>
        <label>Commentaire<input type="text" name="comment" placeholder="(optionnel)"></label>
        <button class="btn btn-primary" type="submit">Ajouter</button>
    </form>

    <form class="card" method="post" action="<?= h(base_path()) ?>/blocklist">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <label>Import en masse (un domaine par ligne, mode bloquer)
            <textarea name="domains" rows="4" placeholder="ads.example.com&#10;tracker.example.net"></textarea>
        </label>
        <button class="btn" type="submit">Importer</button>
    </form>
</div>

<div class="card">
    <table class="table">
        <thead><tr><th>Domaine</th><th>Mode</th><th>Commentaire</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted">Aucune règle.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="<?= $r['enabled'] ? '' : 'row-off' ?>">
                <td><?= h($r['domain']) ?></td>
                <td><span class="tag <?= $r['mode'] === 'block' ? 'tag-block' : 'tag-allow' ?>">
                    <?= $r['mode'] === 'block' ? 'Bloqué' : 'Autorisé' ?></span></td>
                <td class="muted"><?= h($r['comment']) ?></td>
                <td>
                    <form method="post" action="<?= h(base_path()) ?>/blocklist" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button class="badge <?= $r['enabled'] ? 'badge-on' : 'badge-off' ?>">
                            <?= $r['enabled'] ? 'Actif' : 'Inactif' ?>
                        </button>
                    </form>
                </td>
                <td class="actions">
                    <form method="post" action="<?= h(base_path()) ?>/blocklist" class="inline"
                          onsubmit="return confirm('Supprimer <?= h($r['domain']) ?> ?');">
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
