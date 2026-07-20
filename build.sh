#!/bin/bash
#
# build.sh — Génère le paquet .deb dnsmasq-webui, l'archive localement, l'envoie
# sur le VPS et l'intègre au dépôt apt partagé (reprepro) — le tout sans aucune
# connexion interactive au serveur.
#
# Usage   : ./build.sh <version> "<commentaire>"
# Exemple : ./build.sh 1.0-1 "Version initiale"
#
set -e

# Vérification des arguments
if [ $# -lt 2 ]; then
    echo -e "\033[0;31mErreur : arguments manquants.\033[0m"
    echo "Usage : $0 <version> \"<commentaire>\""
    echo "Exemple : $0 1.0-1 \"Version initiale\""
    exit 1
fi

VERSION=$1
COMMENTAIRE=$2

# Variables Debian pour dch
export DEBFULLNAME="Charles Gamligo"
export DEBEMAIL="gamligocharles@gmail.com"

# Cible : dépôt apt PARTAGÉ multi-paquets (lnmp, dnsmasq-webui, …), géré par
# reprepro sur le VPS. update_repo.sh (côté serveur) intègre le .deb dans la
# suite « stable ». Port SSH 2244.
REMOTE_HOST="mawena.cloud"
REMOTE_PORT="2244"
REMOTE_REPO="/var/www/html/Mawena/mawena/repo"   # racine du dépôt reprepro

echo -e "\033[0;34m=== Début du build dnsmasq-webui v$VERSION ===\033[0m"

# 1. Entrer dans le dossier source
[ -d packaging ] || { echo -e "\033[0;31mDossier 'packaging' introuvable.\033[0m"; exit 1; }
cd packaging

# 2. Nettoyer le fichier temporaire
rm -f debian/changelog.dch

# 3. Mise à jour du changelog (ignorée si déjà à la version demandée, pour éviter
#    une entrée en double quand le changelog a été édité à la main)
CURRENT_CL_VERSION=$(dpkg-parsechangelog -SVersion 2>/dev/null || echo "")
if [ "$CURRENT_CL_VERSION" = "$VERSION" ]; then
    echo -e "\033[1;33m-> Changelog déjà en $VERSION : étape 'dch' ignorée.\033[0m"
else
    echo -e "\033[0;32m-> Mise à jour du changelog ($CURRENT_CL_VERSION -> $VERSION)...\033[0m"
    dch -v "$VERSION" "$COMMENTAIRE"
fi

# 4. Compilation du paquet Debian
echo -e "\033[0;32m-> Compilation du paquet avec debuild...\033[0m"
debuild -us -uc -b

# 5. Revenir au dossier parent
cd ..

# 6. Organiser les fichiers générés dans l'archive locale
echo -e "\033[0;32m-> Archivage local des fichiers générés...\033[0m"
TARGET_DIR="dnsmasq-webui_${VERSION}"
mkdir -p "$TARGET_DIR"
mv dnsmasq-webui_${VERSION}* "$TARGET_DIR/" 2>/dev/null || true

mkdir -p archive
rm -rf "archive/$TARGET_DIR"   # Nettoyer une éventuelle archive locale du même nom
mv "$TARGET_DIR" archive/

# 7. Transfert + intégration au dépôt reprepro (déclenchés à distance)
DEB_NAME="dnsmasq-webui_${VERSION}_all.deb"
DEB_FILE="archive/dnsmasq-webui_${VERSION}/${DEB_NAME}"

[ -f "$DEB_FILE" ] || { echo -e "\033[0;31mFichier .deb introuvable : $DEB_FILE\033[0m"; exit 1; }

echo -e "\033[0;34m-> Transfert du .deb vers $REMOTE_HOST (port $REMOTE_PORT)...\033[0m"
scp -P "$REMOTE_PORT" "$DEB_FILE" "${REMOTE_HOST}:${REMOTE_REPO}/ubuntu/"

echo -e "\033[0;34m-> Intégration au dépôt reprepro à distance...\033[0m"
ssh -p "$REMOTE_PORT" "$REMOTE_HOST" "${REMOTE_REPO}/update_repo.sh ${REMOTE_REPO}/ubuntu/${DEB_NAME}"

echo -e "\033[0;32m=== Build v$VERSION envoyé et intégré au dépôt « stable » ! ===\033[0m"
echo -e "Côté client : \033[0;33msudo apt update && sudo apt install dnsmasq-webui\033[0m"
