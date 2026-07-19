# dnsmasq-web

Interface web (PHP natif, zéro dépendance) pour administrer **dnsmasq** :
DNS locaux, alias CNAME, réservations DHCP, baux actifs, filtrage DNS,
paramètres généraux + service.

**Architecture :** MySQL est la source de vérité. L'app fait le CRUD en base,
régénère les fichiers `/etc/dnsmasq.d/webui/*.conf`, puis appelle un wrapper
root ultra-ciblé (`sudo dnsweb-apply`) qui valide et redémarre dnsmasq.
`www-data` n'a jamais les droits root.

## Arborescence

```
dwc                       → outil de gestion unique (install/uninstall/…)
app/
  public/                 → docroot nginx (index.php + assets)
  src/                    → logique (bootstrap, générateur, validation, pages)
  views/                  → gabarits HTML
  bin/                    → outils CLI (create-admin, apply)
```

## L'outil `dwc`

Toutes les opérations passent par un script unique, à lancer **en root depuis
le dossier du dépôt** :

| Commande | Rôle |
|----------|------|
| `sudo bash dwc install` | Met en place toute la plomberie système |
| `sudo bash dwc uninstall` | Retire tout (`--purge` supprime aussi la base) |
| `sudo bash dwc create-admin <user>` | Crée / met à jour un compte admin |
| `sudo bash dwc apply` | Régénère les `.conf` depuis MySQL + recharge dnsmasq |
| `sudo bash dwc status` | Diagnostic de santé complet |
| `sudo bash dwc backup [dir]` | Archive base + `.conf` + config en `.tar.gz` |
| `sudo bash dwc restore <file>` | Restaure une archive puis réapplique |
| `sudo bash dwc help` | Aide |

## Déploiement

### 1. Cloner le dépôt sur le serveur

```bash
sudo git clone <url-du-dépôt> /var/www/dnsmasq-web
cd /var/www/dnsmasq-web
```

### 2. Installer la plomberie système

```bash
sudo bash dwc install
```

Note le **mot de passe MySQL** affiché à la fin (aussi écrit dans
`/etc/dnsmasq-web/config.php`).

### 3. Créer le compte administrateur

```bash
sudo bash dwc create-admin admin
```

### 4. Configurer nginx (docroot)

Le docroot est **`/var/www/dnsmasq-web/app/public`**. Exemple de vhost :

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/dnsmasq-web/app/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;  # adapte la version
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

> ⚠️ Le docroot doit être `app/public`, **pas** la racine du clone — ainsi
> `.git`, `dwc`, `src/`, `views/`, `bin/` ne sont jamais servis sur le web.

### 5. Accéder à l'interface

Ouvre l'URL de ton serveur et connecte-toi.

## Vérifier / dépanner

```bash
sudo bash dwc status
```

- **« www-data dans dnsweb » ✗** : relance `sudo systemctl restart php*-fpm`.
- **« Connexion base » ✗** : vérifie `/etc/dnsmasq-web/config.php`.
- **« Dossier .conf inscriptible » ✗** : `www-data` pas encore dans le groupe
  (reload php-fpm).

## Sécurité

- `www-data` écrit uniquement dans `/etc/dnsmasq.d/webui/` (groupe `dnsweb`).
- Seule commande root autorisée : `/usr/local/sbin/dnsweb-apply` (sans
  argument), via `sudoers.d/dnsweb`.
- Entrées strictement validées avant écriture en conf (anti-injection).
- CSRF sur toutes les actions, sessions durcies, mots de passe `password_hash`.
