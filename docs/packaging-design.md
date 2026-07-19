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

7. **nginx conditionnel** : si `lnmp` est installé → aucun vhost (exposition via
   lnmp). Sinon vhost sur `dnsmasq.mawena.local`.

8. **Dérogation systemd** : drop-in `ReadWritePaths=/etc/dnsmasq.d/webui` pour
   chaque service php-fpm (contourne `ProtectSystem=full` qui met `/etc` en
   lecture seule pour php-fpm).

9. **Dépôt apt** : sous-domaine dédié `dnsmasqwebui.mawena.cloud` (webroot
   `/var/www/dnsmasq-webui/`, servi par le nginx du VPS). `build.sh`
   (debuild + scp -P 2244) envoie le `.deb` dans `/var/www/dnsmasq-webui/repo/ubuntu` ;
   `update_repo.sh` (sur le serveur) scanne `ubuntu/`, écrit l'index
   (`Packages`/`Release`/`InRelease`) à la racine `repo/` — les `Filename:`
   pointent vers `ubuntu/…deb` — exporte la clé publique (`dnsmasq-webui.asc`)
   et signe (même clé GPG que lnmp). Install client en 2 commandes : import de
   la clé + source deb822 `URIs: https://dnsmasqwebui.mawena.cloud/repo/ Suites: ./`,
   puis `apt install dnsmasq-webui`.

## Cycle de vie

| Action | Effet |
|--------|-------|
| `apt install` | `_postinstall` : configuration complète |
| `apt remove` | `_remove` : défait le système, garde base + config |
| `apt purge` | + efface base MySQL, `/etc/dnsmasq-webui`, `.conf` |

## Validé

Build réel `dpkg-buildpackage -b` OK : arborescence FHS correcte, substitution
`@VERSION@`, scripts mainteneur présents, CLI fonctionnel (`version`, `help`).
