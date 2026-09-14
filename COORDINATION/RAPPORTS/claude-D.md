> ⛔ **ARCHIVE — canal retiré.** L'ancienne coordination artisanale (`ORDRES/`, `RAPPORTS/`, `TASKS.md`, `MESSAGES.md`, battement de 15 min) est **remplacée par branches + PR + Issues** — voir `CLAUDE.md`, section « Ce qui a été retiré ». Fichier conservé pour mémoire : **ne rien y écrire, ne pas le lire comme un canal actif.**

# Rapports de `claude-D`

> **Écrit par `claude-D` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 12:22 | Situé : FLOTTE, PLAYBOOK, DECISIONS, ORDRES et `specs/editeur/spec-editeur.md` lus. Constat : ED-1 et ED-2 sont livrés dans `app/src/Subscription/`, pas dans `app/src/Editeur/` — qui n existe sur aucune branche. La spec tranche le nommage (§Nommage : le module s appelle `App\Subscription`) ; la colonne « Chemins » de ED-1/ED-3 dans TASKS.md est donc périmée, pas le code. | Je prends **ED-3** — tunnel de souscription SEPA + provisioning idempotent. Je commence par `ProvisioningRequest` et le service de provisioning, qui ne dépendent pas de B-2. | B-1, B-2 — détail ci-dessous |
| 12:31 | **B-1 corrigé côté claude-A** : mon worktree est revenu sur le clone, à mon commit, avec un `origin` — les poussées repassent par `pre-receive`. Mon travail était intact. Reste ouvert : l identité de commit est toujours partagée (`git config user.name` = claude-I dans `/home/debian/billetterie/.git/config`) ; je commite avec `git -c user.name=claude-D`. Les cinq autres sessions auront le même défaut. | **ED-3, premier lot** : `ProvisioningRequest` (idempotence par contrainte d unicité sur `subscription_id`), `ProvisioningStatus`, `ProvisioningOutcome`, `ProvisioningService`. Les quatre passent `php -l`. Ensuite : les tests de CA-1 et CA-2. | B-2 (catalogue) pour l émission ; **B-3, nouveau** — voir ci-dessous |
| 18:18 | **ED-3 testé, tout vert** : `ProvisioningServiceTest` — 6 tests, 29 assertions, dont CA-1 (établissement + administrateur invité + seuls les modules souscrits exposés) et CA-2 (le même événement rejoué trois fois : une demande, un établissement, un administrateur, `attempts = 3`). Plus `tests/Platform` : 58 tests, 221 assertions, verts. **Un vrai défaut trouvé par le test** : `Role.nom` porte une unicité **globale** (`uniq_role_nom`) et je donnais au rôle livré le nom du rôle modèle — ça passait au premier client et cassait au second. Corrigé (nom = modèle + établissement + préfixe d id) et **test de non-régression à deux clients de même raison sociale** ajouté : aucun test à un seul client ne pouvait le voir. | J écris l abonné `subscription.activated` et j ajoute ses deux lignes au catalogue **dans le même commit**, selon ta méthode. | B-3 (rôle modèle), B-1.3 (identité de commit) |
| 18:28 | **B-2 consommé selon ta méthode.** `SubscriptionActivator` (émetteur), `ProvisionOnSubscriptionActivated` (abonné) et la ligne `subscription.activated` au catalogue, **dans le même commit**. Je suis le **premier consommateur de `DomainEvent` du dépôt** — il n en existait aucun, d où tes 26 sans preneur. Tests : `tests/Subscription` 51 verts / 274 assertions, dont la chaîne activation → bus → provisioning par le vrai bus et le vrai abonné ; `tests/Platform` 58 verts (221 → 222 assertions : `ManifestCatalogueTest` a pris ma ligne). | Le tunnel lui-même : composition du panier, mandat SEPA, confirmation. | B-3 (rôle modèle), B-1.3 (identité), **Q-1 ci-dessous** |
| 18:29 | Pile `claudeD` démontée après usage. | Reprise du tunnel (ce qui ne dépend pas de Q-1). | **Alerte, pas un blocage** : 23 piles de test tournent et 23 réseaux Docker avec (`FIX`, `FIX2`, `CQ1`, `CQ5`, `CQ5B`, `SF1`, `SF1B`, `N8`, `N10`, `N11`, `N11T`, `N12`, `N12T`, `N15`, `N16`, `NT`, `SOIR`, `GL`, `FLOTTE`, `claudeA`, `claudeA2`, `claudeC`, `claudeG`). Le 24/08 la saturation des plages réseau est arrivée à 26. Aucune n est à moi — je ne démonte pas les piles des autres. |
| 21:50 | **Migration écrite à la main (D32) et vérifiée sur base repartie de zéro.** Le brouillon `doctrine:migrations:diff` contenait **104 instructions dont 6 à moi** ; il proposait `DROP INDEX support_ft_article_recherche`, soit la suppression de l index FULLTEXT de la recherche d aide, dans un lot ne créant qu une table. Jeté. `Version20260824213600` ne porte que ma table, ses deux clés étrangères et l unicité `uniq_provisioning_subscription` qui porte RG-ED-05. Horodatage local : le brouillon naissait `193818` (UTC) et se serait classé **avant** `Version20260824200000`, déjà appliquée. Plus : `ADMIN_ROLE_TEMPLATE` = `Administrateur d établissement` comme tu l as fixé, et **troncature par le milieu** avec l identifiant complet en suffixe — ton point était juste, une coupe par la fin aurait recréé la collision d unicité par un autre chemin. Tests : `tests/Subscription` 52 verts / 282 assertions, `tests/Platform` 58 verts (222 → 232). | Le tunnel : composition du panier, puis mandat SEPA. | Q-1 (désignation de l éditeur) — tu la prends, je continue jusqu au point où l anonymat commence |
| 22:08 | **Le tunnel de souscription est livré de bout en bout** (spec §2) : `SubscriptionFunnel` — panier, mandat SEPA, confirmation — branché sur `EditorTenantResolver` que tu viens de livrer. L activateur ne déduit plus le tenant de la fiche client, il le résout. **Un second défaut trouvé par le test** : l activateur publiait `subscription.activated` **sans horodater l événement**, donc l abonné provisionnait avec « maintenant » au lieu de l instant métier — un abonnement prenant effet plus tard aurait été livré **sans les options achetées**, et personne ne l aurait vu avant que le client ne cherche son module. Corrigé : l événement porte `occurredAt = $at`. Tests : `tests/Subscription` 57 verts / 303 assertions, `tests/Platform` 58 verts. | Reste d ED-3 : reprise du paramétrage de démo (RG-ED-08, D11). Puis ED-4. | Rien |
| 22:32 | **ED-3 est complet** : reprise du paramétrage de démo livrée (RG-ED-08, CA-7, D11). Choix d architecture que je te soumets : **un port chez moi, les implémentations chez chacun** — `App\Subscription\Port\ConfigurationSnapshotProvider`, collecté par `AutoconfigureTag`. Écrire l export des offres dans mon module supposerait qu il connaisse le modèle de `Offre`, et le casserait à chaque évolution de `claude-G` dans un fichier qu il n a pas le droit de corriger. La règle qui compte est encodée et testée : **le rejeu n allume jamais un module non souscrit**. Migration `Version20260824224000` (colonne JSON sur l abonnement), écrite à la main, vérifiée sur base neuve. Tests : `tests/Subscription` 64 verts / 320 assertions, `tests/Platform` 58 verts. | **Plus de tâche assignée dans mon périmètre.** ED-0 à ED-4 : ED-0 REVIEW chez toi, ED-1/ED-2 DONE, ED-3 complet. Je prends ED-4 sauf contre-ordre — mais voir ci-dessous. | Rien |
| 22:46 | **ED-4 livré — et je n avais finalement pas besoin de toi.** `JournalAudit::enregistrer()` est une API publique appelable : j écris l entité, le garde et les tests chez moi, et j **appelle** l audit sans toucher à `app/src/Audit/**`. Ma question précédente est donc close, ne la traite pas. Livré : `SupportAccess` (nominatif, `expires_at` **NOT NULL** — la base refuse ce que le code pourrait laisser passer), `SupportAccessGuard` en échec fermé, `SupportAccessDeniedException`, migration `Version20260824224500` (6 lignes gardées sur 102 au brouillon). **CA-6 couvert** : accès expiré refusé **et tracé**. Huit tests, verts du premier coup, dont celui qui vérifie qu un accès sur un établissement n en ouvre aucun autre. Tests : `tests/Subscription` 72 verts / 334 assertions, `tests/Platform` 58 verts. **Le lot ED est terminé côté carnet : ED-1, ED-2, ED-3, ED-4.** | J attends ton arbitrage sur la suite (vitrine ou autre chose) et je prends une tâche libre du carnet en attendant. | Rien |
| 23:13 | **Site vitrine livré** (ED-5) : `vitrine/` — site **statique**, trois fichiers, aucune chaîne de construction. Le chiffre de `claude-H` a tranché mieux que mon intuition : `frontend/` a un bundle unique, et tout ajout grossit celui **que charge aussi le caissier** (~365 ko). Un site séparé ne lui coûte rien. Largeur éditoriale selon sa conclusion — bandeau large, texte à 68 caractères — et ses tokens repris tels quels pour que la vitrine et le produit se ressemblent. **Les prix ne sont pas écrits dans la page** : elle lit `/editor/plans` et `/editor/plan-options`, et si la lecture échoue elle le dit franchement plutôt que d afficher un repli — un prix inventé sur une page publique est un engagement qu on ne tient pas. Ressources publiques `PublicPlan` et `PublicPlanOption` livrées avec leurs fournisseurs. **Le dossier `ApiResource` est maintenant poussé** : tu peux ajouter ta seconde ligne. Tests : `tests/Subscription` 76 — 4 sautés explicitement sur C9, qui se rallumeront seuls — et `tests/Platform` 58 verts après `composer install`. | Le tunnel côté serveur : exposer `openCart` en public, avec le limiteur que tu viens d installer. | Rien |
| 23:34 | **Ton défaut trouvé était plus large que ma garde** : API Platform préfixe par `/api`, donc mes tests **et le site livré** appelaient la mauvaise URL. Les trois corrigés. La garde compare désormais par **suffixe** : elle ne dépend plus d un préfixe de routage qu un test n a aucune raison de connaître. Ta remarque est écrite dans le fichier — *la condition de saut mérite autant de soin que le test, parce qu elle décide si le test existe*. **Les quatre tests tournent pour de vrai** : 76 verts, 0 sauté, 334 → 354 assertions. Plus la migration `Version20260824233000` qui crée le rôle modèle « Administrateur d établissement », vérifiée sur base construite uniquement par les migrations. | Exposer `openCart` en PUBLIC_ACCESS avec le limiteur. | Rien |
| 23:53 | **ED-5 complet : le tunnel est relié à la vitrine.** `POST /api/editor/carts` en PUBLIC_ACCESS, avec ton limiteur — 5 paniers par heure et par adresse, fenêtre glissante. Je construis la fabrique dans mon propre service plutôt que dans `framework.yaml`, qui nest pas mon périmètre : la limite se lit ainsi dans le même fichier que sa raison. **Deux défauts trouvés par les tests, pas par relecture.** (1) Je relayais le message d `InvalidOfferException` en 422 en le croyant écrit pour un humain — il l est, mais pour **l éditeur** : « Ajoute-la au catalogue d options avant de la proposer », au tutoiement, servi à un prospect. Message public réécrit, exception d origine chaînée vers les journaux, test qui vérifie labsence de fuite. (2) Le limiteur mordait entre mes tests : cinq tests partageaient un compteur. C était la preuve quil marche et que mes tests nétaient pas isolés. Compteur remis à zéro entre tests, et test du refus ajouté. Tests : `tests/Subscription` **82 verts / 375 assertions**, `tests/Platform` 58 verts. | Rien en cours — je te demande la suite. | Rien |
| 00:22 | **ED-6 — première brique de ladministration éditeur : Maxime a enfin un écran.** `GET /api/editor/subscriptions` plus lécran qui laffiche. Le contrôle nest **pas une permission mais une identité de tenant** : une permission se délègue, shérite, se recopie dans un rôle modèle ; lappartenance au tenant éditeur, non. **404 et non 403**, pour ne pas confirmer à un client curieux que cet écran existe. Côté écran, D39 appliqué dans sa forme la plus sûre : **aucune règle dautorisation nest rejouée** — le serveur refuse, lécran lexplique. Ce que lécran crie en premier nest pas le chiffre daffaires mais les abonnements **actifs dont le provisionnement a échoué** : un client qui a payé et na rien, que rien ne signale ailleurs. Troisième branche dans `Root.jsx`, chargée à la demande : **EditeurApp pèse 4,6 ko** dans son propre paquet, le caissier ne le télécharge pas. Tests : `tests/Subscription` **85 verts / 388 assertions**, `tests/Platform` 58 verts, et `npm run build` passe. | Suite de ladministration : les offres, puis la fiche client 360 sur le modèle de `Clients.jsx`. | Rien |
| 01:05 | **ED-6, deuxième écran : le catalogue d'offres, modifiable** — formules et options en création, modification, suppression, pilotées par ton `ReferentielEditable`. Contrôle d'accès factorisé dans `EditorOnly` : lecture des offres, écriture des offres, liste des abonnements — trois chemins, un seul contrôle, parce qu'une règle recopiée diverge. **Le garde-fou de couverture de périmètre a refusé ma première version, et il avait deux raisons meilleures que la mienne** — détail ci-dessous. Tests : `tests/Subscription` 89 verts, `tests/Platform` 58 verts, build front OK. `EditeurApp` = 8,8 ko ; `ReferentielEditable` extrait en paquet partagé, donc le back-office **maigrit** de 6,5 ko. | Fiche client 360 sur le modèle de `Clients.jsx`. | Rien |
| 02:10 | **ED-6, troisième écran : la fiche client 360°**, sur le modèle de `Clients.jsx` comme tu me l'as indiqué. Le serveur assemble **en une lecture** ce qu'on veut savoir quand un client appelle : qui il est, ce qu'il paie, si son prélèvement tient, si sa plateforme est livrée, et qui de l'assistance a pu regarder chez lui. Cinq appels afficheraient des morceaux dans le désordre — c'est le moment où l'on se trompe de client. **Le test qui compte** : le CRM de l'éditeur et celui de chaque exploitant sont la **même table**. Sans filtre, cet écran listerait les nageurs d'une piscine à côté des prospects de l'éditeur — une fuite de données personnelles de clients finaux vers un tiers. Filtré sur `etablissementCreation`, et un client d'exploitant demandé nommément par son identifiant rend **404**. La fiche ne porte **aucune donnée d'exploitation** : ni ventes, ni réservations, ni fréquentation. L'éditeur vend une plateforme, il ne lit pas ce qui s'y passe — et quand il doit regarder, la dernière section montre justement qui l'a fait, par quel accès et pour quel motif. L'IBAN n'apparaît jamais qu'en quatre chiffres. Tests : `tests/Subscription` **92 verts / 423 assertions**, `tests/Platform` 62 verts, build front OK — `EditeurApp` = 14,2 ko pour trois écrans. | Rien en cours — je te demande la suite. | Rien |
| 14:55 | **ED-7 — Maxime peut enfin facturer ses abonnements, plus seulement les regarder.** `SubscriptionInvoicer` compose la facture mensuelle et la confie à `EmettreFactureDirecteHandler` : **aucune comptabilité n'est écrite ici**, numérotation, écriture et scellement NF525 restent au module qui les possède. **Le test qui protège l'argent** : l'unicité `(abonnement, mois)` de `SubscriptionInvoice` est réservée **avant** l'émission — un ordonnanceur qui repasse, une relance manuelle, deux clics ne produisent qu'une facture. Et si l'émission échoue, **la réservation est défaite** : un mois rejouable vaut mieux qu'un mois verrouillé par un échec technique qui se lirait ensuite comme « déjà facturé ». Une ligne par élément vendu, avec le **libellé** du module et jamais son code — « reservation » sur une facture n'aide personne. Migration `Version20260825142500` (4 lignes gardées sur 104 au brouillon), vérifiée sur base neuve. Tests : `tests/Subscription` **97 verts / 466 assertions**, `tests/Platform` 62 verts. | L'écran de facturation dans l'administration éditeur. | Rien |
| 16:20 | **ED-7 suite — l'API de l'écran de facturation, et un défaut d'argent trouvé en la testant.** `GET /editor/billing` liste les abonnements facturables du mois avec **ce qui n'a pas eu lieu en tête** — ton raisonnement sur les provisionnements échoués, appliqué à la facturation : un abonnement actif qu'on oublie de facturer ne produit aucun signal, juste de l'argent jamais prélevé qu'on découvre trois mois plus tard. `POST` émet, et rejouer est sans effet. **Le défaut** : la première version ne facturait que les options actives **au premier du mois**. Une option achetée le 20 n'apparaissait sur aucune facture — le client s'en servait sans jamais la payer, et rien ne le signalait. `ProrationCalculator` existait déjà pour ça (CA-4), il n'était simplement pas appelé. Corrigé, avec le test du cas. **Et le montant affiché vient désormais du facturier lui-même** : un écran qui annonce un prix et une facture qui en porte un autre est le meilleur moyen de perdre la confiance d'un client — et de la perdre chez soi d'abord, quand personne ne sait lequel croire. Tests : `tests/Subscription` **104 verts / 506 assertions**, `tests/Platform` 62 verts. | L'écran lui-même, côté React. | Rien |
| 15:12 | **L écran de facturation est livré** — quatrième écran de ton administration. Bandeau en tête quand des abonnements ne sont pas facturés, avec le total à émettre ; les lignes manquantes en rouge, un bouton par ligne. Le taux de TVA est **choisi par l exploitant**, jamais deviné. Et l écart entre le montant attendu et le montant facturé est **montré** plutôt que masqué : une facture émise avant un changement de tarif porte l ancien montant, ce qui est une information et non une erreur. Les refus du serveur sont affichés tels quels — ils disent quoi faire, les traduire en « une erreur est survenue » perdrait justement ce qui sert. `EditeurApp` = 18 ko pour quatre écrans. Build OK. | Rien en cours — je te demande la suite. | Rien |
| 18:40 | **ED-8 — « qui n'a pas payé », cinquième écran.** `GET /editor/receivables` : ce qui reste dû, **le plus en retard devant**, et **une facture soldée sort de la liste** — ni grisée ni repliée. Une liste de créances où figurent les factures payées oblige à lire pour savoir quoi faire, et c'est l'effort qu'on veut supprimer. Le retard est calculé **par le serveur** : côté navigateur il dépendrait de l'horloge du poste, et deux exploitants verraient deux retards différents pour la même facture — le plus optimiste ferait foi. **Deux trouvailles en chemin.** (1) Mes factures d'abonnement ne portaient **aucune date d'échéance** : `delaiPaiementDefautJours` existe dans le paramétrage, valeur 30, et personne ne le lisait — donc aucun retard n'était calculable. Encore ton motif : le mécanisme existait, l'appel manquait. Corrigé, et rendu **déterministe** — l'échéance dérive du mois facturé et non de l'heure d'exécution, pour qu'une facture rejouée après incident porte la même date. (2) Le scellement NF525 m'a **refusé** de modifier une facture émise pendant que j'écrivais les tests. Il avait raison ; j'ai fabriqué les retards en facturant de vieux mois plutôt qu'en trichant sur la date, ce qui rend le test plus fidèle. Tests : `tests/Subscription` **110 verts / 548 assertions**, `tests/Platform` 62 verts, build OK — cinq écrans pour 20,6 ko. | Rien en cours — je te demande la suite. | Rien |
| 20:15 | **Ton paramètre est branché, et la commande de facturation est écrite.** Le facturier lit `tauxTvaAbonnement` ou refuse en disant où le choisir — ma résolution automatique est supprimée, elle n'avait plus lieu d'être. J'ai encodé ta distinction : `null` = **non décidé**, refus explicite ; un taux à **zéro** = exonération, qui facture normalement. Les deux se ressemblent et n'ont rien à voir. `subscription:facturer-le-mois` facture un mois entier en une passe, rejouable sans doublon, avec `--a-blanc`, et **un échec ne bloque pas les clients suivants** — interrompre priverait de facture tout le monde pour le problème d'un seul. | **MAIS JE N'AI PAS DÉCLARÉ LA TÂCHE AU CATALOGUE**, et c'est ton propre critère qui me retient — voir ci-dessous. | Une question pour Maxime, pas un blocage |
| 22:10 | **La facturation mensuelle est automatisée, et la tâche est déclarée** — elle apparaît à `--status`, critique, avec la conséquence écrite plutôt que la fonction. Sur les trois conditions de Maxime, **deux sont désormais garanties par du code, pas par de la vigilance** : le compte est créé `Suspendu`, donc aucun jeton émis pour lui ne passe le contrôle d'activité — un mécanisme qui **existait déjà**, je n'en ai pas ajouté un — et son mot de passe est un aléa que personne ne conserve. Il n'est rattaché à aucun établissement : aucun droit, aucune affectation. Le nom est testé littéralement contre « système », « system » et « admin » : il est lu par un client sur un document opposable, il doit se comprendre et non se décoder. **`--auteur` reste disponible**, et ce n'est pas une politesse : le jour d'un rattrapage on veut que la facture dise qui l'a lancé. Tests : `tests/Subscription` **117 verts / 591 assertions**, `tests/Platform` 62 verts. | Le courriel de bienvenue, avec `establishment.provisioned` et son abonné — enfin un preneur pour l'événement que j'avais renoncé à émettre. | Rien |
| 23:30 | **ED-9 — le client qui vient de payer reçoit ses accès.** Dernier maillon entre « il a payé » et « il utilise » : sans lui, le provisionnement était muet. La chaîne complète passe par le **vrai bus** — activation → `subscription.activated` → provisionnement → `establishment.provisioned` → courriel : deux événements, trois abonnés, rien qui s'appelle directement. **`establishment.provisioned` est enfin émis** : c'est le nom auquel j'avais renoncé il y a deux jours faute de preneur, et le cliquet avait raison de le refuser. Il a même **baissé** — « sans preneur : 25, plafond 26, 1 résorbé ». Je ne lance pas `--nettoyer` : la ligne de base est dans `bin/`, chez `claude-C`. **Le jeton n'est pas transporté par l'événement** : un événement se journalise, se rejoue, se transporte, et un identifiant de connexion qui voyage ainsi finit dans un fichier de journal. Celui qui délivre le jeton le frappe au moment de l'envoi, et un test vérifie qu'il correspond bien à l'empreinte stockée — sinon le client recevrait un lien mort, pire que pas de courriel. **Base légale `Contractuelle`, déclarée explicitement**, avec un test qui vérifie qu'on ne s'est pas contenté du défaut. Tests : `tests/Subscription` **121 verts / 602 assertions**, `tests/Platform` 62 verts. | Rien en cours — je te demande la suite. | Rien |
| 09:20 | **`ExpenseReportFixtures` est idempotente** — les trois rôles sont cherchés avant d'être créés, selon ton patron. **Vérifié par le seul contrôle qui le montre** : j'ai chargé la fixture **deux fois de suite** sur la même base ; le second passage échouait sur « Duplicate entry », il passe. Tests `tests/Finance` : 112 verts / 1151 assertions. | **Une proposition, et je ne la prends pas de moi-même.** Ce contrôle tient en dix lignes — booter le noyau, charger chaque fixture deux fois, échouer si la seconde lève. Il aurait attrapé les quatorze cas d'un coup, et il attrapera le quinzième. Sans lui, la correction d'aujourd'hui se défera à la prochaine fixture écrite : c'est exactement ta règle, un mécanisme qui dépend de la vigilance n'est pas un mécanisme. `bin/` est chez `claude-C` et le harnais de test aussi ; dis-moi qui le prend. | Rien |
| 11:40 | **FAC-1, le moteur : devis → bon de commande → bon de livraison → facture.** **Une entité à natures plutôt que trois jumelles** — les trois pièces portent les mêmes choses et ce qui les distingue tient en trois phrases, pas en trois schémas ; c'est déjà le choix de `Facture` avec sa `nature`. **Le cas qui justifie le bon de livraison est testé** : dix commandés, sept livrés → la facture porte **sept**. Facturer la commande ferait payer ce qui n'est pas arrivé, et une ligne en rupture totale disparaît au lieu de figurer à zéro. Trois refus encodés : un devis ne produit pas deux commandes, une pièce déjà facturée ne se refacture pas, et le destinataire est **copié et non partagé** — un changement d'adresse aujourd'hui ne doit pas réécrire le devis d'il y a six mois. Migration écrite à la main (16 lignes gardées sur 124), vérifiée sur base neuve. Tests : `tests/Facturation` 32 verts / 288 assertions, dont 7 neufs. | L'API et l'écran, pour que ce soit utilisable. | Rien |

---

## Blocages ouverts

**B-1 — La flotte D→I a été créée sur le dépôt nu, pas sur le clone.** `creer-flotte.sh` a posé les
worktrees D→I dans `/home/debian/billetterie.git` alors que A/B/C vivent dans `/home/debian/billetterie`.
Trois effets vérifiés :

1. **Pas de `origin`** dans D→I : `git fetch origin && git merge origin/main` et `git push origin claude-D`
   — le battement prescrit par FLOTTE.md — ne peuvent pas s exécuter tels quels.
2. **`pre-receive` ne s exécute jamais** pour ces six sessions : un worktree du dépôt nu écrit sa branche
   sans push. `pre-commit` s exécute bien ; ce sont les garde-fous de poussée qui sont contournés — soit
   précisément ce que la règle 5 protège.
3. **Identité de commit partagée** : `creer-flotte.sh:70` fait `git config user.name` sans `--worktree`.
   Les worktrees partagent `config`, chaque tour de boucle écrase le précédent, et les six sessions
   commitent aujourd hui sous `claude-I`. Je contourne par `git -c user.name=claude-D` à chaque commit,
   ce qui ne touche pas la configuration partagée.

Maxime a tranché : le correctif appartient à `claude-A`, je ne sors pas de mon périmètre. **Les six
branches avaient `0` commit au-delà de `main` au moment du constat** — c est la fenêtre où recréer les
worktrees sur le clone ne coûte rien. Elle se referme à chaque commit.

**B-2 — Cinq événements manquent au catalogue.** RG-ED-04 impose que le tunnel émette
`subscription.activated` et que le provisioning y soit abonné. Or `catalogue-evenements.md` ne connaît
que `subscription.created` et `subscription.suspended`. Manquent : `subscription.activated`,
`subscription.cancelled`, `subscription_option.added`, `subscription_option.removed`,
`establishment.provisioned` (liste déjà dressée par la spec, §8). `COORDINATION/CONTRACT/**` est le
périmètre de `claude-A` : je ne les ajoute pas. Je construis tout ce qui n en dépend pas et je câble
l émission en dernier.

**Point annexe, sans blocage.** `app/config/reference.php` ressort modifié dans chaque worktree
(`composer install` le regénère, `messenger.enabled` false→true). Ce n est pas du travail : je ne le
commite pas. Et le brief du document de lancement prescrit `git add -A` là où PLAYBOOK §7.2 impose un
staging explicite ; je suis le PLAYBOOK.

**B-1 — état au 24/08 12:31 : partiellement levé.** Les worktrees D→I sont passés sur le clone et
disposent d un `origin` : le battement prescrit fonctionne, et `pre-receive` s exécute de nouveau.
**Reste ouvert le point 3** — `git config user.name` vaut toujours `claude-I` pour tout le monde,
désormais dans `/home/debian/billetterie/.git/config`. Le correctif tient en une ligne
(`git config extensions.worktreeConfig true`, puis `git config --worktree user.name claude-X` dans
chaque worktree) ; il appartient à `claude-A`. En attendant je commite avec `git -c user.name=claude-D`.

**B-3 — le rôle modèle « Administrateur etablissement » n existe pas.** `ProvisioningService` duplique
un `Role` marqué `estModele = true` pour habiliter l administrateur du client, exactement comme
`DuplicationRoleProcessor`. Ce modèle n existe dans aucune fixture : à ce jour, CA-1 échouerait avec
le message prévu (« Rôle modèle absent »).

**C est délibéré, et je ne le contourne pas.** Composer ici la liste des permissions d un
administrateur de client reviendrait à écrire une seconde politique d habilitation à côté de celle
de `Securite`, qui divergerait dès la première évolution — et à décider seul, dans un module de
facturation, de ce qu un client a le droit de faire. Le rôle modèle relève de `Securite`, donc de
`claude-A`. **Ce qu il me faut :** un `Role` `estModele = true` nommé `Administrateur etablissement`,
portant le bundle de permissions d un administrateur d établissement. Je m aligne sur le nom qu il
retiendra ; seule la constante `ProvisioningService::ADMIN_ROLE_TEMPLATE` est à changer.

**Ce que je retiens pour les charges utiles**, puisque tu me demandes de te le signaler.
`subscription.activated` → `planCode`, **`capabilities`**, `effectiveFrom`. Un seul écart avec ta
proposition : `capabilities` au lieu de `options`. `Subscription::activeCapabilities()` renvoie la
somme de la formule **et** des options actives à l instant donné ; l appeler `options` ferait croire
à un abonné qu il n y a là que les suppléments, et il activerait un client sans les modules compris
dans sa formule. Le reste est repris tel quel.

**`establishment.provisioned` : je ne l ai pas émis, et je te dois la raison.** C était le fait
naturel à annoncer après le provisioning, et j avais écrit l émission. Je l ai retirée avant de
pousser : son consommateur prévu est le courriel de bienvenue, qui relève de `Communication` et
n existe pas. Le nom serait donc entré au catalogue **sans preneur**, et le cliquet est gelé à 26 sur
26 — j aurais reproduit exactement ta poussée refusée de ce matin. Il viendra avec son abonné, dans
le même commit. La règle que tu as tirée de ton échec m a évité le mien.

**Q-1 — rien ne désigne l établissement éditeur, et il va falloir trancher.** D12 pose que l éditeur
est un `Etablissement` de la plateforme, mais **aucun paramètre, aucun marqueur, aucune constante ne
dit lequel**. J ai vérifié : rien dans `app/config/**`, rien dans `app/src/**`.

En attendant, je le **déduis** de la fiche CRM du prospect (`Client::getEtablissementCreation()`) :
c est bien l éditeur, puisque c est son CRM qui a créé le prospect, et cela me suffit pour poser le
tenant de `subscription.activated`. **Mais la déduction ne tiendra pas pour le tunnel**, qui est le
reste de ED-3 : la spec (§3) prévoit un **prospect anonyme** qui compose son panier et signe son
mandat *avant* d avoir la moindre fiche. À ce moment-là il n y a pas de client d où déduire quoi que
ce soit, et un événement sans tenant est refusé par le contrat.

**Ce qu il me faut, avant d écrire le tunnel :** une désignation explicite de l établissement
éditeur — paramètre de configuration, drapeau sur `Etablissement`, ou constante de référentiel. Je
n en choisis aucune, c est du noyau (`Organisation`), donc ton périmètre. Dis-moi laquelle et je m y
branche ; en attendant je continue sur ce qui n en dépend pas.

**Note d exploitation, pour tout le monde** — `doctrine:schema:drop --force --full-database` **ne
suffit pas** à repartir de zéro : il laisse `hot_seq` derrière lui, et la migration suivante échoue
sur « hot_seq already exists ». Le symptôme ressemble à une migration cassée ; ce n en est pas une.
Il faut `doctrine:database:drop --force` puis `doctrine:database:create`, puis migrer.

**Choix de conception du tunnel, pour ta revue.** La référence de mandat (RUM) est **dérivée de
l identifiant de l abonnement**, pas tirée au hasard. Un prospect qui se trompe d IBAN resigne, et un
formulaire renvoyé deux fois arrive deux fois : avec un RUM aléatoire, chaque passage créerait un
mandat de plus pour le même client, et la remise suivante ne saurait pas lequel présenter. Dérivé, il
désigne toujours le même mandat — on le met à jour. C est le même identifiant que celui qui porte
l idempotence du provisionnement, donc deux mécanismes qui ne peuvent pas se désynchroniser. Test
dédié : resigner avec un autre IBAN laisse **un seul** mandat, porteur du nouveau.

**Ce que le port attend de chaque module** (à répartir par toi, ce n est pas à moi de le demander).
Un module qui porte de la configuration reprenable implémente
`App\Subscription\Port\ConfigurationSnapshotProvider` : trois méthodes — `capability()`,
`capture(Etablissement)`, `replay(Etablissement, array)`. Le tag est posé par `AutoconfigureTag` sur
l interface, donc **rien à déclarer dans `services.yaml`** et rien à toucher chez moi.

Deux contraintes à leur transmettre : l instantané ne porte **que de la configuration**, jamais de
données personnelles (RG-ED-08) ; et `replay()` doit être **idempotent**, parce que le provisionnement
l est par construction — un rejeu qui dupliquerait les offres livrerait un catalogue en double le jour
où un rappel bancaire arrive deux fois.

Le premier concerné est `Offre` (`claude-G`), qui porte les tarifs. Je ne le lui demande pas
directement : la règle 2 dit qu un pair ne déplace pas un périmètre, et je m y tiens.

**ED-4 touche ton périmètre, dis-moi qui le prend.** Le lot est « accès d assistance borné et audité »
(RG-ED-07). `SupportAccess` — le titulaire, l établissement visé, la fenêtre, le motif — est chez moi.
Mais tracer la tentative refusée à l audit, c est `app/src/Audit/**`, donc toi. Je peux écrire
l entité, la fenêtre de validité et le refus en échec fermé sans y toucher, et te laisser le
raccordement à l audit. Je démarre là-dessus ; si tu préfères prendre le lot entier, dis-le et je
prends autre chose.

**Les piles de test sont ramassées.** Maxime a donné son feu vert et j ai lancé
`bin/ramasser-piles-test.sh --age=6 --faire` : **21 piles supprimées, 3 gardées** (les tiennes et
celles de `claudeG` et `claudeI`, actives). **27 réseaux Docker → 8.** Le script est bon : il décide
sur la dernière activité et non sur l âge, et il a correctement épargné les trois piles en cours
d usage. La panne du 24/08 est écartée avec de la marge.

**Ce que je retiens d ED-4 pour la répartition.** J ai failli te demander la moitié du lot parce que
« tracer à l audit, c est `app/src/Audit/**` ». C était une mauvaise lecture de la règle : le
périmètre interdit d **écrire** dans les fichiers d un autre, pas d **appeler** ses services publics.
`ProvisioningService` crée déjà des `Etablissement` et des `Utilisateur` sans que cela pose problème.
La question à se poser n est pas « à qui appartient ce dossier » mais « est-ce que j y écris ».

**Défaut de sécurité trouvé en écrivant la migration du rôle — il est chez `Securite`, pas chez moi.**

`RoleAPrivileges::estAPrivileges()` décide si le **MFA est obligatoire** (RG-M8-06, CA-4). Il le décide
en cherchant une permission dont le **module** vaut `securite`. Or le joker a pour module `*`.

**Conséquence : un rôle qui ne porte que `*.*` est tout-puissant ET dispensé de MFA**, sans que rien ne
le signale. C'est le cas d'« Administrateur groupe », créé par `Version20260824231500`. Vérifié sur une
base construite uniquement par les migrations :

```
Administrateur d'établissement   1   *.*
Administrateur d'établissement   1   securite.gerer
Administrateur groupe            1   *.*
```

Je me suis protégé chez moi en rattachant **aussi** `securite.gerer` à mon rôle modèle. Cela ne change
aucun droit — le joker les couvre déjà — mais cela rend le rôle reconnaissable par le contrôle du MFA.
C'est un contournement local et explicite, sans effet le jour où le service saura lire le joker.

**La correction de fond t'appartient** : faire reconnaître `*` par `RoleAPrivileges`, ou décider que le
joker implique les privilèges. Je n'y touche pas.

**Le garde-fou de couverture de périmètre a refusé ma première version, et il avait raison.**

J'avais exposé `Plan` et `PlanOption` directement en `#[ApiResource]`. Refus :

```
=== ÉCHEC — entité exposée sans cloisonnement possible ===
  - Subscription/Entity/Plan.php
  - Subscription/Entity/PlanOption.php
```

Deux raisons, et la seconde ne m'était pas venue :

1. **Une entité exposée dont la collection ne se filtre pas est lisible d'un tenant à l'autre.** Ces
   deux-là n'ont pas d'établissement — c'est un catalogue global, à dessein — donc rien ne pourrait
   les cloisonner si quelqu'un ajoutait demain une opération sans passer par mon fournisseur.
2. **Une entité exposée rend modifiable tout ce qu'elle sait écrire.** Une ressource dédiée n'expose
   que les cinq champs que l'administration doit toucher, aujourd'hui et quand l'entité aura gagné
   des colonnes.

Le garde-fou **offrait l'exemption** — « si l'entité est globale à dessein, ce n'est pas à la ligne de
base de l'absorber : dis-le dans MESSAGES.md ». Je ne l'ai pas prise. Corriger la conception a coûté
une heure et supprime la question ; l'exemption l'aurait laissée ouverte pour toujours, dans un
fichier que personne ne relit. Le commit fautif n'a jamais été poussé : je l'ai défait plutôt que de
laisser dans l'historique un état que les garde-fous refusent.

**Second refus, plus intéressant : la résolution par identifiant client.** Le cliquet C19 exige qu'une
entité résolue depuis l'URL soit confrontée au périmètre. Ici il n'y a pas d'établissement d'entité à
confronter — le contrôle est en amont, une fois, par `EditorOnly::assertEditor()`. J'ai utilisé
l'**annotation déclarée** que le script prévoit, `@cloisonnement-verifie:` avec sa raison obligatoire,
plutôt que de demander un relèvement de plafond. Le commentaire du script explique pourquoi elle
existe et il a raison : *« une exemption déclarée est greppable »*. `grep -rn "@cloisonnement-verifie"
app/src` la retrouvera le jour où quelqu'un auditera.

**Ce que j'en retiens.** Un garde-fou qui propose une porte de sortie n'est pas un garde-fou qu'il
faut franchir. Les deux fois, la porte existait ; les deux fois, la refuser a produit un meilleur
code — une ressource qui n'expose que le nécessaire, et une exemption qu'on peut retrouver.

**Pourquoi je n'ai pas déclaré `subscription:facturer-le-mois` au catalogue de l'ordonnanceur.**

Une facture NF525 est un document scellé qui **porte le nom de qui l'a émise** : `Facture.creePar`
est non nullable, et ce n'est pas un oubli de modélisation. Une tâche périodique n'a pas
d'utilisateur connecté.

Le dépôt a bien le précédent — `SessionSystemeBoutiqueResolver` et `SessionSystemeResolver` créent un
utilisateur technique — mais les deux portent le même avertissement dans leur en-tête : *vente sans
opérateur humain identifié, risque n°1 du plan*. Ce qui était déjà inconfortable pour une vente en
ligne l'est davantage pour une facture scellée.

**Et c'est exactement ton critère.** Tu as retiré `personnel:traiter-echeances-sortie` parce qu'elle
« exige une identité humaine et ne peut pas tourner sans surveillance ». Une facturation qui attribue
des documents scellés à un compte de service est le même cas, à un endroit plus sensible : le nom
porté par la facture est opposable.

**Ce que j'ai fait à la place.** `--auteur` est **obligatoire** : la commande s'exécute au nom d'une
personne nommée, qu'un exploitant lance à la main. Elle facture un mois entier en une passe, ce qui
couvre le besoin réel — personne ne facture au jour près — sans trancher une question comptable à la
place de Maxime.

**Ce qu'il faut pour la déclarer**, et c'est une décision de Maxime, pas la nôtre : soit il accepte
qu'un compte de service émette les factures et le désigne au déploiement, soit on ajoute une mention
explicite sur la facture. Je lui ai posé la question. Tant qu'elle n'est pas tranchée, la déclaration
tiendrait en quatre lignes mais dirait quelque chose que personne n'a décidé.

## 26/08 — FAC-1, l'API : huit opérations, et deux garde-fous qui ont parlé

Huit opérations sur `/api/billing/documents` : lecture, création, et cinq gestes (`issue`, `accept`,
`reject`, `derive`, `invoice`). La création ne produit **qu'un devis** — les deux autres natures se
dérivent, donc la filiation, qui est ce que le lot apporte, ne peut pas être contournée par la route
de création. L'établissement vient de la session, jamais du corps (D3). `invoice` exige
`facturation.emettre_directe`, pas seulement `facturation.gerer`.

Un seul processeur pour les cinq gestes : ils partagent le contrôle d'accès, la résolution et le
traitement d'erreur, et cinq classes jumelles divergeraient au premier correctif appliqué à une
seule. Le cloisonnement n'y est pas rejoué : `PerimetreFacturationExtension` restreint déjà la
résolution, et deux règles finissent par diverger sans qu'on sache laquelle fait foi.

**Le test de cloisonnement, je l'avais d'abord monté sur l'administrateur du socle — et il échouait.**
La cause n'était pas le code : l'administrateur est affecté sur A *et* sur B, donc lui montrer la
pièce de B est correct. Monté sur lui, ce test aurait pu passer au vert plus tard en ne mesurant
rien. Le témoin est maintenant le **lecteur** du socle, affecté sur A seulement et porteur du joker
`*.lire` : il franchit le contrôle d'accès et bute uniquement sur le périmètre, ce qui rend le 404
attribuable au cloisonnement et à rien d'autre. Un second test dit l'autre moitié — le lecteur voit,
mais ne peut pas `issue` (403) —, sans quoi les cinq routes de geste auraient pu n'exiger que la
lecture sans que rien ne s'en aperçoive, puisque l'administrateur porte `ROLE_ADMIN` et franchit tout.

**D41 a trouvé un vrai trou.** `CommercialDocument` n'avait aucun `denormalizationContext` : tout
mutateur était donc écrivable, et un corps `{"etablissement": "…/voisin"}` posté sur une route de
geste aurait été désérialisé sur la pièce chargée, que le `flush()` aurait déplacée chez un autre
exploitant. Je ne l'ai pas supposé : la sonde `testUnCorpsDeRequeteNeDeplacePasLaPieceChezLeVoisin`
montre que ce n'est **pas** exploitable aujourd'hui, les huit opérations portant `input: false`. Donc
exposition latente, pas fuite. J'ai quand même déclaré un groupe d'écriture **vide** : huit drapeaux
qu'il faut penser à maintenir ne sont pas une protection, il suffit qu'une neuvième opération naisse
sans le sien. La sonde reste dans la suite pour que la garantie soit mesurée et pas déclarée.

Tests : 14 d'API verts / 93 assertions. `tests/Facturation` 40 verts / 335, `tests/Platform` 62 verts
/ 259. 11 garde-fous sur 12.

### Bloqueur remonté à claude-A — D5 traite une référence comme une déclaration

D5 refuse le fichier sur huit lignes de la forme `is_granted('PERM', 'facturation.gerer')`. Ces codes
sont **déclarés** dans `src/Facturation/DataFixtures/FacturationFixtures.php:35` et référencés à
l'identique par `Facture.php`, `SerieNumerotation.php`, `ParametreFacturationEtablissement.php`,
`FactureRenduProvider.php`. Aucune classe de constantes n'existe : ce sont des littéraux partout dans
le module. Écrire `billing.manage` désignerait une permission absente du catalogue et le voter
refuserait tout ; en fabriquer un second, anglais, pour une seule entité couperait le modèle de droits
du module en deux.

D5 énonce lui-même que « seules les *déclarations* sont contrôlées, pas les références » — il applique
la distinction aux classes, pas aux codes de permission.

**Je n'ai pas pris le remède proposé**, qui est de retirer le mot du lexique. « facturation » est bel
et bien français ; l'ôter aveuglerait le contrôle pour les neuf sessions, sur toutes les familles de
mots, pour débloquer un fichier. Un garde-fou ne devrait jamais offrir comme remède la réduction de
son propre périmètre. Correctif proposé à claude-A : ne signaler un code de permission que s'il
n'existe pas déjà dans `origin/main` — un code français réellement neuf reste attrapé, une référence
passe, et le contrôle garde son objet.

Le commit FAC-1 reste local, prêt à partir. **Je n'attends pas** : j'enchaîne sur PAY-3, qui ne dépend
ni de D5 ni de l'arbitrage sur l'écran.

### Question ouverte

À qui l'écran FAC-1 ? Le lot sert vos **clients** — les clubs qui vendent sans caisse — pas votre
administration. L'écran va donc dans `frontend/src/pages/` (back-office exploitant) et non dans
`frontend/src/editeur/`, qui est mon périmètre. Je ne me l'attribue pas de moi-même.

## 26/08 (suite) — PAY-2 : le préavis de prélèvement, et l'écran FAC-1

### PAY-2 — ce que j'ai trouvé en ouvrant le module

**Le module SEPA n'émet rien.** Aucun fichier de `src/Sepa/` ne dispatche d'événement ni n'appelle de
notifieur. Le préavis réglementaire — informer le débiteur du montant et de la date avant chaque
prélèvement, quatorze jours sauf autre délai convenu — n'existait donc nulle part. Les remises
partaient sans qu'aucun client n'ait été prévenu, et ce n'est pas propre à PAY-2 : c'était vrai de
toutes les collectes, Sport et Piscine comprises.

`DebitPreNotification` consigne le préavis, `DebitPreNotifier` l'envoie **et** le relit. `announce()`
sans `covers()` aurait produit une table à alimenter, pas une garantie.

Trois points qui ne sont pas des détails :

**Le fondement est `Contractuelle`, pas `Consentement`.** Le point de passage de `Platform` refuse par
défaut faute de consentement. Sur cette base, un client ayant refusé la prospection ne recevrait jamais
son préavis — et le prélèvement qui suit serait irrégulier. Prévenir quelqu'un qu'on va débiter son
compte n'est pas de la sollicitation. C'est la deuxième fois que cette valeur par défaut aurait produit
un défaut de conformité en croyant protéger la vie privée ; la première était le courriel de bienvenue.

**`Journalisee` n'est pas `Envoyee`**, et le commentaire de `NotificationOutcome` le disait déjà. Aucun
prestataire d'envoi n'est branché (D19) : l'adaptateur journalise. `covers()` rejette donc un préavis
journalisé. Il est quand même consigné, pour qu'un exploitant puisse compter ce qui ne part pas.

**Le montant fait partie du préavis.** Annoncer trente euros puis en prélever trois cents n'est pas un
préavis, c'est un préavis pour autre chose. Sans cette comparaison, il aurait suffi d'avoir prévenu une
fois pour prélever n'importe quoi ensuite : la garantie serait devenue une case cochée.

### Le câblage — une décision que je n'ai pas prise seul

`covers()` n'était appelé par personne, ce qui est exactement le motif que je répète. Mais le brancher
arrête les prélèvements de quatre verticales tant que D19 tient. J'ai posé le choix à claude-A avec
trois options plutôt que de trancher : il a retenu l'exclusion, et il a corrigé mon analyse sur un point
que j'avais manqué — **aujourd'hui l'exclusion et le fail-closed donnent le même résultat**, seule la
première explique pourquoi ; ce qui les départage est le jour où un prestataire existera.

`reasonNotCovered()` remplace donc le booléen, et nomme le cas `Journalisee` à part **parce que c'est
celui de tout le dépôt aujourd'hui** : le confondre avec « aucun préavis émis » enverrait chercher un
oubli d'appel là où il manque une brique d'infrastructure. La remise porte `nb_exclues` et
`motif_exclusion` — une remise vide parce que tout a été exclu n'est pas une remise vide faute
d'échéances, et les confondre rendrait le blocage invisible. Aucun interrupteur : un garde-fou désarmé
par défaut est décoratif.

**J'ai cherché les appelants avant de pousser**, plutôt que de laisser la casse tomber sur quelqu'un à
froid. Ils sont deux : `SepaFixtures`, que j'ai traité, et `Sport\Service\GenererRemiseSepaHandler`, qui
n'est pas à moi. `tests/Sport/Api/RemiseSepaRecablageTest.php` échoue donc, avec un message qui dit quoi
faire. Recette transmise à claude-A, avec le point de fond : **ce test ne casse pas malgré le
changement, il casse parce qu'il décrivait un comportement qui n'était pas licite** — l'adapter sans
adapter le chemin réel remettrait le problème où il était.

### L'écran FAC-1

L'onglet « Facturation » était un placeholder désactivé depuis le départ. Il ouvre maintenant.

**Les gestes affichés viennent du serveur** (`gestesPossibles`), pas d'une table recopiée dans le front.
C'est D39 appliqué à une règle métier plutôt qu'à une règle d'autorisation : quand le client rejoue une
règle du serveur, il doit la rejouer entière, et le plus sûr est de ne pas la rejouer. Un test de
contrat protège ce champ à chaque étape — s'il disparaissait de la sérialisation, l'écran n'afficherait
plus aucun bouton, sans erreur et sans trace.

Les **droits**, eux, restent évalués côté client : le serveur ne connaît pas l'utilisateur au moment où
il sérialise la pièce. D54 : « Facturer » reste visible et désactivé pour qui n'a pas
`facturation.emettre_directe`, et l'infobulle dit d'abord **pourquoi** le geste est renforcé, ensuite si
vous y avez droit.

Conventions de claude-H suivies, et sa réserve relayée à Maxime : elle ne peut pas céder un périmètre
qu'elle n'a pas attribué. Ce que claude-A a fait est une répartition de charge, pas un déplacement de
frontière — `frontend/src/pages/` reste à elle.

### Un angle mort trouvé dans `verifier-formats`

Le contrôle normalise toute interpolation en `{id}`. Un chemin composé de deux variables —
`/api/billing/documents/${id}/${geste}` — devient `/billing/documents/{id}/{id}`, ne correspond à aucun
`uriTemplate`, et le contrôle réclame un `ld: true` dont ces routes n'ont que faire. J'ai rendu mes cinq
routes littérales plutôt que de contourner le contrôle : un geste mal orthographié échoue maintenant à
l'appel au lieu de partir en 404. Le défaut du contrôle reste, il est signalé à claude-H et claude-A —
`bin/` et `frontend/scripts/` ne sont pas à moi.

**Sur ma branche `claude-D`, pas sur `main`** : `b221e65` (API FAC-1), `b41492f` (préavis), `6dbb912`
(câblage), `7b07119` (écran). Douze garde-fous verts à chaque fois. claude-A retient la fusion tant que
`tests/Sport/Api/RemiseSepaRecablageTest.php` n'est pas réparé par claude-I — un `main` rouge coûte plus
cher à huit sessions que le défaut qu'il signale.

**Et j'écrirai désormais « sur `main` » ou « sur ma branche », jamais « poussé » tout court.** J'avais
écrit « poussé » quatre fois dans mon point à Maxime : vrai de ma branche, faux de `main`, et lui a lu
« livré ». claude-H l'a vérifié — c'était la troisième livraison annoncée qu'elle vérifiait dans la
journée sans la trouver sur `main`, et une fois elle avait déjà retiré son contournement. Le mot
recouvre deux engagements différents ; trois mots de plus les séparent.

## 26/08 (suite) — Compta : deux défauts qu'aucun test ne couvrait

claude-A m'avait demandé de faire tourner la suite de `Compta` et de corriger ce qui casse. **Rien ne
cassait** : 81 tests verts. Ce que j'ai trouvé, je l'ai trouvé en répondant aux trois questions de
claude-H, qui branchait l'écran — et les deux défauts vivaient précisément là où aucun test ne
regardait.

### L'exploitant signait à l'aveugle le seul geste irréversible du module

La clôture est définitive : aucun code du dépôt ne repasse une période à `Ouverte`. Elle fige un
arrêté chiffré — produits, TVA, encaissements, nombre d'écritures. **Or ces montants n'étaient
calculés qu'après.** La meilleure fenêtre de confirmation possible se réduisait donc à « faites-moi
confiance ».

`POST /compta/periodes/{id}/simuler-cloture` rend les mêmes montants **par le même code** :
`ClotureHandler::arrete()` est extrait, et la clôture l'appelle en n'y ajoutant que `clotureLe`. Un
aperçu calculé à part aurait fini par annoncer autre chose que ce que la clôture enregistre, et la
divergence se serait découverte **sur un arrêté** — trop tard, et sur le document qui fait foi. Le test
central vérifie cette égalité poste par poste, pas la présence de la route.

`clotureLe` est **absent** de l'aperçu, délibérément : tant que rien n'est clôturé il n'y a pas de date
de clôture, et en inventer une ferait passer un aperçu pour un arrêté que quelqu'un imprimerait.

**Un POST pour une lecture, et c'est le bon arbitrage.** Mon premier jet résolvait la période par un
`find()` direct — ce qui aurait court-circuité l'extension de cloisonnement, la famille de défauts que
ce dépôt a rencontrée seize fois. `read: true` fait passer la résolution par le provider Doctrine, donc
par le périmètre. Entre une méthode HTTP discutable et un contrôle d'accès dupliqué, le choix n'est pas
serré, et la raison est écrite dans le fichier pour que personne ne « corrige » ça en `GET`.

### Un journal comptable qui pouvait doubler — et pire, se couper en deux

`ventesValideesNonComptabilisees()` est lue **avant** la boucle et le `flush()` n'a lieu qu'à la fin :
deux requêtes qui se chevauchent généraient toutes les deux. Le cas n'est pas théorique — l'exploitant
clique, ne voit rien bouger parce que la requête est longue, et reclique. Un journal comptable doublé
ne se corrige pas en effaçant des lignes ; il se corrige par extourne, et ça se voit au contrôle.

Verrou pessimiste sur la ligne du profil plutôt qu'une table de verrous : rien à créer, et surtout
**aucun verrou orphelin à ramasser** après un incident — il tombe avec la transaction. `symfony/lock`
n'est pas installé et ce n'était pas la peine d'ajouter une dépendance.

**La transaction ferme un second défaut, que je n'avais pas vu en signalant le premier, et qui est plus
grave.** `persisterEcriture()` écrit à chaque écriture — volontairement, le scellement NF525 relit la
base pour chaîner. Sans transaction, une interruption en cours de route laissait un journal **à moitié
généré**. Comme le dit claude-H : le doublon se voit, le trou ne se voit pas.

### Ce que cette journée m'apprend sur la mesure de couverture

claude-H mesure les opérations atteignables depuis le front : 135 ce matin, 196 ce soir sur 1030. Mais
son propre indicateur n'aurait **jamais** vu ce qu'elle a trouvé en branchant `generer` : le serveur
renvoyait la liste des ventes sautées pour mapping incomplet, aucun écran ne l'affichait, des ventes
réelles restaient hors comptabilité et rien ne le disait. L'opération était atteignable ; l'information
n'atteignait personne.

C'est le même motif que je poursuis depuis deux jours, d'un cran plus profond : **un mécanisme qu'il
faut penser à alimenter n'est pas un mécanisme — et un signal qu'on émet sans que personne ne le lise
n'en est pas un non plus.**

**Sur ma branche `claude-D`, pas sur `main` : `bc8706b`.** 13 garde-fous verts. `tests/Compta` 81 verts
/ 685, `tests/Platform` 62 verts / 259.

## 26/08 (suite) — PAY-2 : la bascule carte → prélèvement, et ce que le dépôt sait sans le dire

### La moitié qui est chez moi, avec l'autre moitié dite en clair

`CardDebitFallback` vérifie qu'un mandat SEPA **actif** existe, envoie un préavis « carte refusée », et
enregistre une dette exigible seulement **après** que le préavis aura couru. Elle ne prélève pas :
prélever tout de suite serait un prélèvement sur un moyen que le client ne s'attendait pas à voir
utilisé ce mois-ci — exactement la situation où l'absence de préavis se conteste, et où elle se
conteste avec raison. `CardFallbackEcheanceSource` présente ensuite ces dettes à la collecte par le
même port que Sport et Piscine : un chemin parallèle aurait échappé au préavis, au cloisonnement et au
comptage des exclues.

**Le déclencheur n'existe pas — c'est PAY-3, chez claude-G — et l'absence est épinglée.**
`CardDebitFallbackNonBrancheTest` échouera le jour où quelqu'un appellera le service, et son message
dira quoi supprimer. Un rapport se lit une fois ; ce test parlera au moment précis où quelqu'un croira
la fonctionnalité terminée. C'est la seule façon que j'aie trouvée de ne pas livrer un quinzième
mécanisme muet après en avoir dénoncé quatorze.

### Le contrôle du mandat n'est pas une garde technique : c'est le filtre métier

Trouvé en répondant à claude-G, qui me demandait quoi faire d'un refus de carte sur une vente anonyme.
Ma réponse spontanée — « sans client, pas de mandat, donc je refuse » — était vraie et à côté.

**Au comptoir, la bascule est une mauvaise réponse même si un mandat existe.** Une carte refusée devant
un client qui est là se règle en trente secondes : il paie autrement. Lui annoncer un prélèvement dans
quatorze jours lui imposerait un débit qu'il n'a pas choisi, pour une transaction soldable sur place.

La bascule n'a de sens que quand le client **n'est pas devant nous**. Et ce qui sépare les deux cas
n'est pas la présence d'un client identifié — une vente de guichet peut être nominative — mais celle
d'un **mandat actif**, qui n'existe que dans une relation suivie. Le contrôle que j'avais écrit comme
une précondition sélectionne donc exactement la population pour qui la bascule est utile. Consigné dans
le fichier, parce que quelqu'un finira par vouloir « assouplir » ce contrôle pour élargir la couverture.

### Le garde-fou écrit ce matin m'a attrapé — et il lui manque la moitié du problème

Ma source d'échéances filtrait par `->andWhere('m.etablissement = :e')->setParameter('e', $etablissement)`.
Elle rendait une liste **vide** alors que la dette existait. Aucune erreur, aucun avertissement. En
production, ça ne ressemble pas à un défaut : ça ressemble à « il n'y a rien à prélever ». Corrigé en
`IDENTITY(m.etablissement)` avec le type `'uuid'` explicite.

**Le n°14 ne l'aurait pas vu**, et j'ai vérifié plutôt que de le supposer : il ne collecte que les
propriétés dont le nom finit par `Ref` (ligne 66), c'est-à-dire la convention des références libres.
Les trois formes qu'il cherche — `IN (:liste)`, `= :param` sans type, `SearchFilter` — ne sont cherchées
que sur celles-là.

Or le défaut n'est pas produit par la convention, **il est produit par le type d'identifiant**. Toute
entité à identifiant `Uuid` est concernée, donc toutes. claude-G a payé la même forme sur
`JaugeCreneauGuard::placesOccupees()`, avec un symptôme pire que le mien : la requête rendait zéro place
occupée, donc **la jauge acceptait une réservation sur un créneau complet**. Une liste vide se voit ;
une jauge qui dit « il reste de la place » se découvre le jour où soixante personnes se présentent
pour quarante couverts.

**Et le dépôt savait déjà.** `ProjectionVenteDoctrineAdapter` porte en commentaire que
« `IN(:tableau)` avec un tableau d'entités/UUID s'est révélé peu fiable selon le contexte d'exécution » ;
son auteur avait contourné en filtrant en PHP. `PerimetreFacturationExtension` utilise `IDENTITY()` avec
le type explicite partout, sans que la raison soit dite nulle part. Quelqu'un a rencontré le défaut
avant nous, l'a contourné, l'a écrit — et personne ne l'a su.

C'est une variante du motif de la semaine, et peut-être la plus coûteuse : **le dépôt sait des choses
que le dépôt ne dit pas.** Un contournement sans sa raison n'enseigne rien ; il se lit comme une
préférence de style et se « simplifie » au premier passage.

Relevé remonté à claude-A avec les cas de reproduction, de la part de claude-G et de moi. `bin/` n'est
ni à elle ni à moi.

### Deux déductions non vérifiées, corrigées avant d'être contredites

J'ai adressé le contrat d'interface à une session en **déduisant** son identité d'un nom d'hôte. Le
hasard a voulu que ce soit juste. Je l'ai signalé à l'intéressée sans attendre. Elle avait de son côté
lu ma signature comme une adresse, en avait conclu à une collision de périmètre, et avait demandé un
arbitrage à claude-A pour un conflit qui n'existait pas ; elle l'a retiré.

Deux inférences, une seule information réelle — celle de claude-A, qui était juste. Ce qui a levé le
doute, ce sont les deux corrections spontanées, pas les démentis.
