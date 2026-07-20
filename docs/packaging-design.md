# Conception — paquet `dnsmasq-webui`

Décisions structurantes de la mise en paquet Debian/Ubuntu (2026-07-19).

## Objectif

`sudo apt install dnsmasq-webui` sur n'importe quel Ubuntu → serveur DNS/DHCP de
réseau local avec administration web, prêt à l'emploi.

## Décisions

1. **Dépendances via `Depends:`, pas d'`apt` dans le postinst.**
   dnsmasq, nginx, MySQL, php-fpm sont tirés automatiquement par apt. Le postinst
   ne fait que la *configuration* (verrou dpkg → jamais d'apt imbriqué). Calqué
   sur le paquet `lnmp`.

2. **Toute la plomberie dans le CLI, appelé par les scripts mainteneur.**
   `postinst → dnsmasq-webui _postinstall`, `prerm(remove) → _remove`. Le
   `postrm(purge)` agit en shell direct (le CLI est déjà retiré à ce stade).

3. **Groupe partagé `webdev`** (réutilisé de lnmp, créé s'il manque). Jamais
   supprimé au purge (partagé). Abandon du groupe `dnsweb`.

4. **Emplacements FHS** : code `/usr/share/dnsmasq-webui/`, config
   `/etc/dnsmasq-webui/`, `.conf` générés `/etc/dnsmasq.d/webui/`, CLI
   `/usr/bin/`, wrapper `/usr/sbin/`.

5. **Port 53 libéré** : `systemd-resolved` est reconfiguré (`DNSStubListener=no`)
   et `/etc/resolv.conf` pointé sur `127.0.0.1`, avec sauvegarde restaurée au
   purge/remove. C'est ce qui fait de la machine un vrai serveur DNS.

6. **dnsmasq = résolveur local + repli public** : la table `settings` est seedée
   avec `upstream_dns=1.1.1.1,8.8.8.8`, `domain_needed`, `bogus_priv`, cache et
   domaine local. `no-resolv` est émis quand un upstream est défini (pas de
   boucle avec resolv.conf).

7. **nginx conditionnel + chemin d'exposition** : le paquet crée un symlink
   `/var/www/dnsmasq-webui` → `/usr/share/dnsmasq-webui/public` (code en FHS,
   exposition sous `/var/www`). Si `lnmp` est installé → aucun vhost (exposition
   via lnmp, `root /var/www/dnsmasq-webui`). Sinon vhost sur
   `dnsmasq.mawena.local` avec ce même `root`. Symlink retiré au `remove`.

8. **Dérogation systemd** : drop-in `ReadWritePaths=/etc/dnsmasq.d/webui` pour
   chaque service php-fpm (contourne `ProtectSystem=full` qui met `/etc` en
   lecture seule pour php-fpm).

9. **Dépôt apt partagé signé** : dépôt multi-paquets commun à toute l'infra
   mawena (`lnmp`, `dnsmasq-webui`, …), hébergé sur le VPS à
   `/var/www/html/Mawena/mawena/repo/` et exposé sur `https://mawena.cloud/repo`
   (suite `stable`, composant `main`), géré par **reprepro**. `build.sh` fait tout
   sans connexion manuelle : debuild → `scp` du `.deb` dans `ubuntu/` → `ssh` qui
   lance `update_repo.sh <deb>` (reprepro `includedeb stable`). Dépôt **signé GPG** :
   clé publique à `https://mawena.cloud/repo/public.key`. Install client en 3
   étapes : (1) `curl … public.key -o /etc/apt/keyrings/mawena-repository.asc`,
   (2) `deb [signed-by=…/mawena-repository.asc] https://mawena.cloud/repo stable main`
   dans `/etc/apt/sources.list.d/mawena.list`, (3) `apt install dnsmasq-webui`.
   L'infra du dépôt (dossier, clé, vhost, `update_repo.sh`) est gérée une seule
   fois, côté lnmp ; dnsmasq-webui n'y ajoute que son `.deb`.

## Cycle de vie

| Action | Effet |
|--------|-------|
| `apt install` | `_postinstall` : configuration complète |
| `apt remove` | `_remove` : défait le système, garde base + config |
| `apt purge` | + efface base MySQL, `/etc/dnsmasq-webui`, `.conf` |

## Validé

Build réel `dpkg-buildpackage -b` OK : arborescence FHS correcte, substitution
`@VERSION@`, scripts mainteneur présents, CLI fonctionnel (`version`, `help`).
