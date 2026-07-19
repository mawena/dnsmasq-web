# dnsmasq-webui

Paquet Debian/Ubuntu qui transforme une machine en **serveur DNS/DHCP de réseau
local** avec une **interface web d'administration**.

```bash
sudo apt install dnsmasq-webui
```

`apt` installe et configure la pile complète : dnsmasq devient le résolveur du
réseau (avec repli sur les DNS publics), le port 53 est libéré de
systemd-resolved, et l'interface web est prête.

## Fonctionnalités

- Enregistrements **DNS locaux** (nom → IP) et **alias CNAME**
- **Réservations DHCP** (IP fixe par MAC) + vue des **baux actifs**
- **Filtrage DNS** (blocage de domaines, style Pi-hole)
- **Paramètres** : DNS upstream, domaine local, cache, plage DHCP
- Authentification, CSRF, validation stricte, journal d'audit

## Architecture

MySQL est la **source de vérité**. L'app fait le CRUD en base, régénère les
fichiers `/etc/dnsmasq.d/webui/*.conf`, puis appelle un wrapper root
ultra-ciblé (`sudo dnsmasq-webui-apply`) qui valide et redémarre dnsmasq.
`www-data` n'a jamais les droits root.

| Élément | Emplacement |
|---------|-------------|
| Code PHP | `/usr/share/dnsmasq-webui/` (docroot = `public/`) |
| CLI | `/usr/bin/dnsmasq-webui` |
| Config runtime | `/etc/dnsmasq-webui/config.php` |
| `.conf` générés | `/etc/dnsmasq.d/webui/` |
| Wrapper root | `/usr/sbin/dnsmasq-webui-apply` |

## Structure du dépôt

```
build.sh              → génère le .deb (debuild) + scp vers le serveur
update_repo.sh        → (sur le serveur) régénère + signe l'index apt
packaging/
├── bin/dnsmasq-webui → CLI (install hooks + administration)
├── app/              → application PHP
└── debian/           → control, rules, changelog, postinst/prerm/postrm…
docs/                 → conception
```

## Installation (utilisateur final)

Le paquet est publié sur le dépôt apt **`https://dnsmasqwebui.mawena.cloud/repo/`**.
Deux commandes suffisent :

```bash
# 1) Ajouter le dépôt (clé GPG + source deb822)
curl -fsSL https://dnsmasqwebui.mawena.cloud/repo/dnsmasq-webui.asc \
  | sudo gpg --dearmor -o /usr/share/keyrings/dnsmasq-webui.gpg \
  && printf 'Types: deb\nURIs: https://dnsmasqwebui.mawena.cloud/repo/\nSuites: ./\nSigned-By: /usr/share/keyrings/dnsmasq-webui.gpg\n' \
  | sudo tee /etc/apt/sources.list.d/dnsmasq-webui.sources

# 2) Installer
sudo apt update && sudo apt install dnsmasq-webui
```

Variante « une commande » via le script hébergé ([setup.sh](setup.sh)) :

```bash
# 1) Ajouter le dépôt
curl -fsSL https://dnsmasqwebui.mawena.cloud/setup.sh | sudo bash
# 2) Installer
sudo apt install dnsmasq-webui
```

Puis créer le compte admin :

```bash
sudo dnsmasq-webui create-admin admin
```

Interface : `http://dnsmasq.mawena.local/` (ou via lnmp s'il est présent).

### Exposition web

- Si **lnmp** est installé, le paquet **ne crée pas** de vhost — tu exposes
  l'interface via lnmp (docroot `/usr/share/dnsmasq-webui/public`).
- Sinon, le paquet configure nginx sur `dnsmasq.mawena.local`.

## Commandes `dnsmasq-webui`

| Commande | Rôle |
|----------|------|
| `create-admin <user> [role]` | Crée / met à jour un compte |
| `apply` | Régénère les `.conf` depuis MySQL + recharge |
| `status` | Diagnostic de santé complet |
| `backup [dir]` / `restore <f>` | Sauvegarde / restauration |
| `reconfigure` | Rejoue la configuration système (idempotent) |

## Déploiement du serveur (git pull)

Le projet vit sur GitHub ; le VPS le récupère par `git pull`. Le dossier cloné
**est** le webroot exposé sur `dnsmasqwebui.mawena.cloud`.

### Mise en place (une fois)

```bash
# Sur le VPS
sudo git clone <url-github> /var/www/dnsmasq-webui
cd /var/www/dnsmasq-webui
sudo cp deploy/nginx-dnsmasqwebui.conf /etc/nginx/sites-available/dnsmasqwebui.mawena.cloud
sudo ln -s /etc/nginx/sites-available/dnsmasqwebui.mawena.cloud /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d dnsmasqwebui.mawena.cloud     # TLS
```

### Layout servi sur `dnsmasqwebui.mawena.cloud`

```
/var/www/dnsmasq-webui/          → dossier cloné = webroot
├── index.html                   → https://dnsmasqwebui.mawena.cloud/         (doc + commandes)
├── setup.sh                     → https://dnsmasqwebui.mawena.cloud/setup.sh (install 1 commande)
├── favicon.svg
├── update_repo.sh               → (non servi) régénère l'index du dépôt
├── deploy/nginx-*.conf          → (non servi) vhost de référence
├── packaging/, docs/, build.sh  → (non servis, masqués par le vhost)
└── repo/                        → https://dnsmasqwebui.mawena.cloud/repo/     (base apt)
    ├── ubuntu/                  → les .deb (Filename: ubuntu/…deb, gitignore)
    ├── Packages, Packages.gz    → (générés, gitignore)
    ├── Release, Release.gpg, InRelease
    └── dnsmasq-webui.asc        → clé publique GPG (générée)
```

Seuls `index.html`, `setup.sh`, `favicon.svg` et `repo/` sont servis ; le reste
(sources, `.git`, scripts) arrive par git mais reste masqué (voir le vhost).

### Publier une nouvelle version

```bash
# En local : bump changelog + build + envoi du .deb
./build.sh 1.1-1 "Correctifs"            # scp du .deb -> VPS:/var/www/dnsmasq-webui/repo/ubuntu/

# Sur le VPS : récupérer la doc/scripts à jour + régénérer l'index signé
cd /var/www/dnsmasq-webui && sudo git pull
sudo ./update_repo.sh                     # (re)génère Packages/Release/InRelease + clé
```

> Les `.deb` et l'index apt sont des artefacts (gitignore) : le code et la doc
> passent par git, le binaire par `scp` (ou un `debuild` directement sur le VPS).

## Cycle de vie du paquet

- `apt install` → configure tout (groupe `webdev`, dnsmasq, port 53, DB, web).
- `apt remove` → défait les changements système, **conserve** base + config.
- `apt purge` → efface aussi la base MySQL et `/etc/dnsmasq-webui`.
- Le groupe partagé `webdev` (venu de lnmp) n'est jamais supprimé.

## Sécurité

- `www-data` écrit uniquement dans `/etc/dnsmasq.d/webui/` (groupe `webdev`).
- Seule commande root autorisée : `/usr/sbin/dnsmasq-webui-apply` (sans
  argument), via `sudoers.d/dnsmasq-webui`.
- Dérogation systemd `ReadWritePaths` pour contourner `ProtectSystem=full`.
- Entrées strictement validées (anti-injection), CSRF, `password_hash`.
