#!/bin/bash
#
# build.sh — Génère le paquet .deb dnsmasq-webui, l'archive localement et
# l'envoie sur le serveur de dépôt (dépôt exposé sur dnsmasqwebui.mawena.cloud).
#
# Usage : ./build.sh <version> "<commentaire>"
# Exemple : ./build.sh 1.0-1 "Version initiale"
#
set -e

if [ $# -lt 2 ]; then
    echo -e "\033[0;31mErreur : arguments manquants.\033[0m"
    echo "Usage : $0 <version> \"<commentaire>\""
    exit 1
fi

VERSION=$1
COMMENTAIRE=$2
export DEBFULLNAME="Charles Gamligo"
export DEBEMAIL="gamligocharles@gmail.com"

# Cible sur le serveur (mirror de l'infra lnmp, port SSH 2244).
REMOTE_HOST="mawena.cloud"
REMOTE_PORT="2244"
REMOTE_REPO="/var/www/dnsmasq-webui/repo/ubuntu"

echo -e "\033[0;34m=== Build dnsmasq-webui v$VERSION ===\033[0m"

[ -d packaging ] || { echo -e "\033[0;31mDossier 'packaging' introuvable.\033[0m"; exit 1; }
cd packaging

rm -f debian/changelog.dch

# Changelog (ignoré si déjà à la version demandée)
CURRENT=$(dpkg-parsechangelog -SVersion 2>/dev/null || echo "")
if [ "$CURRENT" = "$VERSION" ]; then
    echo -e "\033[1;33m-> Changelog déjà en $VERSION.\033[0m"
else
    echo -e "\033[0;32m-> Changelog ($CURRENT -> $VERSION)...\033[0m"
    dch -v "$VERSION" "$COMMENTAIRE"
fi

# Compilation binaire du paquet
echo -e "\033[0;32m-> debuild...\033[0m"
debuild -us -uc -b
cd ..

# Archivage local
echo -e "\033[0;32m-> Archivage local...\033[0m"
TARGET_DIR="dnsmasq-webui_${VERSION}"
mkdir -p "$TARGET_DIR"
mv dnsmasq-webui_${VERSION}* "$TARGET_DIR/" 2>/dev/null || true
mkdir -p archive
rm -rf "archive/$TARGET_DIR"
mv "$TARGET_DIR" archive/

# Transfert vers le serveur
DEB_FILE="archive/dnsmasq-webui_${VERSION}/dnsmasq-webui_${VERSION}_all.deb"
if [ -f "$DEB_FILE" ]; then
    echo -e "\033[0;34m-> Transfert vers ${REMOTE_HOST}:${REMOTE_REPO} (port ${REMOTE_PORT})...\033[0m"
    scp -P "$REMOTE_PORT" "$DEB_FILE" "${REMOTE_HOST}:${REMOTE_REPO}/"
    echo -e "\033[0;32m=== Build v$VERSION envoyé ! ===\033[0m"
    echo -e "Sur le serveur, lance : \033[0;33msudo /var/www/dnsmasq-webui/update_repo.sh\033[0m"
else
    echo -e "\033[0;31mFichier .deb introuvable : $DEB_FILE\033[0m"; exit 1
fi
