# Le worker asynchrone (D7-bis)

Depuis D7-bis, le travail **sortant** — appeler une API externe, provisionner un établissement après
paiement, notifier — passe par `symfony/messenger` avec le transport Doctrine. La file vit dans la base
(`messenger_messages`), il n'y a donc **aucun composant d'infrastructure supplémentaire à installer**.

Mais il faut un processus qui consomme la file. Sans lui, les messages s'accumulent et rien ne part.

## Lancer le worker

Le même conteneur que la préprod, en mode consommation :

```bash
docker run -d --name billetterie-preprod-worker \
  --restart unless-stopped \
  --network billetterie-preprod_default \
  -v /home/debian/billetterie/app:/app \
  -w /app \
  billetterie-preprod-php \
  php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
```

`--time-limit` et `--memory-limit` ne sont pas des précautions cosmétiques : un worker PHP de longue
durée finit toujours par accumuler de la mémoire ou par garder une connexion morte. On le laisse
s'arrêter proprement toutes les heures, et `--restart unless-stopped` le relance. C'est plus fiable
qu'un processus qu'on croit éternel.

## Ou en service systemd

Le fichier ci-dessous est fourni **à titre de modèle et n'est pas installé** : poser un service sur la
machine est une modification système, elle revient à l'exploitant.

```ini
# /etc/systemd/system/billetterie-worker.service
[Unit]
Description=Billetterie — worker messenger
After=docker.service
Requires=docker.service

[Service]
Restart=always
RestartSec=5
ExecStart=/usr/bin/docker run --rm --name billetterie-worker \
  --network billetterie-preprod_default \
  -v /home/debian/billetterie/app:/app -w /app \
  billetterie-preprod-php \
  php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M

[Install]
WantedBy=multi-user.target
```

## Surveiller les échecs — la partie qu'on oublie

L'asynchrone déplace les erreurs **hors du champ de vision de l'utilisateur**. Une erreur synchrone se
voit tout de suite ; un message qui échoue dans une file que personne ne regarde, non. D'où un
transport d'échec séparé, et l'obligation de le consulter :

```bash
php bin/console messenger:failed:show            # ce qui a échoué, et pourquoi
php bin/console messenger:failed:retry           # rejouer après correction
php bin/console messenger:failed:remove <id>     # abandonner explicitement
```

Un message reste en file d'échec après **cinq tentatives** espacées de 10 s à 13 min 30 (plafond une
heure). Ces délais visent les API externes : un réseau social qui refuse pour dépassement de quota ne
redeviendra pas disponible en trois secondes.

**Si cette file grossit sans que personne ne la regarde, l'asynchrone est devenu une façon élégante de
perdre du travail en silence.** C'est le vrai coût de D7-bis, et il se paie en surveillance.

## Ce qui ne passe PAS par là

Les **faits** du domaine restent sur le bus synchrone (`App\Platform\Event\EventBus`), dans la
transaction de l'émetteur : c'est ce qui garantit qu'un fait et ses conséquences internes réussissent
ou échouent ensemble. Seul le travail sortant est asynchrone, et il se déclare en implémentant
`App\Platform\Message\AsyncMessage`.
