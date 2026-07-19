#!/usr/bin/env bash
#
# installer.sh — Prépare un serveur (Ubuntu/Debian) pour héberger l'interface web
# d'administration de dnsmasq.
#
# Ce script est AUTO-SUFFISANT (tout est embarqué) et IDEMPOTENT (relançable).
# À exécuter EN ROOT sur le serveur cible (192.168.0.102) :
#
#     sudo bash installer.sh
#
# Ce qu'il met en place :
#   1. Paquets requis (dnsmasq, nginx, php-fpm, php-mysql, mariadb/mysql) si absents.
#   2. Un groupe "dnsweb" + ajout de www-data dedans.
#   3. Un dossier /etc/dnsmasq.d/webui/ inscriptible par le groupe (setgid),
#      dans lequel l'app PHP écrit ses .conf SANS sudo.
#   4. Le chargement de ce dossier par dnsmasq (fichier loader dans /etc/dnsmasq.d).
#   5. Un wrapper root /usr/local/sbin/dnsweb-apply (valide + redémarre dnsmasq),
#      appelable par www-data via une règle sudo NOPASSWD ULTRA-CIBLÉE.
#   6. Le fichier de baux (leases) lisible par le groupe.
#   7. La base MySQL + l'utilisateur + le schéma initial.
#   8. Le fichier de config de l'app (/etc/dnsmasq-web/config.php).
#   9. (Optionnel) un vhost nginx prêt à l'emploi.
#
set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────────
# CONFIGURATION  (ajuste ici si besoin)
# ─────────────────────────────────────────────────────────────────────────────
WEB_USER="www-data"                       # utilisateur sous lequel tourne php-fpm/nginx
DNSWEB_GROUP="dnsweb"                      # groupe privilégié partagé
WEBUI_DIR="/etc/dnsmasq.d/webui"          # dossier des .conf gérés par l'app
DNSMASQ_CONFD="/etc/dnsmasq.d"            # dossier conf-dir standard de dnsmasq
LEASES_FILE="/var/lib/misc/dnsmasq.leases" # fichier des baux DHCP actifs
APPLY_CMD="/usr/local/sbin/dnsweb-apply"  # wrapper root (valide + restart)
SUDOERS_FILE="/etc/sudoers.d/dnsweb"      # règle sudo ciblée
APP_DIR="/var/www/dnsmasq-web"            # racine de l'app (public/ servi par nginx)
APP_CONF_DIR="/etc/dnsmasq-web"           # config runtime de l'app (creds DB, chemins)
APP_CONF_FILE="${APP_CONF_DIR}/config.php"

DB_NAME="dnsmasq_web"
DB_USER="dnsweb"
DB_HOST="127.0.0.1"
DB_PASS=""                                # vide => généré automatiquement

SETUP_PACKAGES=1                          # 1 = installer les paquets manquants
SETUP_DATABASE=1                          # 1 = créer base + user + schéma
SETUP_NGINX=0                             # 1 = créer un vhost nginx
NGINX_SERVER_NAME="dnsmasq.local 192.168.0.102"

# ─────────────────────────────────────────────────────────────────────────────
# Helpers d'affichage
# ─────────────────────────────────────────────────────────────────────────────
c_info()  { printf '\033[1;34m[i]\033[0m %s\n' "$*"; }
c_ok()    { printf '\033[1;32m[✓]\033[0m %s\n' "$*"; }
c_warn()  { printf '\033[1;33m[!]\033[0m %s\n' "$*"; }
c_err()   { printf '\033[1;31m[✗]\033[0m %s\n' "$*" >&2; }
die()     { c_err "$*"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Ce script doit être lancé en root (sudo bash installer.sh)."
command -v apt-get >/dev/null 2>&1 || die "apt-get introuvable : ce script cible Debian/Ubuntu."

# ─────────────────────────────────────────────────────────────────────────────
# 1) Paquets
# ─────────────────────────────────────────────────────────────────────────────
if [ "$SETUP_PACKAGES" -eq 1 ]; then
  c_info "Vérification des paquets requis…"
  NEEDED=(dnsmasq nginx)

  # PHP-FPM + module mysql (on prend la version dispo dans les dépôts)
  if ! command -v php >/dev/null 2>&1; then NEEDED+=(php-fpm php-mysql); fi

  # Serveur MySQL/MariaDB : on n'installe que si aucun n'est présent
  if ! command -v mysql >/dev/null 2>&1 && ! command -v mariadb >/dev/null 2>&1; then
    NEEDED+=(mariadb-server)
  fi

  TO_INSTALL=()
  for pkg in "${NEEDED[@]}"; do
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then TO_INSTALL+=("$pkg"); fi
  done

  if [ "${#TO_INSTALL[@]}" -gt 0 ]; then
    c_info "Installation : ${TO_INSTALL[*]}"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq "${TO_INSTALL[@]}"
    c_ok "Paquets installés."
  else
    c_ok "Tous les paquets requis sont déjà présents."
  fi
else
  c_warn "SETUP_PACKAGES=0 : étape paquets ignorée."
fi

# Détection de la socket php-fpm (pour le vhost nginx)
PHP_FPM_SOCK="$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"

# ─────────────────────────────────────────────────────────────────────────────
# 2) Groupe privilégié + appartenance de www-data
# ─────────────────────────────────────────────────────────────────────────────
c_info "Configuration du groupe '${DNSWEB_GROUP}'…"
getent group "$DNSWEB_GROUP" >/dev/null 2>&1 || groupadd "$DNSWEB_GROUP"
if id -nG "$WEB_USER" | tr ' ' '\n' | grep -qx "$DNSWEB_GROUP"; then
  c_ok "${WEB_USER} est déjà dans ${DNSWEB_GROUP}."
else
  usermod -aG "$DNSWEB_GROUP" "$WEB_USER"
  c_ok "${WEB_USER} ajouté à ${DNSWEB_GROUP} (effectif après reload de php-fpm)."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 3) Dossier des .conf gérés par l'app (inscriptible par le groupe, setgid)
# ─────────────────────────────────────────────────────────────────────────────
c_info "Préparation de ${WEBUI_DIR}…"
mkdir -p "$WEBUI_DIR"
chown root:"$DNSWEB_GROUP" "$WEBUI_DIR"
# 2775 : rwx pour root & groupe, r-x pour autres, + setgid (bit 2) => les fichiers
# créés dans ce dossier héritent du groupe 'dnsweb'.
chmod 2775 "$WEBUI_DIR"

# Fichier initial pour que 'dnsmasq --test' passe même à vide
INIT_CONF="${WEBUI_DIR}/00-init.conf"
if [ ! -f "$INIT_CONF" ]; then
  cat > "$INIT_CONF" <<'EOF'
# Fichier généré par installer.sh — géré par l'interface web dnsmasq-web.
# Ne pas éditer à la main : le contenu de ce dossier est régénéré depuis MySQL.
EOF
  chown "$WEB_USER":"$DNSWEB_GROUP" "$INIT_CONF"
  chmod 664 "$INIT_CONF"
fi
c_ok "Dossier géré par l'app prêt (setgid + groupe ${DNSWEB_GROUP})."

# ─────────────────────────────────────────────────────────────────────────────
# 4) Chargement de WEBUI_DIR par dnsmasq
#    conf-dir n'est PAS récursif : on ajoute un loader explicite pour le sous-dossier.
#    Ce loader vit dans /etc/dnsmasq.d (déjà chargé par la conf par défaut).
# ─────────────────────────────────────────────────────────────────────────────
LOADER="${DNSMASQ_CONFD}/00-dnsweb-loader.conf"
c_info "Installation du loader dnsmasq (${LOADER})…"
cat > "$LOADER" <<EOF
# Généré par installer.sh — charge les fichiers gérés par l'interface web.
conf-dir=${WEBUI_DIR}/,*.conf
EOF
chown root:root "$LOADER"
chmod 644 "$LOADER"

# Sécurité : s'assurer que /etc/dnsmasq.d est bien chargé par la conf principale.
if [ -f /etc/dnsmasq.conf ]; then
  if ! grep -Eq '^\s*conf-dir=.*dnsmasq\.d' /etc/dnsmasq.conf; then
    c_warn "/etc/dnsmasq.conf ne semble pas charger ${DNSMASQ_CONFD} — ajout d'une ligne conf-dir."
    printf '\n# Ajouté par installer.sh\nconf-dir=%s/,*.conf\n' "$DNSMASQ_CONFD" >> /etc/dnsmasq.conf
  fi
else
  c_warn "/etc/dnsmasq.conf absent — création minimale chargeant ${DNSMASQ_CONFD}."
  printf '# Créé par installer.sh\nconf-dir=%s/,*.conf\n' "$DNSMASQ_CONFD" > /etc/dnsmasq.conf
fi
c_ok "dnsmasq chargera ${WEBUI_DIR}."

# ─────────────────────────────────────────────────────────────────────────────
# 5) Wrapper root : valide la conf puis redémarre dnsmasq
#    NB : un simple reload (SIGHUP) ne relit PAS les fichiers de conf,
#    d'où le restart pour prendre en compte les changements DNS/DHCP/blocage.
# ─────────────────────────────────────────────────────────────────────────────
c_info "Installation du wrapper ${APPLY_CMD}…"
cat > "$APPLY_CMD" <<'EOF'
#!/usr/bin/env bash
# dnsweb-apply — Valide la configuration dnsmasq puis la recharge.
# Appelé UNIQUEMENT via sudo par www-data (voir /etc/sudoers.d/dnsweb).
# N'accepte AUCUN argument : toute la sécurité repose là-dessus.
set -euo pipefail
if [ "$#" -ne 0 ]; then
  echo "dnsweb-apply n'accepte aucun argument." >&2
  exit 2
fi
# 1) Validation de la syntaxe (lit /etc/dnsmasq.conf et ses conf-dir).
if ! /usr/sbin/dnsmasq --test 2>&1; then
  echo "Validation dnsmasq échouée : configuration NON appliquée." >&2
  exit 1
fi
# 2) Redémarrage pour recharger toute la configuration.
/bin/systemctl restart dnsmasq
echo "dnsmasq rechargé avec succès."
EOF
chown root:root "$APPLY_CMD"
chmod 750 "$APPLY_CMD"   # exécuté en root via sudo ; non exécutable par les autres
c_ok "Wrapper installé."

# ─────────────────────────────────────────────────────────────────────────────
# 6) Règle sudo ULTRA-CIBLÉE : www-data ne peut lancer QUE ce wrapper, sans args
# ─────────────────────────────────────────────────────────────────────────────
c_info "Installation de la règle sudo (${SUDOERS_FILE})…"
cat > "$SUDOERS_FILE" <<EOF
# Généré par installer.sh — autorise l'interface web à recharger dnsmasq.
# ${WEB_USER} ne peut exécuter QUE ce script, en root, sans mot de passe.
${WEB_USER} ALL=(root) NOPASSWD: ${APPLY_CMD}
EOF
chmod 440 "$SUDOERS_FILE"
# Valide la syntaxe sudoers ; en cas d'erreur on retire le fichier pour ne pas casser sudo.
if ! visudo -cf "$SUDOERS_FILE" >/dev/null 2>&1; then
  rm -f "$SUDOERS_FILE"
  die "Règle sudoers invalide — fichier retiré. Aucun changement sudo appliqué."
fi
c_ok "Règle sudo validée."

# ─────────────────────────────────────────────────────────────────────────────
# 7) Fichier de baux lisible par le groupe (lecture seule pour l'app)
# ─────────────────────────────────────────────────────────────────────────────
c_info "Droits de lecture sur ${LEASES_FILE}…"
mkdir -p "$(dirname "$LEASES_FILE")"
[ -f "$LEASES_FILE" ] || : > "$LEASES_FILE"
chgrp "$DNSWEB_GROUP" "$LEASES_FILE" 2>/dev/null || true
chmod 640 "$LEASES_FILE" 2>/dev/null || true
c_ok "Baux DHCP lisibles par ${DNSWEB_GROUP}."

# ─────────────────────────────────────────────────────────────────────────────
# 8) Base MySQL / MariaDB
# ─────────────────────────────────────────────────────────────────────────────
if [ "$SETUP_DATABASE" -eq 1 ]; then
  c_info "Configuration de la base MySQL…"
  if [ -z "$DB_PASS" ]; then
    DB_PASS="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
    c_info "Mot de passe DB généré automatiquement."
  fi

  # On se connecte en root via la socket (auth_socket sur MariaDB/Ubuntu par défaut).
  MYSQL="mysql"
  if ! $MYSQL -e "SELECT 1" >/dev/null 2>&1; then
    die "Impossible de se connecter à MySQL en root via la socket. Configure l'accès puis relance."
  fi

  $MYSQL <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

  # Schéma initial (idempotent).
  $MYSQL "$DB_NAME" <<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Enregistrements DNS locaux (nom -> IP)
CREATE TABLE IF NOT EXISTS dns_hosts (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  hostname   VARCHAR(255) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  comment    VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_host_ip (hostname, ip)
) ENGINE=InnoDB;

-- Alias CNAME (alias -> cible)
CREATE TABLE IF NOT EXISTS dns_cnames (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  alias      VARCHAR(255) NOT NULL UNIQUE,
  target     VARCHAR(255) NOT NULL,
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  comment    VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Réservations DHCP (adresse fixe par MAC)
CREATE TABLE IF NOT EXISTS dhcp_reservations (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  mac        VARCHAR(17)  NOT NULL UNIQUE,
  ip         VARCHAR(45)  NOT NULL,
  hostname   VARCHAR(255) DEFAULT NULL,
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  comment    VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Domaines bloqués / autorisés (filtrage DNS)
CREATE TABLE IF NOT EXISTS blocklist (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  domain     VARCHAR(255) NOT NULL UNIQUE,
  mode       ENUM('block','allow') NOT NULL DEFAULT 'block',
  enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  comment    VARCHAR(255) DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Options générales (serveurs upstream, plage DHCP, etc.) sous forme clé/valeur
CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(64) PRIMARY KEY,
  value      TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Journal des actions (audit)
CREATE TABLE IF NOT EXISTS audit_log (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(64) DEFAULT NULL,
  action     VARCHAR(64) NOT NULL,
  detail     TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
SQL
  c_ok "Base '${DB_NAME}' + schéma prêts."
else
  c_warn "SETUP_DATABASE=0 : étape base de données ignorée."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 9) Fichier de config de l'app (lu par le futur code PHP)
# ─────────────────────────────────────────────────────────────────────────────
c_info "Écriture de la config de l'app (${APP_CONF_FILE})…"
mkdir -p "$APP_CONF_DIR"
cat > "$APP_CONF_FILE" <<EOF
<?php
// Généré par installer.sh — configuration runtime de dnsmasq-web.
return [
    'db' => [
        'host' => '${DB_HOST}',
        'name' => '${DB_NAME}',
        'user' => '${DB_USER}',
        'pass' => '${DB_PASS}',
    ],
    'webui_dir'   => '${WEBUI_DIR}',
    'leases_file' => '${LEASES_FILE}',
    'apply_cmd'   => 'sudo ${APPLY_CMD}',
];
EOF
# Lisible par www-data (groupe) mais pas par tout le monde.
chown root:"$WEB_USER" "$APP_CONF_FILE"
chmod 640 "$APP_CONF_FILE"
c_ok "Config de l'app écrite."

# Racine de l'app (l'app elle-même sera déployée ensuite)
mkdir -p "${APP_DIR}/public"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
if [ ! -f "${APP_DIR}/public/index.php" ]; then
  cat > "${APP_DIR}/public/index.php" <<'EOF'
<?php // Placeholder — remplacé par l'application dnsmasq-web.
echo "dnsmasq-web : serveur prêt. Déployez l'application dans " . __DIR__;
EOF
  chown "$WEB_USER":"$WEB_USER" "${APP_DIR}/public/index.php"
fi

# ─────────────────────────────────────────────────────────────────────────────
# 10) Vhost nginx (optionnel)
# ─────────────────────────────────────────────────────────────────────────────
if [ "$SETUP_NGINX" -eq 1 ]; then
  if [ -z "$PHP_FPM_SOCK" ]; then
    c_warn "Socket php-fpm introuvable — vhost nginx non créé (installe php-fpm puis relance)."
  else
    SITE="/etc/nginx/sites-available/dnsmasq-web"
    c_info "Création du vhost nginx (${SITE}) → ${PHP_FPM_SOCK}…"
    cat > "$SITE" <<EOF
server {
    listen 80;
    server_name ${NGINX_SERVER_NAME};
    root ${APP_DIR}/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    location ~ /\.(?!well-known) { deny all; }
}
EOF
    ln -sf "$SITE" /etc/nginx/sites-enabled/dnsmasq-web
    if nginx -t >/dev/null 2>&1; then
      systemctl reload nginx
      c_ok "Vhost nginx activé."
    else
      c_warn "nginx -t a échoué — vérifie la conf avant de recharger nginx."
    fi
  fi
else
  c_warn "SETUP_NGINX=0 : étape nginx ignorée."
fi

# ─────────────────────────────────────────────────────────────────────────────
# 11) Prise en compte : recharger php-fpm (nouveau groupe) + tester/redémarrer dnsmasq
# ─────────────────────────────────────────────────────────────────────────────
c_info "Rechargement des services…"
for svc in $(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
  systemctl restart "$svc" || true
done

if dnsmasq --test >/dev/null 2>&1; then
  systemctl enable dnsmasq >/dev/null 2>&1 || true
  systemctl restart dnsmasq || c_warn "Impossible de redémarrer dnsmasq — vérifie 'systemctl status dnsmasq'."
  c_ok "dnsmasq validé et redémarré."
else
  c_warn "'dnsmasq --test' a échoué — corrige la conf avant de démarrer dnsmasq."
fi

# ─────────────────────────────────────────────────────────────────────────────
# Récapitulatif
# ─────────────────────────────────────────────────────────────────────────────
echo
c_ok "Installation terminée."
echo   "─────────────────────────────────────────────────────────────"
echo   "  Dossier .conf géré par l'app : ${WEBUI_DIR}"
echo   "  Wrapper root (sudo)          : ${APPLY_CMD}"
echo   "  Règle sudo                   : ${SUDOERS_FILE}"
echo   "  Baux DHCP                    : ${LEASES_FILE}"
echo   "  Config de l'app              : ${APP_CONF_FILE}"
echo   "  Racine app (nginx root)      : ${APP_DIR}/public"
if [ "$SETUP_DATABASE" -eq 1 ]; then
echo   "  Base MySQL                   : ${DB_NAME} (user: ${DB_USER})"
echo   "  Mot de passe DB              : ${DB_PASS}"
fi
echo   "─────────────────────────────────────────────────────────────"
echo   "  Prochaine étape : déployer le code de l'application dans ${APP_DIR}."
