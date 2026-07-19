<?php
declare(strict_types=1);
/** @var PDO $pdo */

if (current_user()) {
    redirect('/dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (login_user($pdo, $username, $password)) {
        redirect('/dashboard');
    }
    flash_set('err', 'Identifiants invalides.');
    redirect('/login');
}

$title = 'Connexion';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<form class="card login-card" method="post" action="<?= h(base_path()) ?>/login">
    <h1>🌐 dnsmasq-web</h1>
    <p class="muted">Administration de dnsmasq</p>
    <?= csrf_field() ?>
    <label>Utilisateur
        <input type="text" name="username" autofocus required autocomplete="username">
    </label>
    <label>Mot de passe
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn btn-primary">Se connecter</button>
</form>
<?php require dirname(__DIR__, 2) . '/views/layout_bottom.php'; ?>
