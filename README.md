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
installer.sh              → prépare le serveur (groupe, sudoers, DB, nginx…)
app/
  public/                 → racine servie par nginx (index.php + assets)
  src/                    → logique (bootstrap, générateur, validation, pages)
  views/                  → gabarits HTML
  bin/create-admin.php    → crée le 1er compte admin (CLI)
```

## Déploiement sur le serveur

### 1. Préparer le serveur

```bash
sudo bash installer.sh
```

Note le **mot de passe MySQL** affiché à la fin (il est aussi écrit dans
`/etc/dnsmasq-web/config.php`).

### 2. Déployer le code

Copie le contenu de `app/` vers la racine créée par l'installateur :

```bash
sudo rsync -a app/ /var/www/dnsmasq-web/
sudo chown -R www-data:www-data /var/www/dnsmasq-web
```

Le vhost nginx pointe déjà vers `/var/www/dnsmasq-web/public`.

### 3. Créer le compte administrateur

```bash
cd /var/www/dnsmasq-web
sudo -u www-data php bin/create-admin.php admin
```

### 4. Accéder à l'interface

Ouvre `http://localhost/` et connecte-toi.

## Sécurité

- `www-data` écrit uniquement dans `/etc/dnsmasq.d/webui/` (groupe `dnsweb`).
- La seule commande root autorisée est `/usr/local/sbin/dnsweb-apply`
  (sans argument), via `sudoers.d/dnsweb`.
- Toutes les entrées sont strictement validées avant d'être écrites en conf.
- CSRF sur toutes les actions, sessions durcies, mots de passe `password_hash`.

## Configuration

`/etc/dnsmasq-web/config.php` (généré par l'installateur) :

```php
return [
    'db'          => ['host' => '127.0.0.1', 'name' => 'dnsmasq_web', 'user' => 'dnsweb', 'pass' => '...'],
    'webui_dir'   => '/etc/dnsmasq.d/webui',
    'leases_file' => '/var/lib/misc/dnsmasq.leases',
    'apply_cmd'   => 'sudo /usr/local/sbin/dnsweb-apply',
];
```

## Dépannage

- **« Dossier non inscriptible »** : `www-data` n'est pas encore dans le groupe
  `dnsweb`. Relance `sudo systemctl restart php*-fpm`.
- **« Échec de l'application »** : la sortie de `dnsmasq --test` s'affiche dans
  le message d'erreur — corrige la donnée fautive.
- **Baux vides** : vérifie que `/var/lib/misc/dnsmasq.leases` est lisible par le
  groupe `dnsweb`.
