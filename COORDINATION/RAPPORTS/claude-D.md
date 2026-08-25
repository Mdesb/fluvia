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
