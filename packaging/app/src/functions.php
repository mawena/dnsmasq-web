<?php
declare(strict_types=1);

/** Connexion PDO à MySQL. */
function db_connect(array $db): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']);
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** Échappe pour affichage HTML. */
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Redirection puis arrêt (pattern POST-Redirect-GET). */
function redirect(string $path): never
{
    header('Location: ' . base_path() . $path);
    exit;
}

/** Préfixe d'URL de l'app (si servie dans un sous-dossier). Vide par défaut. */
function base_path(): string
{
    return rtrim(getenv('DNSMASQ_WEB_BASE') ?: '', '/');
}

/** Route courante déduite de l'URL (ex: /hosts → "hosts"). */
function current_route(): string
{
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $route = trim($uri, '/');
    return $route === '' ? 'dashboard' : $route;
}

/* ── Messages flash (PRG) ─────────────────────────────────────────────── */
function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ── CSRF ─────────────────────────────────────────────────────────────── */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}
function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Jeton CSRF invalide. Rechargez la page.');
    }
}

/* ── Authentification ─────────────────────────────────────────────────── */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}
function require_login(): void
{
    if (!current_user()) {
        redirect('/login');
    }
}
function login_user(PDO $pdo, string $username, string $password): bool
{
    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role']];
        return true;
    }
    return false;
}
function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Journalise une action dans audit_log. */
function log_action(PDO $pdo, string $action, string $detail = ''): void
{
    $st = $pdo->prepare('INSERT INTO audit_log (username, action, detail) VALUES (?,?,?)');
    $st->execute([current_user()['username'] ?? null, $action, $detail]);
}

/** Régénère les .conf depuis la base et recharge dnsmasq. Renvoie [ok, sortie]. */
function apply_changes(PDO $pdo, array $config): array
{
    [$ok, $out] = generate_config($pdo, $config);
    if ($ok) {
        flash_set('ok', 'Configuration appliquée et dnsmasq rechargé.');
    } else {
        flash_set('err', 'Échec de l\'application : ' . $out);
    }
    return [$ok, $out];
}
