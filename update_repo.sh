#!/bin/bash
#
# update_repo.sh — À exécuter SUR LE SERVEUR (VPS mawena.cloud), en root.
# Régénère l'index du dépôt apt « flat » et le signe avec la clé GPG.
#
# Layout du dépôt (webroot exposé sur https://$DOMAIN/) :
#   /var/www/dnsmasq-webui/            <- webroot du sous-domaine
#   └── repo/
#       ├── ubuntu/                    <- les .deb (envoyés par build.sh)
#       ├── Packages, Packages.gz      <- index (chemins « ubuntu/xxx.deb »)
#       ├── Release, Release.gpg, InRelease
#       └── dnsmasq-webui.asc          <- clé publique GPG (à importer côté client)
#
# À placer dans /var/www/dnsmasq-webui/ et lancer après chaque envoi d'un
# nouveau .deb par build.sh. Réutilise la même clé GPG que le dépôt lnmp.
#
set -e

# Sous-domaine servant le dépôt (change-le ici si besoin, p. ex. dnsmasq.mawena.cloud).
DOMAIN="${DOMAIN:-dnsmasqwebui.mawena.cloud}"

# Racine du dépôt : les index (Packages/Release/InRelease) sont écrits ICI,
# les .deb vivent dans le sous-dossier $POOL.
REPO_ROOT="/var/www/dnsmasq-webui/repo"
POOL="ubuntu"

# Clé GPG de signature (ID/email de la clé du dépôt lnmp).
GPG_KEY="${GPG_KEY:-gamligocharles@gmail.com}"

[ -d "$REPO_ROOT/$POOL" ] || { echo "Dépôt introuvable : $REPO_ROOT/$POOL"; exit 1; }
cd "$REPO_ROOT"

echo "-> Génération de l'index Packages (chemins relatifs à $POOL/)..."
dpkg-scanpackages --multiversion "$POOL" > Packages
gzip -9kf Packages

echo "-> Génération du fichier Release..."
apt-ftparchive \
    -o APT::FTPArchive::Release::Origin="dnsmasq-webui" \
    -o APT::FTPArchive::Release::Label="dnsmasq-webui" \
    -o APT::FTPArchive::Release::Suite="stable" \
    -o APT::FTPArchive::Release::Codename="stable" \
    release . > Release

echo "-> Export de la clé publique -> dnsmasq-webui.asc..."
gpg --armor --export "$GPG_KEY" > dnsmasq-webui.asc

echo "-> Signature GPG (clé : $GPG_KEY)..."
rm -f Release.gpg InRelease
gpg --default-key "$GPG_KEY" --batch --yes -abs -o Release.gpg Release
gpg --default-key "$GPG_KEY" --batch --yes --clearsign -o InRelease Release

# Droits de lecture pour nginx
chmod 644 Packages Packages.gz Release Release.gpg InRelease dnsmasq-webui.asc

echo "=== Dépôt mis à jour ==="
echo "Install côté client (Ubuntu/Debian), en 2 commandes :"
echo
echo "  # 1) Ajouter le dépôt (clé + source)"
echo "  curl -fsSL https://${DOMAIN}/repo/dnsmasq-webui.asc | sudo gpg --dearmor -o /usr/share/keyrings/dnsmasq-webui.gpg \\"
echo "    && printf 'Types: deb\\nURIs: https://${DOMAIN}/repo/\\nSuites: ./\\nSigned-By: /usr/share/keyrings/dnsmasq-webui.gpg\\n' | sudo tee /etc/apt/sources.list.d/dnsmasq-webui.sources"
echo
echo "  # 2) Installer"
echo "  sudo apt update && sudo apt install dnsmasq-webui"
