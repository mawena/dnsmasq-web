#!/bin/bash
#
# update_repo.sh — À exécuter SUR LE SERVEUR (mawena.cloud), en root.
# Régénère l'index du dépôt apt « flat » et le signe avec la clé GPG.
#
# À placer dans /var/www/dnsmasq-webui/repo/ et lancer après chaque envoi d'un
# nouveau .deb par build.sh. Réutilise la même clé GPG que le dépôt lnmp.
#
set -e

REPO_DIR="/var/www/dnsmasq-webui/repo/ubuntu"
# Clé GPG de signature (mets ici l'ID/email de la clé de ton dépôt lnmp).
GPG_KEY="${GPG_KEY:-gamligocharles@gmail.com}"

[ -d "$REPO_DIR" ] || { echo "Dépôt introuvable : $REPO_DIR"; exit 1; }
cd "$REPO_DIR"

echo "-> Génération de l'index Packages..."
dpkg-scanpackages --multiversion . > Packages
gzip -9kf Packages

echo "-> Génération du fichier Release..."
apt-ftparchive \
    -o APT::FTPArchive::Release::Origin="dnsmasq-webui" \
    -o APT::FTPArchive::Release::Label="dnsmasq-webui" \
    -o APT::FTPArchive::Release::Suite="stable" \
    -o APT::FTPArchive::Release::Codename="stable" \
    release . > Release

echo "-> Signature GPG (clé : $GPG_KEY)..."
rm -f Release.gpg InRelease
gpg --default-key "$GPG_KEY" --batch --yes -abs -o Release.gpg Release
gpg --default-key "$GPG_KEY" --batch --yes --clearsign -o InRelease Release

# Droits de lecture pour nginx
chmod 644 Packages Packages.gz Release Release.gpg InRelease

echo "=== Dépôt mis à jour ==="
echo "Sur un client Ubuntu, pour installer :"
echo "  1) importe la clé publique GPG du dépôt"
echo "  2) echo 'deb [signed-by=/usr/share/keyrings/dnsmasq-webui.gpg] http://${HOSTNAME:-mawena.cloud}/dnsmasq-webui/repo/ubuntu ./' | sudo tee /etc/apt/sources.list.d/dnsmasq-webui.list"
echo "  3) sudo apt update && sudo apt install dnsmasq-webui"
