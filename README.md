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
| Exposition web | `/var/www/dnsmasq-webui` → `…/public` (symlink) |
| CLI | `/usr/bin/dnsmasq-webui` |
| Config runtime | `/etc/dnsmasq-webui/config.php` |
| `.conf` générés | `/etc/dnsmasq.d/webui/` |
| Wrapper root | `/usr/sbin/dnsmasq-webui-apply` |

## Structure du dépôt

```
build.sh              → génère le .deb (debuild) + scp vers le dépôt partagé
index.html            → page de documentation (install en 3 étapes)
packaging/
├── bin/dnsmasq-webui → CLI (install hooks + administration)
├── app/              → application PHP
└── debian/           → control, rules, changelog, postinst/prerm/postrm…
docs/                 → conception
```

## Installation (utilisateur final)

Le paquet est publié sur le **dépôt apt partagé mawena**
(`https://mawena.cloud/repo`, suite `stable`, composant `main`), signé GPG et
commun à `lnmp`, `dnsmasq-webui` et aux futurs paquets. Trois étapes :

```bash
# 1) Ajouter la clé GPG du dépôt
sudo curl -fsSL https://mawena.cloud/repo/public.key -o /etc/apt/keyrings/mawena-repository.asc

# 2) Ajouter le dépôt
echo "deb [signed-by=/etc/apt/keyrings/mawena-repository.asc] https://mawena.cloud/repo stable main" | sudo tee /etc/apt/sources.list.d/mawena.list

# 3) Installer
sudo apt update && sudo apt install dnsmasq-webui
```

Les étapes 1 et 2 (clé + source) ne se font qu'une fois : ensuite
`sudo apt install lnmp` (ou tout autre paquet mawena) suffit. Si
`/etc/apt/keyrings` n'existe pas : `sudo install -m 0755 -d /etc/apt/keyrings`
avant l'étape 1.

Puis créer le compte admin :

```bash
sudo dnsmasq-webui create-admin admin
```

Interface : `http://dnsmasq.mawena.local/` (ou via lnmp s'il est présent).

### Exposition web

Le paquet crée un symlink **`/var/www/dnsmasq-webui`** → `/usr/share/dnsmasq-webui/public`
(le code reste en FHS sous `/usr/share` ; `/var/www/dnsmasq-webui` est le chemin
d'exposition à pointer par le vhost).

- Si **lnmp** est installé, le paquet **ne crée pas** de vhost — tu exposes
  l'interface via lnmp avec `root /var/www/dnsmasq-webui`.
- Sinon, le paquet configure nginx sur `dnsmasq.mawena.local` (root =
  `/var/www/dnsmasq-webui`).

## Commandes `dnsmasq-webui`

| Commande | Rôle |
|----------|------|
| `create-admin <user> [role]` | Crée / met à jour un compte |
| `apply` | Régénère les `.conf` depuis MySQL + recharge |
| `status` | Diagnostic de santé complet |
| `backup [dir]` / `restore <f>` | Sauvegarde / restauration |
| `reconfigure` | Rejoue la configuration système (idempotent) |

## Dépôt apt partagé

Le paquet est distribué via le **dépôt partagé mawena** (signé, suite `stable`,
composant `main`), hébergé sur le VPS à `/var/www/html/Mawena/mawena/repo/` et
exposé sur `https://mawena.cloud/repo`. Le dossier `ubuntu/` contient TOUS les
`.deb` (lnmp, dnsmasq-webui, …) : un seul dépôt sert donc `apt install lnmp`,
`apt install dnsmasq-webui`, etc.

L'infrastructure du dépôt — indexation, **signature GPG**, publication de la clé
(`public.key`), vhost nginx sur `mawena.cloud` — est **partagée et gérée
directement sur le VPS** (côté lnmp). Ce projet n'en fait pas partie : il ne fait
que produire son `.deb` et le déposer dans le pool commun.

### Publier une nouvelle version

Une seule commande en local : `build.sh` compile, archive, envoie le `.deb` sur
le VPS **et** l'intègre au dépôt reprepro à distance (aucune connexion manuelle
au serveur).

```bash
./build.sh 1.1-1 "Correctifs"
```

En coulisses : `debuild` → `scp` du `.deb` dans `…/mawena/repo/ubuntu/` → `ssh`
qui lance `…/mawena/repo/update_repo.sh <deb>` (reprepro `includedeb stable`).
Le paquet est alors dispo côté client via `apt update && apt install dnsmasq-webui`.

> Seul `build.sh` est spécifique à dnsmasq-webui ; l'infra du dépôt (reprepro,
> clé GPG, vhost) est gérée côté serveur. La doc d'installation (3 étapes) est
> dans [index.html](index.html).

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
