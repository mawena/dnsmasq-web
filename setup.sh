#!/bin/sh
#
# setup.sh — Ajoute le dépôt apt dnsmasq-webui sur une machine Debian/Ubuntu.
#
# À déposer sur le serveur dans /var/www/dnsmasq-webui/ (webroot du sous-domaine),
# donc servi sur https://dnsmasqwebui.mawena.cloud/setup.sh.
#
# Usage côté client (en 2 commandes) :
#   curl -fsSL https://dnsmasqwebui.mawena.cloud/setup.sh | sudo bash
#   sudo apt install dnsmasq-webui
#
# POSIX sh, aucune dépendance à pré-installer (curl OU wget suffit).
set -e

DOMAIN="${DOMAIN:-dnsmasqwebui.mawena.cloud}"
BASE="https://${DOMAIN}/repo"
KEYRING="/usr/share/keyrings/dnsmasq-webui.gpg"
SOURCES="/etc/apt/sources.list.d/dnsmasq-webui.sources"

# Doit tourner en root (via sudo).
if [ "$(id -u)" -ne 0 ]; then
    echo "Ce script doit être lancé en root : curl -fsSL https://${DOMAIN}/setup.sh | sudo bash" >&2
    exit 1
fi

# Télécharge $1 vers stdout, avec curl ou wget.
fetch() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$1"
    elif command -v wget >/dev/null 2>&1; then
        wget -qO- "$1"
    else
        echo "Ni curl ni wget trouvé — installe l'un des deux d'abord." >&2
        exit 1
    fi
}

# gpg est nécessaire pour dé-armurer la clé.
if ! command -v gpg >/dev/null 2>&1; then
    echo "-> Installation de gnupg (requis pour la clé de signature)..."
    apt-get update -qq
    apt-get install -y --no-install-recommends gnupg
fi

echo "-> Import de la clé publique GPG -> ${KEYRING}"
fetch "${BASE}/dnsmasq-webui.asc" | gpg --dearmor -o "${KEYRING}"
chmod 644 "${KEYRING}"

echo "-> Écriture de la source apt -> ${SOURCES}"
cat > "${SOURCES}" <<EOF
Types: deb
URIs: ${BASE}/
Suites: ./
Signed-By: ${KEYRING}
EOF

echo "-> apt update..."
apt-get update

echo
echo "=== Dépôt dnsmasq-webui ajouté. ==="
echo "Installe maintenant :  sudo apt install dnsmasq-webui"
