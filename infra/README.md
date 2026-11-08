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
> ⚠ LIP réelle du VPS nest plus dans ce dépôt public : voir `infra/ACCES-VPS.md` (sur le VPS, non suivi).

- **Un sous-domaine** (ex. `preprod.tondomaine.fr`) en A vers `<IP_DU_VPS>`
  et en AAAA vers l'IPv6. Propagation DNS avant de lancer Certbot.
- **Ta clé SSH** déposée sur le VPS : `ssh-copy-id debian@<IP_DU_VPS>`

### 2. Durcissement du serveur (une fois)

```bash
scp infra/bootstrap.sh debian@<IP_DU_VPS>:~
ssh debian@<IP_DU_VPS> 'sudo bash ~/bootstrap.sh'
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

> ⚠ **CETTE PROCEDURE EST CELLE D'UNE MACHINE NEUVE. Sur une machine deja en service, ne recopiez
> pas ce fichier sans avoir lu le diff.**
>
> `infra/nginx/billetterie-preprod.conf` n'est **pas** deploye par `deploy-preprod.sh` : il n'est
> applique que si quelqu'un le recopie a la main. Il a donc derive de la machine, et la derive n'est
> pas theorique — le depot declare un `auth_basic` que la preproduction a **leve le 28/08 a la
> demande de Maxime**, pour pouvoir ouvrir les ecrans dans un navigateur. Recopier le fichier tel
> quel remet cette authentification.
>
> Avant toute recopie sur une machine existante :
>
> ```bash
> diff /etc/nginx/sites-available/billetterie-preprod.conf infra/nginx/billetterie-preprod.conf
> ```
>
> et **lire ce que la difference ferait**, plutot que de supposer que le depot a raison.
>
> Corollaire, et il porte plus loin que l'authentification : tant que l'ecart n'est pas reduit,
> toute affirmation tiree de la LECTURE de ce fichier — un en-tete, un `try_files`, une limite de
> taille — vaut comme hypothese, pas comme fait. Pour savoir ce que la machine fait vraiment, on
> l'interroge : `bin/version-servie.py`, `bin/verifier-deploiement.py`, ou un `curl` avec un temoin
> de controle sur une URL inventee.

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

### 6. Recharger les données de démonstration

La démonstration dérive au fil des essais. Les 37 classes de `DataFixtures` décrivent l'état voulu et
peuvent être rejouées sur la préprod :

```bash
docker compose exec php php bin/console app:demo:charger
```

Elle **complète** : chaque fixture cherche avant de créer, donc rien n'est écrasé ni dupliqué. Deux
chargements successifs laissent des comptes identiques sur les 329 tables — mesuré, pas supposé.

⚠ **Ce qu'elle ne fait pas** : remettre à zéro. Un objet de démonstration modifié à la main reste
modifié. Repartir d'une base propre est un geste de `dev` sur une base jetable, jamais un geste de
préprod.

⚠ **Et n'utilisez pas `doctrine:fixtures:load`** : elle **purge la base avant d'écrire**. C'est ce qui
a mis les trente-quatre rôles de la préprod à zéro droit le 24/08. Elle refuse désormais de purger hors
`dev`/`test` — mais autant ne pas la taper.

⚠ **Si vous vérifiez un changement de configuration en préprod** : `APP_ENV=prod` avec `APP_DEBUG=0` ne
recompile pas le conteneur de services. Sans `rm -rf app/var/cache/prod` au préalable, vous mesurez
l'état d'avant — et la commande s'exécute normalement, en affichant un résultat plausible.

### Installer une base de production vierge

Une base neuve, migrée, n'a ni établissement ni compte : personne ne peut s'y connecter. Les fixtures
ne servent pas en production (données de démonstration, mot de passe `aaa`). Après le premier
déploiement :

```bash
docker compose exec php php bin/console platform:install --admin-email=<adresse>
```

Elle crée l'établissement éditeur (son groupe, sa région), un rôle copié du modèle « Administrateur
d'établissement » posé par migration, et l'administrateur **invité** — aucun mot de passe n'est écrit.
Elle imprime la ligne `EDITOR_TENANT_ID=…` à poser dans `app/.env.local` (puis vider le cache) et le
lien d'activation, valable 72 h, à transmettre à l'administrateur. Elle sème aussi les taux de TVA
légaux ; pas les options vendables, dont le prix est provisoire.

Rejouable : un second lancement ne crée rien. `--new-link` réémet un lien expiré. Elle **refuse** une
base qui contient déjà d'autres établissements (la préprod) ; `--force` existe mais n'a pas lieu
d'être utilisé hors cas exceptionnel.

## Production (conteneur chez IT Cotation)

Décidé le 28/10/2026 : la **production** tourne dans un conteneur LXD non privilégié (Debian 13)
hébergé par Fabrice (IT Cotation). La préprod reste sur le VPS OVH. Base **vierge** : rien de la
préprod n'y est copié.

### Accès

Depuis le VPS OVH (la clé dédiée y vit, sa partie privée n'en sort pas) :

```bash
ssh -i ~/.ssh/fluvia_migration_fabrice -o IdentitiesOnly=yes -p 2222 maxime@137.74.167.234
```

Port **2222** = SSH du conteneur (le 22 est l'hôte, hors de notre périmètre). `sudo` sans mot de
passe. Aucun accès à l'hôte, ni à GitHub depuis le conteneur.

### Contrat réseau

- L'application écoute en **HTTP sur le port 80 du conteneur** (service `front`).
- L'hôte de Fabrice termine le TLS de **https://portal.fluvia-app.com** et proxifie vers ce port,
  en préservant `Host` et en posant `X-Forwarded-Proto: https`.
- **Pas de certbot, pas de 443, pas de pare-feu** dans le conteneur : il n'a pas d'IP publique.
- Nouveaux sous-domaines (`clientX.fluvia-app.com`) : à demander à Fabrice (wildcard possible).
- Plafond : **6 Go de RAM / 3 cœurs** pour tout le conteneur (budget dans `compose.prod.yaml`).
  Un relèvement se demande à Fabrice.

### Premier déploiement

```bash
# 1. Dans le conteneur : le dépôt nu qui reçoit le code, et l'arbre servi
sudo mkdir -p /srv/fluvia && sudo chown maxime: /srv/fluvia
git init --bare -b main /srv/fluvia/fluvia.git

# 2. Depuis le VPS OVH : pousser main (le conteneur n'a pas accès à GitHub)
git -C ~/billetterie push ssh://maxime@137.74.167.234:2222/srv/fluvia/fluvia.git origin/main:refs/heads/main
#    (avec GIT_SSH_COMMAND="ssh -i ~/.ssh/fluvia_migration_fabrice -o IdentitiesOnly=yes")

# 3. Dans le conteneur : l'arbre, sa configuration locale, puis le déploiement
git clone /srv/fluvia/fluvia.git /srv/fluvia/app
cd /srv/fluvia/app
( umask 077 && printf '%s\n' \
    'FRONT_BASE_URL=https://portal.fluvia-app.com' \
    'MAILER_DSN=null://null' > app/.env.local )
./infra/deploy-prod.sh
```

- `deploy-prod.sh` crée `infra/.env.prod` (600) et **génère tous les secrets** au premier passage ;
  il ne les affiche jamais et ne les régénère jamais.
- `MAILER_DSN=null://null` : **aucun courriel ne part** pour l'instant (décision de Maxime du
  28/10). Le déploiement le rappelle à chaque passage.
- `EDITOR_TENANT_ID` : posé dans `app/.env.local` **après** la commande d'installation
  `platform:install` (voir « Installer une base de production vierge »), qui crée l'établissement éditeur et le premier
  administrateur sur la base vierge. Sans elle, personne ne peut se connecter.
- L'ordonnanceur n'est **pas** démarré par le déploiement (profil `ordonnanceur`, comme en préprod).

### Mises à jour

```bash
# VPS OVH, après fusion dans main :
git -C ~/billetterie push ssh://maxime@137.74.167.234:2222/srv/fluvia/fluvia.git origin/main:refs/heads/main
# conteneur :
cd /srv/fluvia/app && git pull --ff-only && ./infra/deploy-prod.sh
```

### Sauvegardes

Dumps quotidiens dans `/srv/fluvia/sauvegardes` (service `sauvegarde`, 14 jours). Ils sont dans le
**même** conteneur que la base : la copie hors serveur est l'export du conteneur, côté Fabrice.

⚠ **Les dumps ne contiennent pas les clés** (`infra/.env.prod`). Une base restaurée sans son
trousseau NF525 a des chaînes que plus rien ne vérifie : voir la section suivante.

## Trousseau NF525 — #361

**Pourquoi.** Chaque maillon des trois chaînes scellées (vente, comptabilité, facturation) est signé
par un HMAC, donc par une clé **secrète** : vérifier un maillon ancien exige la clé qui l'a signé. Or
les données doivent être conservées six ans et leur intégrité prouvée dans le temps : BOFiP
**BOI-TVA-DECLA-30-10-30**, version publiée le **25/03/2026** (consultée le 08/11/2026 sur
bofip.impots.gouv.fr), §155 (« conservées pendant le délai de six ans »), §100 (« empreinte numérique
à clé privée, chaînage »), §220 et §250 (intégrité des archives dans le temps). Perdre une clé, c'est
perdre la preuve.

**Ce qui existe.**

| Variable (fichier d'environnement de l'installation) | Rôle |
|---|---|
| `NF525_SEAL_KEY`, `NF525_COMPTA_SEAL_KEY`, `NF525_FACTURATION_SEAL_KEY` | clé **active** de chaque chaîne : elle signe |
| `NF525_SEAL_RETIRED_KEYS`, `NF525_COMPTA_SEAL_RETIRED_KEYS`, `NF525_FACTURATION_SEAL_RETIRED_KEYS` | clés **retirées**, séparées par des virgules : elles ne font que vérifier |

- Chaque maillon porte `seal_key_id`, une empreinte **non réversible** de sa clé (jamais la clé). La
  vérification choisit la clé par cet identifiant. Les maillons antérieurs au trousseau (`NULL`) sont
  vérifiés avec la clé active, puis avec les clés retirées.
- **Échec fermé** : si le dernier maillon d'une chaîne ne se vérifie avec aucune clé du trousseau,
  l'application **refuse de sceller** (HTTP 409, message qui nomme la variable à restaurer) et journalise
  une alerte critique (identifiants seulement).
- Le déploiement **ne génère jamais** une clé NF525 quand des maillons existent : il mesure la base
  (`infra/nf525-key-guard.sh`) et s'arrête. Après les migrations, `app:nf525:check-keys` (lecture
  seule) vérifie le dernier maillon de chaque chaîne et fait échouer le déploiement s'il est bloqué.

**Sauvegarder le trousseau HORS du serveur (à faire une fois, puis à chaque rotation).**

1. Copier les six lignes `NF525_*SEAL*` du fichier d'environnement (`infra/.env.prod` en production,
   `infra/.env.preprod` en préprod) dans le **coffre de mots de passe de l'exploitant** (une entrée par
   installation, intitulée par exemple « Fluvia prod — trousseau NF525 »), ou dans un fichier chiffré
   (`gpg --symmetric`) rangé avec les sauvegardes externes. Jamais dans le dépôt, jamais dans un ticket,
   jamais dans un message.
2. Noter, à côté, les **identifiants** affichés par
   `docker compose -f infra/compose.prod.yaml --env-file infra/.env.prod exec -T php php bin/console app:nf525:check-keys`
   (« clé active … ») : ils permettent de vérifier plus tard qu'on restaure la bonne clé, sans l'afficher.
3. Conserver chaque clé au moins **six ans après le dernier maillon qu'elle a signé** (§155).

**Restaurer (base restaurée, fichier d'environnement perdu ou recréé).** Remettre les six lignes depuis
le coffre dans le fichier d'environnement, relancer le déploiement, et vérifier que
`app:nf525:check-keys` affiche `0 bloquée(s)` et les mêmes identifiants que ceux notés.

**Changer une clé (rotation volontaire).** Ne jamais remplacer la valeur seule. Dans le même geste :
ajouter l'ancienne clé à la variable `*_RETIRED_KEYS` correspondante, poser la nouvelle clé active,
mettre à jour la sauvegarde hors serveur, redéployer, puis vérifier `app:nf525:check-keys`. Une clé
retirée ne se supprime pas avant la fin du délai de conservation.

**Clé réellement perdue (aucune sauvegarde).** Aucune procédure automatique : la chaîne concernée ne
peut plus être prolongée, et c'est voulu. La sortie (par exemple un maillon de « rupture constatée »
scellé avec la nouvelle clé, option B de l'enquête #361) est une décision de Maxime, à ne pas
improviser sur le serveur.

## Paiement en ligne (PayFiP, PSP CB) — #339

Aucune valeur n'est dans le code ni dans le dépôt. Elles se posent dans **`app/.env.local`** (comme
`FRONT_BASE_URL`), puis on redémarre PHP-FPM. Tant qu'un prestataire n'a pas sa configuration complète,
il reste éteint et c'est le bouchon de démonstration qui répond — lui-même refusé hors préproduction
(`PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE`).

| Variable | Valeur | Obligatoire |
| --- | --- | --- |
| `BOUTIQUE_BASE_URL` | URL publique de la boutique, sans `/` final. Le retour de paiement est `<BOUTIQUE_BASE_URL>/b/<vitrine>/retour-paiement`. | oui, pour tout prestataire réel |
| `PAYFIP_NUMCLI` | Numéro de client PayFiP de la régie (6 chiffres), donné par la DDFiP. | oui (PayFiP) |
| `PAYFIP_ENTRY_MODE` | `T` paiements de test, `X` paiement d'activation, `W` paiements réels. Défaut : `T`. | non |
| `PAYFIP_NOTIFICATION_URL` | `https://<domaine>/api/boutique/paiement-en-ligne/notification/payfip`. Le domaine doit être déclaré au correspondant DDFiP ; son certificat ne doit pas dépendre de SNI. | oui (PayFiP) |
| `PAYFIP_SERVICE_URL` | Web service. Défaut : `https://www.payfip.gouv.fr/tpa/services/securite`. | non |
| `PAYFIP_PAYMENT_PAGE_URL` | Page de paiement. Défaut : `https://www.payfip.gouv.fr/tpa/paiementws.web`. | non |
| `STRIPE_SECRET_KEY` | Clé secrète de l'exploitant (`sk_test_…` en préproduction). **Secret** : jamais dans un ticket, jamais en clair dans un journal. | oui (PSP CB) |
| `STRIPE_API_BASE` | Défaut : `https://api.stripe.com`. | non |

Côté Stripe, déclarer le webhook `https://<domaine>/api/boutique/paiement-en-ligne/notification/stripe`
(événements `checkout.session.completed`, `checkout.session.expired`). La notification n'est qu'une
invitation : l'issue est toujours redemandée au prestataire avec la clé secrète.

**Mise en service PayFiP** (guide DGFiP « PayFiP Régie Web service », V18.0 d'août 2019 ; l'édition de
juin 2025 se demande au correspondant moyens de paiement de la DDFiP) :

1. `PAYFIP_ENTRY_MODE=T` : paiement de test avec une carte de test DGFiP, **montant en euros ronds**,
   différent à chaque essai. Vérifier que la vente est validée et que le bordereau PayFiP porte P.
2. `PAYFIP_ENTRY_MODE=X` : paiement d'activation (carte puis prélèvement).
3. Le lendemain : `PAYFIP_ENTRY_MODE=W`. Un mode T ou X en service ne débite rien.

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
