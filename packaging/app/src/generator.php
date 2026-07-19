<?php
declare(strict_types=1);

/**
 * generator.php — Régénère les fichiers .conf de dnsmasq depuis MySQL,
 * puis appelle le wrapper root (sudo dnsmasq-webui-apply) qui valide et redémarre.
 *
 * MySQL est la SOURCE DE VÉRITÉ : on n'édite jamais les .conf à la main,
 * on les réécrit intégralement à chaque changement.
 */

/** Lit un réglage clé/valeur avec valeur par défaut. */
function setting(PDO $pdo, string $name, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT name, value FROM settings') as $row) {
            $cache[$row['name']] = $row['value'];
        }
    }
    return $cache[$name] ?? $default;
}

/**
 * Génère tous les fichiers puis applique.
 * @return array{0: bool, 1: string} [succès, sortie du wrapper ou message]
 */
function generate_config(PDO $pdo, array $config): array
{
    $dir = $config['webui_dir'];
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, "Dossier non inscriptible : $dir (www-data est-il dans le groupe webdev ?)"];
    }

    $files = [
        '00-general.conf'   => gen_general($pdo),
        '10-hosts.conf'     => gen_hosts($pdo),
        '20-cnames.conf'    => gen_cnames($pdo),
        '30-dhcp.conf'      => gen_dhcp($pdo),
        '40-blocklist.conf' => gen_blocklist($pdo),
    ];

    // Écriture atomique : fichier temporaire dans le même dossier puis rename.
    foreach ($files as $name => $content) {
        $path = $dir . '/' . $name;
        $tmp  = $path . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            return [false, "Écriture impossible : $path"];
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return [false, "Renommage impossible : $path"];
        }
    }

    // Validation + rechargement via le wrapper root.
    $out = [];
    $code = 0;
    exec($config['apply_cmd'] . ' 2>&1', $out, $code);
    $output = trim(implode("\n", $out));
    return [$code === 0, $output === '' ? "(code $code)" : $output];
}

function header_comment(string $title): string
{
    return "# {$title}\n# Fichier généré automatiquement par dnsmasq-web — NE PAS ÉDITER.\n"
        . "# Source de vérité : base MySQL. Régénéré à chaque changement.\n\n";
}

function gen_general(PDO $pdo): string
{
    $out = header_comment('Options générales');

    // Serveurs DNS upstream.
    $upstream = trim((string) setting($pdo, 'upstream_dns', ''));
    if ($upstream !== '') {
        $out .= "no-resolv\n";
        foreach (preg_split('/[\s,]+/', $upstream, -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            if (valid_ip($ip)) {
                $out .= "server=$ip\n";
            }
        }
    }

    if (setting($pdo, 'domain_needed') === '1') { $out .= "domain-needed\n"; }
    if (setting($pdo, 'bogus_priv')    === '1') { $out .= "bogus-priv\n"; }

    $cache = setting($pdo, 'cache_size', '');
    if ($cache !== '' && ctype_digit((string) $cache)) {
        $out .= "cache-size=$cache\n";
    }

    $domain = trim((string) setting($pdo, 'local_domain', ''));
    if ($domain !== '' && valid_hostname($domain)) {
        $out .= "domain=$domain\n";
        $out .= "local=/$domain/\n";
        $out .= "expand-hosts\n";
    }

    return $out;
}

function gen_hosts(PDO $pdo): string
{
    $out = header_comment('Enregistrements DNS locaux (host-record)');
    $st = $pdo->query('SELECT hostname, ip FROM dns_hosts WHERE enabled = 1 ORDER BY hostname');
    foreach ($st as $r) {
        if (valid_hostname($r['hostname']) && valid_ip($r['ip'])) {
            $out .= "host-record={$r['hostname']},{$r['ip']}\n";
        }
    }
    return $out;
}

function gen_cnames(PDO $pdo): string
{
    $out = header_comment('Alias CNAME');
    $st = $pdo->query('SELECT alias, target FROM dns_cnames WHERE enabled = 1 ORDER BY alias');
    foreach ($st as $r) {
        if (valid_hostname($r['alias']) && valid_hostname($r['target'])) {
            $out .= "cname={$r['alias']},{$r['target']}\n";
        }
    }
    return $out;
}

function gen_dhcp(PDO $pdo): string
{
    $out = header_comment('DHCP : plage + réservations');

    if (setting($pdo, 'dhcp_enabled') === '1') {
        $start = trim((string) setting($pdo, 'dhcp_range_start', ''));
        $end   = trim((string) setting($pdo, 'dhcp_range_end', ''));
        $mask  = trim((string) setting($pdo, 'dhcp_netmask', '255.255.255.0'));
        $lease = trim((string) setting($pdo, 'dhcp_lease_time', '12h'));
        if (valid_ipv4($start) && valid_ipv4($end)) {
            $out .= "dhcp-range=$start,$end,$mask,$lease\n";
        }
        $gw = trim((string) setting($pdo, 'dhcp_gateway', ''));
        if (valid_ipv4($gw)) { $out .= "dhcp-option=3,$gw\n"; }
        $dns = trim((string) setting($pdo, 'dhcp_dns', ''));
        if ($dns !== '') {
            $ips = array_filter(preg_split('/[\s,]+/', $dns, -1, PREG_SPLIT_NO_EMPTY), 'valid_ipv4');
            if ($ips) { $out .= 'dhcp-option=6,' . implode(',', $ips) . "\n"; }
        }
        $out .= "\n";
    }

    $st = $pdo->query('SELECT mac, ip, hostname FROM dhcp_reservations WHERE enabled = 1 ORDER BY ip');
    foreach ($st as $r) {
        if (!valid_mac($r['mac']) || !valid_ipv4($r['ip'])) {
            continue;
        }
        $line = 'dhcp-host=' . normalize_mac($r['mac']);
        if (!empty($r['hostname']) && valid_hostname($r['hostname'])) {
            $line .= ',' . $r['hostname'];
        }
        $line .= ',' . $r['ip'];
        $out .= $line . "\n";
    }
    return $out;
}

function gen_blocklist(PDO $pdo): string
{
    $out = header_comment('Filtrage DNS (blocage / exceptions)');
    $st = $pdo->query('SELECT domain, mode FROM blocklist WHERE enabled = 1 ORDER BY domain');
    foreach ($st as $r) {
        if (!valid_domain($r['domain'])) {
            continue;
        }
        if ($r['mode'] === 'block') {
            // Renvoie 0.0.0.0 (et :: en IPv6) pour tout le domaine et ses sous-domaines.
            $out .= "address=/{$r['domain']}/0.0.0.0\n";
            $out .= "address=/{$r['domain']}/::\n";
        } else { // allow : force une résolution normale (exception à une règle large)
            $out .= "server=/{$r['domain']}/#\n";
        }
    }
    return $out;
}
