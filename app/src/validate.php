<?php
declare(strict_types=1);

/**
 * validate.php — Validation stricte de toutes les entrées.
 *
 * CRITIQUE : ces valeurs finissent dans des fichiers de config dnsmasq.
 * Un champ mal filtré (retour à la ligne, virgule, directive) = injection
 * de configuration. On rejette tout ce qui n'est pas explicitement autorisé.
 */

function valid_ipv4(string $s): bool
{
    return filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function valid_ip(string $s): bool
{
    return filter_var($s, FILTER_VALIDATE_IP) !== false; // v4 ou v6
}

/** Nom d'hôte / domaine (labels alphanumériques + tirets, points). */
function valid_hostname(string $s): bool
{
    if ($s === '' || strlen($s) > 253) {
        return false;
    }
    return (bool) preg_match(
        '/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/',
        $s
    );
}

/** Domaine (identique à hostname ici ; doit contenir au moins un point). */
function valid_domain(string $s): bool
{
    return valid_hostname($s) && str_contains($s, '.');
}

/** Adresse MAC AA:BB:CC:DD:EE:FF (séparateur : ou -). */
function valid_mac(string $s): bool
{
    return (bool) preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $s);
}

/** Normalise une MAC en minuscules avec ":". */
function normalize_mac(string $s): string
{
    return strtolower(str_replace('-', ':', trim($s)));
}

function valid_int(string $s, int $min, int $max): bool
{
    return ctype_digit($s) && (int) $s >= $min && (int) $s <= $max;
}
