<?php
declare(strict_types=1);
/** @var PDO $pdo */
/** @var array $config */

/** Enregistre un réglage (upsert). */
function save_setting(PDO $pdo, string $name, string $value): void
{
    $st = $pdo->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $st->execute([$name, $value]);
}

function get_setting(PDO $pdo, string $name, string $default = ''): string
{
    $st = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // — DNS général —
    $upstream = trim((string) ($_POST['upstream_dns'] ?? ''));
    foreach (preg_split('/[\s,]+/', $upstream, -1, PREG_SPLIT_NO_EMPTY) as $ip) {
        if (!valid_ip($ip)) { $errors[] = "Serveur upstream invalide : $ip"; }
    }
    $cache = trim((string) ($_POST['cache_size'] ?? ''));
    if ($cache !== '' && !valid_int($cache, 0, 100000)) { $errors[] = 'Taille de cache invalide.'; }
    $domain = trim((string) ($_POST['local_domain'] ?? ''));
    if ($domain !== '' && !valid_hostname($domain)) { $errors[] = 'Domaine local invalide.'; }

    // — DHCP —
    $dhcpEnabled = isset($_POST['dhcp_enabled']);
    $rs = trim((string) ($_POST['dhcp_range_start'] ?? ''));
    $re = trim((string) ($_POST['dhcp_range_end'] ?? ''));
    $mask = trim((string) ($_POST['dhcp_netmask'] ?? ''));
    $gw = trim((string) ($_POST['dhcp_gateway'] ?? ''));
    $dnsOpt = trim((string) ($_POST['dhcp_dns'] ?? ''));
    $lease = trim((string) ($_POST['dhcp_lease_time'] ?? '12h'));
    if ($dhcpEnabled) {
        if (!valid_ipv4($rs)) { $errors[] = 'Début de plage DHCP invalide.'; }
        if (!valid_ipv4($re)) { $errors[] = 'Fin de plage DHCP invalide.'; }
        if ($mask !== '' && !valid_ipv4($mask)) { $errors[] = 'Masque réseau invalide.'; }
        if ($gw !== '' && !valid_ipv4($gw)) { $errors[] = 'Passerelle invalide.'; }
        if (!preg_match('/^\d+[smhd]?$|^infinite$/', $lease)) { $errors[] = 'Durée de bail invalide (ex : 12h, 3600, infinite).'; }
    }

    if (!$errors) {
        save_setting($pdo, 'upstream_dns', $upstream);
        save_setting($pdo, 'domain_needed', isset($_POST['domain_needed']) ? '1' : '0');
        save_setting($pdo, 'bogus_priv', isset($_POST['bogus_priv']) ? '1' : '0');
        save_setting($pdo, 'cache_size', $cache);
        save_setting($pdo, 'local_domain', $domain);
        save_setting($pdo, 'dhcp_enabled', $dhcpEnabled ? '1' : '0');
        save_setting($pdo, 'dhcp_range_start', $rs);
        save_setting($pdo, 'dhcp_range_end', $re);
        save_setting($pdo, 'dhcp_netmask', $mask ?: '255.255.255.0');
        save_setting($pdo, 'dhcp_lease_time', $lease ?: '12h');
        save_setting($pdo, 'dhcp_gateway', $gw);
        save_setting($pdo, 'dhcp_dns', $dnsOpt);
        log_action($pdo, 'settings.save', 'Paramètres mis à jour');
        apply_changes($pdo, $config);
    } else {
        foreach ($errors as $e) { flash_set('err', $e); }
    }
    redirect('/settings');
}

$title = 'Paramètres';
require dirname(__DIR__, 2) . '/views/layout_top.php';
?>
<div class="page-head"><h1>Paramètres</h1></div>

<form method="post" action="<?= h(base_path()) ?>/settings">
    <?= csrf_field() ?>

    <div class="card">
        <h2>DNS général</h2>
        <label>Serveurs DNS upstream (séparés par des espaces/virgules)
            <input type="text" name="upstream_dns" value="<?= h(get_setting($pdo, 'upstream_dns')) ?>"
                   placeholder="1.1.1.1 8.8.8.8">
        </label>
        <label>Domaine local
            <input type="text" name="local_domain" value="<?= h(get_setting($pdo, 'local_domain')) ?>"
                   placeholder="lan">
        </label>
        <label>Taille du cache DNS
            <input type="text" name="cache_size" value="<?= h(get_setting($pdo, 'cache_size')) ?>"
                   placeholder="1000">
        </label>
        <label class="check">
            <input type="checkbox" name="domain_needed" <?= get_setting($pdo, 'domain_needed') === '1' ? 'checked' : '' ?>>
            Ne pas transférer les noms sans domaine (domain-needed)
        </label>
        <label class="check">
            <input type="checkbox" name="bogus_priv" <?= get_setting($pdo, 'bogus_priv') === '1' ? 'checked' : '' ?>>
            Ne pas transférer les reverse-DNS privés (bogus-priv)
        </label>
    </div>

    <div class="card">
        <h2>Serveur DHCP</h2>
        <label class="check">
            <input type="checkbox" name="dhcp_enabled" <?= get_setting($pdo, 'dhcp_enabled') === '1' ? 'checked' : '' ?>>
            Activer le serveur DHCP
        </label>
        <div class="grid-2">
            <label>Début de plage
                <input type="text" name="dhcp_range_start" value="<?= h(get_setting($pdo, 'dhcp_range_start')) ?>"
                       placeholder="192.168.0.100">
            </label>
            <label>Fin de plage
                <input type="text" name="dhcp_range_end" value="<?= h(get_setting($pdo, 'dhcp_range_end')) ?>"
                       placeholder="192.168.0.200">
            </label>
            <label>Masque réseau
                <input type="text" name="dhcp_netmask" value="<?= h(get_setting($pdo, 'dhcp_netmask', '255.255.255.0')) ?>">
            </label>
            <label>Durée de bail
                <input type="text" name="dhcp_lease_time" value="<?= h(get_setting($pdo, 'dhcp_lease_time', '12h')) ?>"
                       placeholder="12h">
            </label>
            <label>Passerelle (option 3)
                <input type="text" name="dhcp_gateway" value="<?= h(get_setting($pdo, 'dhcp_gateway')) ?>"
                       placeholder="192.168.0.1">
            </label>
            <label>DNS annoncé (option 6)
                <input type="text" name="dhcp_dns" value="<?= h(get_setting($pdo, 'dhcp_dns')) ?>"
                       placeholder="192.168.0.102">
            </label>
        </div>
    </div>

    <button class="btn btn-primary" type="submit">💾 Enregistrer et appliquer</button>
</form>
<?php require dirname(__DIR__, 2) . '/views/layout_bottom.php'; ?>
