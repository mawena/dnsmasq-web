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

Une fois le dépôt apt ajouté (voir `update_repo.sh`) :

```bash
sudo apt update
sudo apt install dnsmasq-webui
sudo dnsmasq-webui create-admin admin      # créer le compte admin
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

## Développement / publication

```bash
./build.sh 1.0-1 "Version initiale"      # build + envoi sur mawena.cloud:2244
# puis sur le serveur :
sudo /var/www/dnsmasq-webui/repo/update_repo.sh
```

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
