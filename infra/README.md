# Infra — VPS OVH (préprod billetterie + agents IA + second site)

Machine cible : **VPS-3 2027**, 6 vCores / 12 Go RAM / 100 Go, Debian 13, Gravelines.

Principe directeur : **tout est scripté et rejouable**. Le VPS de production de la
billetterie sera provisionné en rejouant `bootstrap.sh` puis `deploy-preprod.sh`
(renommé en `deploy-prod.sh` avec son propre `.env`). La préprod n'est utile que
si elle est identique à la prod — mêmes images, mêmes versions, même config.

## État actuel (17/08/2026)

Le VPS est provisionné et la préprod tourne. Accès depuis le PC de dev :

```bash
ssh vps-preprod                 # alias configuré dans ~/.ssh/config
```

Clé dédiée : `~/.ssh/id_ed25519_ovh` (sans passphrase, pour l'automatisation).
Authentification par mot de passe **désactivée** sur le serveur.

Déploiement : le dépôt est poussé vers un dépôt bare sur le VPS, sans passer par
GitHub. Depuis le PC de dev :

```bash
git push vps main
ssh vps-preprod 'cd ~/billetterie && ./infra/deploy-preprod.sh'
```

Préprod en ligne : **https://smartaccess.hector-conseil.com**
(Basic Auth, utilisateur `aaa` — protégée par `X-Robots-Tag: noindex` et
un `robots.txt` interdisant tout).

Certificat Let's Encrypt émis le 17/08/2026, expire le 15/11/2026, renouvellement
automatique par `certbot.timer`. Contact d'expiration : mdesbonnet1@gmail.com.

Changer le mot de passe d'accès :

```bash
ssh vps-preprod 'sudo htpasswd /etc/nginx/.htpasswd-preprod aaa'
```

Accès sans passer par le domaine (tunnel SSH, utile si le DNS pose problème) —
puis http://localhost:18081/api/docs :

```bash
ssh -N -L 18081:127.0.0.1:8081 vps-preprod
```

## Répartition de la machine

| Charge | Mode | Port interne | RAM allouée |
|---|---|---|---|
| Nginx (reverse proxy + TLS) | natif | 80 / 443 | ~100 Mo |
| Billetterie préprod (php + nginx + mariadb) | Docker | 127.0.0.1:8081 | ~2,6 Go max |
| Second site | Docker | 127.0.0.1:8082 | à définir |
| Agents IA / n8n | Docker | 127.0.0.1:5678 | ~3 Go |
| Marge système + cache disque | — | — | ~4 Go |

Règle de sécurité importante : **aucune stack applicative ne publie sur `0.0.0.0`**.
Docker écrit directement dans nftables et court-circuite UFW ; un port publié sur
`0.0.0.0` serait accessible depuis Internet malgré le pare-feu. Seul Nginx, natif
sur l'hôte, est exposé.

## Ordre des opérations

### 1. Prérequis (à faire avant de toucher au serveur)

- **Un dépôt Git distant privé** (GitHub/GitLab). Le dépôt local n'a pas encore de
  remote — sans ça, pas de `git clone` ni de `git pull` sur le serveur.
- **Un sous-domaine** (ex. `preprod.tondomaine.fr`) en A vers `<ip-du-vps>`
  et en AAAA vers l'IPv6. Propagation DNS avant de lancer Certbot.
- **Ta clé SSH** déposée sur le VPS : `ssh-copy-id debian@<ip-du-vps>`

### 2. Durcissement du serveur (une fois)

```bash
scp infra/bootstrap.sh debian@<ip-du-vps>:~
ssh debian@<ip-du-vps> 'sudo bash ~/bootstrap.sh'
```

Fait : mises à jour, swap 4 Go, SSH par clé uniquement, UFW, fail2ban,
mises à jour de sécurité automatiques, Docker + Compose, Nginx + Certbot.

Le script refuse de couper l'authentification par mot de passe tant qu'aucune clé
SSH n'est installée — impossible de se verrouiller dehors.

Puis se reconnecter (pour que le groupe `docker` prenne effet) et vérifier :

```bash
ufw status verbose && fail2ban-client status sshd && docker run --rm hello-world
```

### 3. Déploiement de la préprod

```bash
git clone <url-du-depot-prive> ~/billetterie && cd ~/billetterie
cp infra/env.preprod.example infra/.env.preprod
# remplir les 4 secrets avec : openssl rand -hex 32
./infra/deploy-preprod.sh
```

### 4. Exposition via Nginx + HTTPS

```bash
sudo cp infra/nginx/billetterie-preprod.conf /etc/nginx/sites-available/
sudo sed -i 's/preprod.CHANGEME.fr/preprod.tondomaine.fr/' /etc/nginx/sites-available/billetterie-preprod.conf
sudo ln -sf /etc/nginx/sites-available/billetterie-preprod.conf /etc/nginx/sites-enabled/
sudo htpasswd -c /etc/nginx/.htpasswd-preprod monlogin
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d preprod.tondomaine.fr
```

La préprod est protégée par Basic Auth + `X-Robots-Tag: noindex` + `robots.txt`
interdisant tout. Certbot renouvelle automatiquement (timer systemd installé par
le paquet Debian).

### 5. Mises à jour suivantes

```bash
./infra/deploy-preprod.sh
```

## Points de vigilance

- **Données** : jamais de données clients réelles en préprod (RGPD). Dump anonymisé
  ou jeu de test généré.
- **opcache** tourne avec `validate_timestamps=0` : le redémarrage de PHP-FPM après
  déploiement est obligatoire, il est déjà dans `deploy-preprod.sh`.
- **Sauvegardes** : l'option « Automated backup / Standard » d'OVH sauvegarde la VM.
  Pour la base, ajouter un dump quotidien si la préprod porte des données utiles :
  `docker compose -f infra/compose.preprod.yaml exec -T db mariadb-dump ...`
- **Snapshot OVH** : en prendre un juste après `bootstrap.sh`. C'est le point de
  retour arrière propre si une expérimentation tourne mal.
- **Agents IA** : si l'inférence est locale (Ollama, vLLM), 12 Go de RAM partagés
  deviennent le facteur limitant et la préprod en souffrira. Avec des API externes
  (Claude, OpenAI), la machine est très largement dimensionnée.
