<?php
declare(strict_types=1);

/**
 * leases.php — Lecture (seule) du fichier des baux DHCP actifs de dnsmasq.
 *
 * Format d'une ligne : <expiration_epoch> <mac> <ip> <hostname> <client-id>
 */
function read_leases(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }
    $leases = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 5) {
            continue;
        }
        [$exp, $mac, $ip, $host] = $p;
        $leases[] = [
            'expires'  => (int) $exp,
            'mac'      => $mac,
            'ip'       => $ip,
            'hostname' => ($host === '*') ? '' : $host,
        ];
    }
    return $leases;
}

/** Statut du service dnsmasq via systemctl (lecture seule, sans sudo). */
function dnsmasq_status(): string
{
    $out = [];
    exec('systemctl is-active dnsmasq 2>/dev/null', $out);
    return trim($out[0] ?? 'inconnu');
}
