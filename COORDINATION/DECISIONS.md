# DECISIONS — journal append-only des décisions transverses

N'édite jamais une entrée passée ; ajoute une nouvelle entrée en bas. Format : date · décision · raison.

---

### 2026-08-19 · D1 — Convergence en socle unique
Le core **Symfony 7 / API Platform / Doctrine / MariaDB** de la billetterie devient **LA coquille**
de la plateforme. Finance, Smart Flow, Revenue Recovery et, progressivement, les domaines OFS et
Vespera deviennent des **modules activables**.
**Raison :** c'est le projet le plus « plateforme » (capacités activables + RBAC module×action +
API-first déjà en place). Retrofit **incrémental**, jamais big-bang.

### 2026-08-19 · D2 — Contract-first
Aucun module ne se construit avant que son **manifeste** et ses **événements** soient posés dans
`CONTRACT/`. Les modules communiquent **par événements**, jamais par appel direct module→module.
**Raison :** c'est ce qui rend le travail parallèle (plusieurs Claude) sûr et le cœur agnostique.

### 2026-08-19 · D3 — Invariant n°1 : cloisonnement à périmètre serveur
Le périmètre (tenant/établissement) est **toujours dérivé de la session serveur**, jamais d'un id
fourni par le client. Toute résolution d'entité par id client doit revérifier l'appartenance au
périmètre. Échec **fermé** (403/404), jamais de repli silencieux.
**Raison :** les 3 projets l'ont adopté indépendamment ; 5 violations réelles (IDOR cross-tenant)
viennent d'être trouvées et corrigées en revue de cohérence (Personnel, Stock, Terminal).

### 2026-08-19 · D4 — Suite Finance sur le core billetterie
Factures (client + fournisseur), Comptabilité (plan comptable, FEC), Trésorerie, Notes de frais
se construisent sur le core billetterie (là où vivent déjà `Facturation` + Compta/Régie + `SEPA`).
**OCR** est un **service transverse partagé**, pas un module métier (alimente Factures fourn. + Notes de frais).

### 2026-08-19 · D5 — Anglais pour tout le technique, i18n pour l'affichage
**Tous les identifiants techniques sont en anglais** (standard international) : entités, tables,
colonnes, propriétés, valeurs d'enum, champs d'API/DTO, **codes de permission** (`finance.read`),
**noms d'événements** (`payment.failed`). Les libellés visibles par l'utilisateur ne sont **jamais**
en dur : ce sont des **clés de traduction** résolues par la couche i18n (français par défaut, langues
extensibles). Un **agent de traduction** (IA) auto-remplit les catalogues de langues.
**Raison :** standard de l'industrie + interop propre entre les 3 projets ; sépare le technique
(anglais, stable) de la présentation (traduite).
**Portée / retrofit :** tout nouveau code est en anglais dès maintenant. L'existant billetterie
(français : `Etablissement`, `Facturation`…) sera migré **incrémentalement** vers l'anglais dans le
cadre du retrofit total (renommages + migrations, avec la couche i18n qui garantit que l'UI reste
française pendant toute la transition). Un garde-fou CI vérifiera l'absence d'identifiant non-anglais
dans les nouvelles migrations/entités.

### 2026-08-19 · D6 — L'enveloppe d'événement dérive son tenant du sujet, pas du contexte HTTP
Le champ `tenant.establishmentId` d'un événement est renseigné depuis l'**entité sujet** (l'établissement
de la facture, de la réservation…), **jamais** depuis `ContexteEtablissement`. Ce dernier ne sert que
d'assertion de cohérence.
**Raison :** `ContexteEtablissement` lit l'en-tête HTTP `X-Etablissement`, qui est un **sélecteur** fourni
par le client, pas une preuve d'appartenance — l'autorité est recalculée serveur par
`CalculateurDroits::codesEffectifs()` (filtrage sur les `Affectation`, échec fermé). L'en-tête est aussi
facultatif : absent, il vaut `null`. Le remplir dans l'enveloppe inscrirait au cœur de la plateforme un
périmètre influencé par le client, en contradiction avec D3.

### 2026-08-19 · D7 — Le bus v0 est synchrone in-process
Pas de `symfony/messenger` dans le projet : le bus s'appuie sur l'`EventDispatcher` Symfony déjà utilisé
par 5 modules (`Recouvrement`, `Crm`, `Acces`…). Les abonnés s'exécutent **dans la transaction de
l'émetteur**. Le passage à l'asynchrone (messenger + transport) est un ajout ultérieur, décidé quand un
besoin réel apparaît.
**Raison :** D1 impose un retrofit incrémental. Introduire une file maintenant ajouterait de
l'infrastructure (worker, supervision, rejeu) sans consommateur qui la justifie, et casserait le modèle
transactionnel des 5 émetteurs existants.

### 2026-08-19 · D8 — Toute entité résolue depuis un identifiant client porte son propre contrôle de périmètre
Un `Processor`, un `Provider` ou un contrôleur qui charge une entité à partir d'un identifiant venu de la
requête (corps, query, en-tête) **doit vérifier explicitement l'appartenance au périmètre de la session
serveur**, et refuser en échec fermé (403/404) sinon. Ce contrôle ne peut pas être délégué aux extensions
Doctrine.
**Raison :** le cloisonnement du projet repose aujourd'hui sur des extensions API Platform
(`QueryCollectionExtensionInterface`, `QueryItemExtensionInterface`) qui ne s'exécutent que sur les
opérations de **lecture**. Une opération déclarée `read: false`, ou un Processor qui appelle
`$repository->find($uuidDuCorps)`, sort du filet **en silence** : rien dans le code ne signale que la
protection a été contournée. claude-C l'a confirmé le 19/08 en calibrant le garde-fou C4 — deux cas
exploitables sur des chemins argent (`POST /mouvements-caisse`, rejets SEPA), et 37 fichiers sur 116 sans
contrôle visible. Un mécanisme de sécurité dont l'absence est invisible n'est pas un mécanisme de
sécurité : d'où la règle explicite, et le garde-fou CI qui la rend exécutable.

### 2026-08-20 · D9 — OFS et Vespera sont hors périmètre
Ni absorbés en modules, ni fédérés par API, ni portés. Ce sont des **sociétés et produits tiers** ; leurs
documents ne servent que de source d'inspiration fonctionnelle. **Rectifie D1**, qui annonçait
« domaines OFS/Vespera = modules », et le tableau « autres dépôts » d'OWNERS.
**Raison :** décision du client (20/08). Les documents OFS et Vespera avaient été fournis pour en tirer
des idées de fonctionnalités, et le PLAYBOOK les avait transformés en feuille de route d'absorption —
une roadmap fondée sur un malentendu coûte plus cher qu'une absente.

### 2026-08-20 · D10 — Le tunnel d'acquisition démarre en prélèvement SEPA seul
Pas de carte bancaire au lancement. Le tunnel s'appuie sur le module `Sepa` existant (mandats, remises,
rejets) ; l'encaissement carte sera ajouté quand le volume le justifiera.
**Raison :** le module mandats est déjà construit et testé, là où la carte impose un prestataire, des
webhooks et un tunnel hébergé chez un tiers. On échange un peu de conversion contre un lancement
nettement plus tôt. **Invariant à tenir le jour où la carte arrive : le formulaire de paiement est
hébergé par le prestataire, jamais par nous** — les données de carte ne traversent pas la plateforme.

### 2026-08-20 · D11 — La démo est un bac à sable jetable, mais le paramétrage est repris
Le prospect explore sur des données fictives ; à la souscription, ce qu'il a configuré (offres, tarifs,
horaires) est exporté et rejoué sur son établissement réel, qui démarre propre.
**Raison :** faire de la démo le compte réel supprimerait la friction, mais imposerait de gérer le cycle
de vie de milliers d'établissements fantômes (expiration, purge RGPD des non-convertis). Repartir de
zéro après paiement est l'endroit où l'on perd les clients. La reprise du paramétrage prend le meilleur
des deux, au prix d'un format d'export à définir.

### 2026-08-20 · D12 — L'administration de l'éditeur vit dans la plateforme
L'éditeur est un `Etablissement` comme un autre, avec les modules CRM, Devis, Facturation, SEPA et
Recouvrement activés. Ses prospects, devis, factures et abonnements sont **ses propres données**, dans
**son** établissement. Il n'y a pas de seconde application.
**Raison :** les quatre modules commerciaux existent déjà — les redévelopper ailleurs reviendrait à
maintenir deux fois le même métier. Et l'éditeur devient le premier utilisateur de son produit : il
rencontre ses propres défauts avant ses clients.

**Le point difficile, et sa résolution.** On objecte que l'administration de l'éditeur doit « voir tous
les établissements », ce que D3 interdit par construction. L'objection confond trois besoins distincts :

1. **Facturer** ne demande aucun accès inter-établissement. Les données commerciales appartiennent à
   l'éditeur, D3 s'applique tel quel.
2. **Provisionner** est une écriture **système**, déclenchée par un paiement confirmé — pas une
   navigation humaine. Elle passe par un service dédié, idempotent et journalisé.
3. **Assister un client** est le seul vrai besoin de lecture inter-établissement. Il devient un
   **accès d'assistance** explicite : borné dans le temps, attribué à une personne nommée, tracé à
   l'audit.

Donc **on ne perce pas le cloisonnement** : deux des trois besoins n'en ont jamais eu besoin, et le
troisième devient une fonctionnalité auditée au lieu d'une exception d'architecture. Il n'existe aucun
rôle qui voit tous les établissements par défaut.

Spec : `specs/editeur/spec-editeur.md`.

### 2026-08-20 · D13 — Le moins d'écrans possible : la modale est le défaut
Une action se fait **dans une modale, au-dessus du contexte où l'utilisateur se trouve**. Créer un
écran est l'exception, et l'exception se justifie.

**Raison :** chaque écran supplémentaire est une navigation, une perte de contexte et une occasion
d'abandonner. Un exploitant qui gère une caisse ou une réservation ne veut pas naviguer, il veut agir
et revenir à ce qu'il faisait. La modale garde le contexte visible derrière elle — c'est exactement ce
qui rend un processus court perçu comme simple.

**En pratique**
- Une action depuis une liste (créer, éditer, valider, annuler) ouvre une **modale**. Pas de page de
  détail en lecture seule quand une modale suffit.
- Un processus en plusieurs étapes est **une modale à étapes**, pas N routes.
- Pas de modale au-dessus d'une modale. Si le besoin apparaît, c'est que l'étape méritait un écran.

**Un nouvel écran se justifie par l'une de ces trois raisons, et on l'écrit dans le plan :**
1. **espace de travail durable** — on y reste (caisse, contrôle d'accès, planning) ;
2. **contenu qui ne tient pas** — un tableau large, un comparatif, une édition longue ;
3. **lien partageable ou reprise après interruption** — l'utilisateur doit pouvoir revenir par une URL.

**Exceptions nommées, pour que la règle ne devienne pas un dogme nuisible :**
- Le **tunnel de souscription public** reste en pages : il est parcouru au mobile, doit être repris
  après abandon et partagé par lien. Une modale y perdrait l'utilisateur, pas l'inverse.
- Les **écrans de terminal** (caisse, contrôle d'accès) restent plein écran : ce sont des postes de
  travail, pas des actions.

**Ce qu'une modale doit tenir, sans quoi elle est pire que l'écran qu'elle remplace :** le focus est
piégé puis restitué à la fermeture, `Échap` ferme, et un formulaire long ne doit pas pouvoir être
perdu par un rafraîchissement. Une modale mal faite transforme une simplification en piège.

### 2026-08-21 · D14 — Module de publication sociale : un seul module, deux usages, trois contraintes
Un module `App\Social` permet de rédiger un message et de le publier sur plusieurs réseaux, puis d'en
collecter les statistiques. Il sert **l'éditeur et les clients avec le même code** : l'éditeur étant un
établissement de la plateforme (D12), « l'éditeur publie sur ses réseaux » est ce module activé sur son
propre établissement. Aucun second développement.

**Modèle : un post, N publications.** Un message part vers cinq réseaux ; trois réussissent, un dépasse
son quota, un cinquième échoue sur un jeton expiré. Chaque réseau a donc sa propre ligne, son état, son
identifiant distant et son erreur. Modéliser « un post publié ou non » perdrait l'information exacte
qui compte le jour de l'incident — et c'est aussi cette ligne qui portera les statistiques, donc la
jointure entre ce qu'on a publié et ce que ça a produit.

**Contrainte 1 — l'entité juridique porteuse.** L'application destinée aux **clients** appartient à la
société créée pour ce projet, et à aucune autre. Trois raisons : transférer une application entre
comptes Business est laborieux et se fait mal une fois des clients connectés ; le responsable de
traitement RGPD doit être l'entité qui signe le contrat, sinon le décalage se découvre pendant une
négociation ; et la vérification d'entreprise porte sur des documents légaux. Une application distincte
sous une société existante, réservée à l'usage interne de l'éditeur, est en revanche sans conséquence.

**Contrainte 2 — les plateformes ne conservent pas l'historique.** Les statistiques ne remontent que
sur une fenêtre limitée et leurs définitions changent entre versions d'API. On prend donc des
**instantanés planifiés dès le premier jour**, et on stocke la charge brute **en plus** de la vue
normalisée : sans instantanés, l'historique n'existera pas et sera irrattrapable ; sans charge brute,
une redéfinition de « portée » rendra le passé incomparable.

**Contrainte 3 — ce module rouvre D7.** Appeler cinq API externes, avec quotas, reprises et délais,
dans la transaction d'un utilisateur qui clique sur « Publier », est exactement ce que le bus synchrone
ne permet pas. D7 disait « on ajoutera une file quand un besoin réel apparaîtra » : le voici. La
décision se prend **avant** d'écrire le module, pas pendant.

**Séquencement.** On construit contre des réseaux ouverts (Mastodon, Bluesky) qui n'exigent aucune
autorisation : toute la mécanique — file, reprises, chiffrement et cloisonnement des jetons, collecte
planifiée — est prouvée pendant que les revues applicatives sont en cours. Chaque réseau devient
ensuite un adaptateur enfichable, comme le socle le fait déjà pour le signataire NF525 et l'extracteur
OCR.

**Dépendance à signaler :** publier une image sur Instagram exige une URL publiquement accessible —
donc la GED, qui n'existe pas encore. Le module démarre sans média, ou avec un hébergement temporaire.

**Ce que ça vaut, et pourquoi ce n'est pas une commodité.** Un outil de publication générique dit qu'un
post a fait 4 000 vues. Il ne dira jamais s'il a rempli le cours d'aquagym du samedi. La plateforme a
les réservations, la billetterie et le Reporting dans la même base : croiser la publication et la
fréquentation est ce qu'aucun outil du marché ne peut faire, faute d'avoir les ventes. C'est là qu'est
la valeur, pas dans la publication elle-même.

### 2026-08-21 · D7-bis — L'asynchrone arrive, en complément du bus synchrone et non à sa place
`symfony/messenger` est ajouté, avec le **transport Doctrine**. Un worker unique, supervisé par
systemd. **D7 n'est pas annulée** : le bus d'événements reste synchrone et in-process.

**La ligne de partage, et c'est tout l'objet de cette décision.**
- Le **bus d'événements** transporte des **faits**, dans la transaction de l'émetteur. C'est ce qui
  garantit qu'un fait et ses conséquences internes sont cohérents : `payment.failed` et la suspension
  qu'il déclenche réussissent ou échouent ensemble. Rien ne change.
- **Messenger** transporte du **travail sortant** : appeler cinq API sociales avec quotas et reprises,
  provisionner un établissement après confirmation d'un paiement, envoyer un e-mail. Ce travail est
  lent, faillible pour des raisons extérieures, et n'a aucune raison de tenir la transaction d'un
  utilisateur qui vient de cliquer.

Un abonné au bus **peut** publier un message asynchrone. C'est le pont, et c'est le seul.

**Pourquoi le transport Doctrine et pas Redis ou AMQP.** Aucun transport n'est disponible : le Redis de
la machine appartient à Vespera, hors périmètre depuis D9. Le transport Doctrine n'ajoute **aucun
composant d'infrastructure** — la base existe déjà, elle est sauvegardée, et la file vit dedans. Sur un
VPS unique sans équipe d'exploitation, un composant de moins à surveiller vaut mieux qu'un gain de
débit dont personne n'a besoin. Le jour où le volume le justifiera, changer de transport est une ligne
de configuration : c'est précisément ce que messenger abstrait.

**Le piège à ne pas manquer : publier après le commit.** Un message envoyé à l'intérieur d'une
transaction qui déroule ensuite met en file du travail pour un fait qui n'a jamais eu lieu — on
provisionne un établissement pour un paiement annulé. Les messages sont donc dépêchés **après le
commit**, jamais pendant.

**Le coût, qu'on assume plutôt que de le découvrir.** L'asynchrone déplace les échecs hors du champ de
vision de l'utilisateur. Une erreur synchrone se voit ; un message qui échoue en silence dans une file
que personne ne regarde, non. Deux exigences en découlent, non négociables : un **transport d'échec**
distinct, et un moyen de le consulter. Un worker est aussi un processus de plus à superviser et à
redémarrer — c'est de la surface d'exploitation nouvelle, et c'est le vrai prix de cette décision.

**Ce que ça débloque :** SOC-2 (publication sociale), ED-3 (provisionnement sur paiement confirmé), et
plus tard toute notification sortante.

### 2026-08-22 · D15 — Un établissement compose des activités ; l'énumération `Metier` disparaît
`App\Fonctionnalite\Enum\Metier` et la table figée `PresetVerticale` sont remplacés par une
**composition d'activités**. Un établissement n'a plus *un* métier : il compose ce qu'il exerce.

**Raison :** un camping a un bar, un restaurant, des hébergements, une piscine et un bowling. Une
station de ski a des forfaits, de la location, une école et de la restauration. Ce ne sont pas des
verticales, ce sont des **compositions** — et l'énumération les rend inexprimables. Accessoirement,
ajouter un métier modifiait jusqu'ici le noyau (`App\Fonctionnalite`), donc chaque nouveau client
exotique était une migration et un conflit de fusion potentiel.

**Neuf types d'activité couvrent l'ensemble des métiers évoqués** — billetterie/entrée, réservation de
ressource, abonnement, location de matériel, vente de produits, cours/encadrement, prestation sur
rendez-vous, hébergement, restauration. Vingt métiers deviennent des combinaisons de neuf briques.
Sept existent déjà ; **hébergement et restauration sont les deux seuls vrais manques**.

**Une verticale devient un paquet rédigé, pas un module développé** : manifeste, activités composées,
données de départ à identifiants stables (avec non-écrasement à la mise à jour, faute de quoi une
montée de version écrase les tarifs du client), et **clés de vocabulaire** — un « créneau » est un
*rendez-vous* chez le coiffeur et une *réservation de terrain* au padel. Même concept, mots
différents. Un module de code ne subsiste que si la verticale apporte une règle réellement nouvelle.

**Ce qui est repris d'Odoo, et ce qui ne l'est pas.** On reprend les **modules de colle à installation
automatique** (le code qui n'a de sens que si deux modules coexistent a enfin un domicile), les
**identifiants externes stables** et le drapeau de non-écrasement, et l'idée de **points d'extension
d'interface**. On ne reprend **pas** l'héritage de modèle en place (`_inherit`) : qu'un module puisse
redéfinir silencieusement le modèle et les méthodes d'un autre est contraire à D2 et à la propriété
disjointe des dossiers — sur cet axe notre conception est meilleure, l'adopter serait une régression.

### 2026-08-22 · D16 — Réserver est un acte unique, paramétré ; seules les conséquences diffèrent
Une table de 8, une chambre de 4, un court de padel, un cours d'aquagym et une séance de massage sont
**le même acte de réservation**. Ce qui varie, c'est l'unité de temps, le mode de capacité, et surtout
ce qui se produit **après** — une addition, un séjour, un accès, une feuille d'émargement. Les
conséquences passent par le bus d'événements ; l'acte reste unique.

Le modèle existant en exprime déjà l'essentiel : `Ressource` porte `capacitePropre`, `partageable`,
`codeType`, `ressourceMere` et `competenceRequise` ; `Creneau` porte début, fin, `capacite` et
récurrence. **Trois manques précis**, et rien de plus :

1. **Une réservation consomme N unités, pas 1.** Une table de 8 consomme huit couverts sur les
   soixante du service. Aujourd'hui les participants sont des lignes individuelles — juste pour un
   cours, faux pour des couverts.
2. **On réserve un type, l'instance est affectée plus tard.** Personne ne réserve « la chambre 214 » :
   on réserve *une chambre double*. Le `codeType` existe mais n'est pas une unité réservable.
3. **Deux niveaux de capacité imbriqués.** Une table libre ne suffit pas si le service n'a plus de
   couverts ; un moniteur libre ne suffit pas si l'école est complète.

L'hébergement ajoute par-dessus la sémantique de la **nuitée** (tarif par nuit, calendrier
d'occupation, chambre libérée le matin et relouable le soir) — une couche mince, pas un module
parallèle.

**Et le concept qui rend l'ensemble utilisable : le séjour.** Un client, une période, et tout ce qu'il
consomme sur place — emplacement, entrées piscine, additions du bar, parties de bowling — sur un même
compte, réglé une fois. C'est ce qui transforme « six modules » en « un logiciel », et c'est la
réponse à l'exigence « simple et hyper clair ». Le porte-monnaie et le contrôle d'accès existent déjà.

### 2026-08-22 · D17 — Un pilote d'accès déclare ses capacités ; la plateforme ne promet que ce qu'il sait tenir
`PiloteAcces` expose quatre opérations — `ouvrir`, `recevoirEvenement`, `heartbeat`,
`pousserListeRevocation` — et le port **suppose que tout pilote sait les quatre**. Les trois
adaptateurs existants (Itbox, SmartAccess, Simulateur) les implémentent d'ailleurs à l'identique.
C'est vrai pour la topologie que nous connaissons, et faux pour toutes les autres.

**Le mode de défaillance que cela crée est le pire possible en contrôle d'accès :** la plateforme
appelle `pousserListeRevocation`, l'adaptateur ne sait pas le faire, et *personne ne l'apprend*. On
croit avoir révoqué un accès. La porte s'ouvre quand même. Aucune trace, aucune alerte, et la
découverte se fait sur incident.

**Décision : chaque pilote déclare ses capacités**, sur le modèle exact de `ModuleManifest` — même
motif, même étiquette de service, même test de catalogue. Quatre axes, parce que ce sont les quatre
qui changent d'une topologie à l'autre :

1. **Où se prend la décision** — au serveur, dans l'unité de traitement, ou sur la carte elle-même.
2. **La révocation** — immédiate, différée à la prochaine synchronisation, ou impossible.
3. **L'encodage** — le pilote sait-il écrire une autorisation sur un médium, ou seulement lire un
   identifiant.
4. **Les passages** — remontés en temps réel, ou seulement relus à la synchronisation.

**Conséquence directe et non négociable :** une opération non déclarée n'est pas silencieusement
ignorée, elle **échoue explicitement**. Et l'exploitant doit voir dans l'interface que *ce site-là* ne
sait pas révoquer immédiatement — c'est une promesse commerciale, pas un détail technique.

**Pourquoi maintenant, avant tout nouveau matériel.** Les topologies que nous ne couvrons pas encore
arrivent toutes par le même chemin : lecteur IP qui est sa propre unité de traitement, serrure
autonome sur pile, donnée portée par la carte avec point de mise à jour, téléphone servant de mule en
Bluetooth, et accès sans support du tout (plaque d'immatriculation à la barrière, QR lu par caméra).
Avec la déclaration de capacités, chacune devient **un adaptateur qui déclare autre chose**. Sans
elle, chacune est une refonte du port.

**Un second port est ouvert : l'encodage.** Appairer associe un identifiant à un droit ; encoder
**écrit le droit sur le médium**. Deux sémantiques différentes, deux ports différents — les mélanger
reproduirait exactement le défaut que cette décision corrige.

**Ce qui n'est pas décidé ici, et ne doit pas l'être en code :** devenir nous-mêmes l'unité de
traitement en pilotant des lecteurs OSDP. C'est un autre métier — vendre du matériel, tenir un site
sans internet, et répondre d'une porte qui ne s'ouvre pas, sachant que le déverrouillage d'urgence
relève de la réglementation incendie et reste mécanique. La décision d'aujourd'hui **préserve cette
option sans l'engager**. La seule mesure à prendre dès maintenant est contractuelle et gratuite :
**exiger des lecteurs OSDP plutôt que Wiegand** dans les cahiers des charges, Wiegand étant en clair,
unidirectionnel et rejouable.

### 2026-08-22 · D18 — GED : le lien public est un jeton au porteur, et les fichiers suivent la sauvegarde
Arbitrage des points laissés ouverts par la spec DMS-0 (`specs/dms/spec-dms.md`). Deux d'entre eux
engagent la plateforme au-delà du module et sont donc consignés ici ; le reste est répondu à claude-B.

**1. `dms.manage_public_link` devient un rôle dédié, jamais une permission parmi d'autres.**
C'est la **seule capacité de toute la plateforme qui fabrique un accès non authentifié**. Partout
ailleurs, le périmètre serveur borne ce qu'un utilisateur voit ; ici on émet un jeton au porteur qui
contourne l'authentification par construction — même famille que la carte cadeau et le badge d'accès.
Un rôle générique d'administration ne doit **jamais** l'obtenir par héritage ou par commodité : elle
s'attribue explicitement, à une personne nommée. L'intuition de claude-B était la bonne.

**2. Le stockage sur système de fichiers est validé — à une condition qui n'était pas dans la spec.**
Le port `Storage` avec un adaptateur local, hors racine web, sur un VPS unique : approuvé, la
dépendance S3 n'a aucune justification aujourd'hui. **Mais des fichiers sur disque ne sont pas dans la
sauvegarde de la base.** Une restauration ramènerait alors des `Document` dont le contenu a disparu —
une GED qui rend des références mortes est pire qu'une absence de GED. La sauvegarde des fichiers et
celle de la base doivent donc être **cohérentes entre elles**, et cette contrainte est une condition
de mise en production du module, pas une tâche d'exploitation à voir plus tard.

**3. Chiffrement au repos : oui, clé depuis l'environnement, sans valeur par défaut.** Quatrième
occurrence du même motif après les trois chaînes NF525 — le garde-fou des secrets couvre déjà le cas.
À dire honnêtement, en revanche : chiffrer sur la machine qui détient la clé protège contre
l'exfiltration d'un disque ou d'une sauvegarde, **pas** contre la compromission de l'application. On
ne vend pas cette mesure pour ce qu'elle n'est pas.

**4. Durée par défaut des liens publics ramenée à 7 jours, plafond 30.** L'usage réel est d'envoyer un
devis ou une facture à un client, et sept jours y suffisent. Trente jours par défaut, c'est un mois
d'exposition non authentifiée pour une commodité que presque personne n'utilise.

### 2026-08-22 · D19 — Ce qui dépend d'un tiers est consigné, jamais attendu
**Aucun développement ne s'arrête en attendant une vérification externe, un accès API, un agrément ou
un contrat.** Le besoin est inscrit au registre `COORDINATION/BLOQUEURS-EXTERNES.md`, la tâche prend le
statut `EXTERNE`, et l'instance passe **immédiatement** à un autre module. On ne planifie pas autour
d'une date qu'on ne maîtrise pas.

**Distinction à ne jamais confondre**, faute de quoi cette décision devient une excuse :

- **Bloqueur externe** — un tiers doit agir et nous ne pouvons rien faire d'autre qu'attendre
  (vérification d'entreprise, agrément de programme, contrat bancaire, matériel d'un fournisseur).
  Statut `EXTERNE`. Il **sort des points horaires** et vit au registre, parce que le répéter chaque
  heure ne le fait pas avancer d'une minute et noie ce qui bouge vraiment.
- **Bloqueur interne** — nous *pourrions* le résoudre, nous ne l'avons pas encore fait (C9 et le
  mécanisme réel de découverte des ressources API en est l'exemple). Statut `BLOCKED`. Il **reste**
  dans les points horaires, parce que c'est une dette et qu'elle doit démanger.

**Le motif technique qui rend la règle applicable est déjà notre pratique** : un port, un adaptateur
factice, et l'intégration réelle repoussée. `CollecteurSepaInterface` avec son
`CollecteurSepaStubAdapter`, `PiloteAcces` avec son `SimulateurAccesAdapter`, `SOC-2` qui vise
Mastodon et Bluesky — des réseaux ouverts, sans vérification d'entreprise — plutôt que d'attendre
Meta. Le contrat se développe et se teste **entièrement** sans le tiers ; le jour où l'accès arrive,
il ne reste qu'un adaptateur à écrire.

**Conséquence sur la conception :** quand une fonctionnalité dépend d'un tiers, le premier livrable
n'est jamais l'intégration — c'est le port et son adaptateur factice. Ce qui se teste sans le tiers
doit être écrit avant lui, pas après.

### 2026-08-22 · D20 — Aucune assertion d'horloge dans la suite fonctionnelle
Une suite complète de 1 149 tests a échoué une fois, puis repassé au vert **sur exactement le même
code**. Ce n'était pas une régression : c'était une assertion de durée. Trois existaient — 1 s sur deux
routes d'accès, 50 ms sur un adaptateur OCR.

**Le défaut de conception :** un `assertLessThan` sur un chronomètre, exécuté au milieu d'une suite qui
tourne deux heures sur un VPS partagé sous Docker, **mesure la charge de la machine, pas le code**. Il
passe en module isolé, où la machine est au repos, et saute en suite complète. Le symptôme est le pire
qui soit : un échec qui ne se reproduit pas, donc qu'on finit par ignorer — et le jour où la suite
signale une vraie régression, plus personne ne la croit.

**Règle :** la suite fonctionnelle ne contient pas d'assertion de temps écoulé. Ce qui subsiste est un
**seuil de garde** volontairement large (5 s là où l'exigence est à 1 s), dont le seul rôle est
d'attraper une régression pathologique — un N+1, un appel bloquant — sans dépendre du voisin.

**Ce qui n'est pas abandonné :** les exigences de performance elles-mêmes. US-L3-03 et RG-ACC-01 restent
entières, mais elles se vérifient **sur matériel représentatif, à chaud, sur plusieurs échantillons et
en percentile** — pas sur un tir unique au milieu d'une suite. C21 ouvre ce chantier.

**Et la leçon de méthode, qui a coûté une heure :** en cherchant l'échec, j'avais filtré la sortie des
tests pour retirer le bruit applicatif — le filtre a emporté le bloc d'échec avec lui, et j'ai perdu le
nom du test. **On ne filtre jamais la sortie d'une suite dont on cherche l'échec.**

### 2026-08-22 · D21 — Une amélioration de garde-fou se fusionne avant tout le reste
Le hook `pre-receive` analyse l'arbre poussé, mais exécute le `bin/` que contient **`main`**. Une
branche qui améliore un garde-fou est donc jugée par l'ancienne version : **tant qu'elle n'est pas
fusionnée, elle ne protège personne**. claude-C l'a établi par la mesure, pas par le raisonnement — même
fichier de test, deux verdicts opposés selon la version exécutée.

**Ce que ça inverse.** Pour du code applicatif, l'ordre normal est : on développe sur une branche, on
valide, on fusionne — la valeur existe dès la branche et la fusion la publie. Pour un outil de contrôle,
la valeur **n'existe qu'après** la fusion. Une amélioration de garde-fou non fusionnée n'est pas « du
travail en attente d'intégration », c'est **du travail sans effet**.

**Règle :** un lot qui améliore un garde-fou, un hook ou le harnais de test passe **avant** un lot
fonctionnel dans la file d'intégration, à qualité de revue égale. Ce n'est pas une préférence pour
l'outillage, c'est la conséquence de son mode de fonctionnement.

**Et l'aveu qui va avec.** J'ai laissé dix commits de claude-C attendre douze heures, dont ceux qui
ajoutaient précisément la règle attrapant la forme des cinq IDOR du projet. Pendant ces douze heures,
deux IDOR ont été corrigés à la main — alors que la détection automatique dormait sur une branche.
Vérifié après fusion, sur un clone jetable : le garde-fou de `main` **refuse** désormais un Processor
qui résout une entité depuis le corps sans contrôle (code de sortie 1), et son message cite l'IDOR
d'appairage en exemple. C'était vrai avant la fusion aussi — sur la branche de C, où ça ne servait à
personne.

### 2026-08-23 · D22 — Revenue Recovery et Smart Flow passent en tête ; leurs déclencheurs d'abord
Priorité donnée par Maxime. Les deux modules étaient au **point 5** de l'ordre conseillé du PLAYBOOK,
derrière le bus d'événements, les services transverses, la suite Finance et les garde-fous — tous
livrés depuis. Leur tour est donc venu sans qu'aucune séquence ne soit forcée.

**Le constat qui commande la façon de s'y prendre.** Ces deux modules ne font rien par eux-mêmes : ils
**réagissent à des événements**. Le catalogue leur en attribue quatorze. Vérification faite dans le
code : **deux existent**, `payment.failed` et `payment.incident_reopened`, et encore, uniquement
republiés par le pont d'événements historiques. Les douze autres — panier abandonné, facture échue,
devis expiré, client inactif, réservation annulée, no-show, passage enregistré — **ne sont émis nulle
part**.

**Conséquence : construire les modules avant leurs déclencheurs produirait deux coquilles inertes.**
Nous avons déjà ce précédent exact, à plus petite échelle, avec `ProjectionAccesReservation` : une
projection écrite, documentée, testée… et sans effet, parce que rien ne l'alimentait. Le no-op a
survécu des semaines. On ne le refait pas à l'échelle d'un module.

**Donc l'ordre est : émettre, puis réagir.** Les tâches RR-1 et SF-1 (émission des événements
manquants depuis les modules qui les produisent) sont des **préalables**, pas des dépendances
optionnelles. Elles traversent Boutique, Facturation, Réservation, CRM et Devis — donc l'intégrateur.

**Revenue Recovery ne part pas de zéro, et il faut le dire.** Le module `Recouvrement` implémente déjà
le cœur du dunning : `PolitiqueRecouvrement` (calendrier de représentation, nombre maximal, moment du
refus d'accès, blocage après N échecs), `MoteurRecouvrementHandler`, `IncidentImpaye`,
`RepresentationSepa`. Ce qui manque n'est pas le moteur de relance — c'est **l'élargissement des
déclencheurs** au-delà du seul impayé SEPA : panier abandonné, devis expiré, client inactif, no-show.
La première question de la spec est donc « étend-on `Recouvrement` ou crée-t-on `RevenueRecovery` ? »,
et non « comment relancer un client ? ».

**Smart Flow, lui, part vraiment de zéro** — retards, créneaux libérés, liste d'attente, affluence.
Mais il se branche sur `Reservation`, qui est mature et dont les événements manquants sont les plus
simples à émettre.

### 2026-08-23 · D23 — La carte multi-entrées : recharger sans changer de support, consulter sans consommer
Demande de Maxime. Vérification faite dans le code avant toute conception : **la moitié du chemin
existe déjà et fonctionne**.

**Ce qui marche aujourd'hui.** Vendre un produit-carte alimente `BilletSupport.nbCompostages` depuis
`CarteMultiEntrees.stockCompostagesInitial` (`ValiderVenteService`). La projection construit un
`DroitAcces` de type `CarteQuota` portant `creditRestant`. Chaque passage le décrémente
(`ValidationPassageHandler`). Le solde est déjà calculé et renvoyé au terminal
(`AffichagePorteurResolver`, `SnapshotTerminalProvider` → `compostagesRestants`). L'appairage
support ↔ droit existe, avec un seul appairage actif par support.

**Quatre manques, tous vérifiés.**

**1. Aucune recharge d'entrées n'existe.** « Recharge » n'existe que pour le porte-monnaie
(`PmvRechargeHandler`). Pire : `PassageIngestionProcessor` renvoie déjà
`propositionRecharge: ['caisse','borne','app']` quand le crédit est épuisé — **trois canaux promis à
l'interface pour une opération qui n'a aucun point d'entrée**. C'est la même famille de promesse
creuse que le no-op de `ProjectionAccesReservation`.

**2. Consulter un solde le consomme.** Le seul moyen de connaître le crédit restant est de tenter un
passage — qui décrémente. Or la demande la plus fréquente en caisse est précisément « combien me
reste-t-il ? ». Il faut une route de **consultation en lecture seule**, distincte du passage.

**3. Un droit d'accès n'est rattaché à aucun client.** `DroitAcces` porte `billetSupportRef`,
`produitRef`, `reservationRef` — des UUID libres, et **aucune relation vers `Crm`** (vérifié : zéro
occurrence de `DroitAcces` dans `src/Crm`). « Afficher le solde avec la fiche client » est donc
aujourd'hui structurellement impossible : c'est le maillon à créer avant tout le reste.

**4. Une carte de dix réservations n'est pas possible.** `TypeDroitAcces::Booking` existe (ACC-3) mais
`ProjectionAccesReservationHandler` pose explicitement `setCreditRestant(null)`. Le mécanisme de
décompte est pourtant identique — c'est un paramètre à ouvrir, pas une mécanique à écrire.

**Décisions de conception.**

**La recharge incrémente le droit existant ; elle n'en crée jamais un second.** Ce n'est pas un détail
d'implémentation, c'est ce qui garantit l'exigence « pas de changement de support » : un support n'a
qu'un appairage actif, donc créer un nouveau droit imposerait de révoquer l'appairage et d'en refaire
un — c'est-à-dire, pour le client, **une nouvelle carte physique**. Incrémenter préserve la carte.

**Toute recharge incrémente `Support.versionMaj`.** C'est cette version qui pilote le delta du
snapshot terminal : sans elle, un lecteur hors ligne continuerait de refuser une carte qu'on vient de
recharger à la caisse.

**Le scan de consultation est une opération distincte, jamais un passage.** Même identifiant lu, même
lecteur possible, mais une route qui ne décrémente rien et ne journalise pas un franchissement.

**L'écran est une modale, pas une page (D13).** Scan → modale portant le solde, l'identité du porteur
et **deux boutons d'ajout rapide** : recharger le forfait courant, ou ajouter des entrées à l'unité.
L'agent de caisse ne doit pas naviguer pour répondre à « combien me reste-t-il ? ».

### 2026-08-23 · D24 — Carte de séances nominative : un quota de stock, et un no-show qui ne se facture pas
Complément de D23, demandé par Maxime : cartes de séances liées à un planning et à une personne
(salon de massage), et traitement du no-show sur une prestation **déjà payée**.

**1. La validité après recharge devient un paramètre de la recharge**, comme demandé — pas une règle
globale. Deux exploitants du même logiciel n'ont pas la même politique commerciale, et le même
exploitant peut vouloir prolonger sur une offre d'appel et pas sur une autre.

**2. Le quota qui existe n'est pas celui qu'il nous faut, et il ne faut pas les confondre.**
`QuotaFormuleResolver` et `SimulateurQuota` implémentent déjà un quota **périodique** : « deux
aquagym par semaine incluses dans l'abonnement », semaine calendaire, **sans report** (RG-M1-12). Il
est résolu à la réservation dans `ReserverProcessor`.

La carte de dix séances est un quota de **stock** : il ne se recharge pas au calendrier, il s'épuise.
Un même client peut parfaitement porter les deux — un abonnement avec deux séances hebdomadaires
incluses, *et* une carte de dix massages achetée à part. Les fusionner produirait des décomptes faux
dans les deux sens. Ce sont deux notions distinctes qui partagent le même point de consommation.

**3. Nominatif : la structure existe.** `Reservation` porte un `organisateur` (`Beneficiaire`) et une
collection de participants. Une carte de séances nominative se rattache donc à un bénéficiaire
identifié — contrairement à la carte d'entrées piscine, qui reste volontiers au porteur. C'est
pourquoi le rattachement du droit à un porteur (CQ-0) est **facultatif au niveau du modèle**, mais
**obligatoire pour ce type de carte**.

**4. Le point qui bloque réellement : le no-show d'une prestation déjà payée est inexprimable.**
`ModeFacturationNoShow` propose quatre issues — vente différée, débit du porte-monnaie, prélèvement,
facture à encaisser. **Les quatre répondent à la question « combien facture-t-on ? ».** Or sur une
carte de dix séances, le client a déjà payé : il n'y a rien à facturer. La vraie question commerciale
est ailleurs, et le modèle actuel ne sait pas la poser :

> La séance manquée est-elle **décomptée** du solde, ou **restituée** au client ?

**Décision : le no-show gagne une seconde dimension, indépendante de la facturation.** `RegleAnnulation`
porte déjà la portée (établissement, type de ressource, ressource, **activité**), le délai franc, le
mode de montant et les exonérations. On lui ajoute l'**issue sur le crédit** :

- **décompté** — la séance est perdue, c'est la politique stricte du praticien dont l'agenda est rare ;
- **restitué** — le crédit revient au solde, le client reprend rendez-vous librement ;
- **restitué avec report proposé** — le crédit revient *et* un nouveau créneau est proposé, ce qui
  transforme un incident en réengagement.

Ces trois issues se paramètrent **aux quatre portées existantes**, ce qui permet à un salon de massage
d'être strict là où une piscine sera indulgente, dans le même établissement.

**Pourquoi une dimension séparée et non une cinquième valeur de `ModeFacturationNoShow`.** Parce que
les deux questions sont orthogonales : une séance peut être décomptée *et* facturée (carte épuisée,
créneau réservé quand même), ou restituée *et* non facturée. Les mélanger dans une seule énumération
produirait le produit cartésien des cas, et l'un des deux axes finirait par être oublié — c'est
exactement ce qui s'est produit avec les pilotes d'accès avant D17.

### 2026-08-23 · D25 — On pousse au moins une fois par heure, même incomplet
Règle de Maxime. Chaque instance pousse sur **sa propre branche** au minimum une fois par heure, y
compris un travail en cours qui ne compile pas ou dont les tests ne passent pas encore.

**Rien n'est mis en danger :** une branche n'entre dans `main` que par une fusion de l'intégrateur,
après revue et suite verte. Un commit intermédiaire ne casse donc rien, et le hook `pre-receive`
continue de refuser ce qui doit l'être.

**Raison :** l'absence de poussée était devenue ma seule mesure d'activité, et elle ne mesure rien.
Vérifié le 23/08 : aucune instance ne s'exécute sur le VPS — les worktrees officiels sont figés
(celui de claude-B date du 19/08), les lanceurs n'ont pas servi, aucun processus n'y tourne. Chacun
travaille ailleurs et pousse par SSH. J'ai donc écrit « X heures de silence » dans une douzaine de
rapports en laissant entendre une inactivité que je n'avais **aucun moyen d'observer**.

**Convention :** préfixer le sujet d'un commit intermédiaire par `WIP :`. L'intégrateur ne fusionne
jamais un `WIP :` — il attend le commit qui le remplace ou le complète.

### 2026-08-23 · D26 — Une recharge prolonge la validité, et c'est configurable
Décision de Maxime, complément de D23. La date de validité d'une carte multi-entrées est **prolongée**
par une recharge. C'est le **défaut livré** ; le comportement reste une **option du produit-carte**,
pour l'exploitant qui préfère que la validité d'origine tienne.

**Prolongée à partir de quelle date — l'ambiguïté qu'il faut lever ici plutôt qu'à l'implémentation.**
Retenu : **une période complète à compter de la recharge**, et non un ajout à l'échéance existante.
C'est la lecture qu'un client comprend au comptoir — « vous rechargez, vous repartez pour un an » — et
c'est celle qui se dit en une phrase.

**Le risque assumé, écrit pour qu'il ne soit pas découvert plus tard :** cette règle permet de
recharger **une seule entrée** pour repartir sur une période entière. Sur une carte à validité longue,
c'est ouvert au grignotage. Deux garde-fous sont possibles si le cas se présente — un minimum de
recharge pour déclencher la prolongation, ou un plafond de prolongations cumulées — et **aucun n'est
implémenté pour l'instant**. On les ajoutera sur constat, pas par précaution.

### 2026-08-23 · D27 — Un no-show sur séance prépayée restitue le crédit et propose un report
Décision de Maxime, complément de D24. Des trois issues possibles sur le crédit — décomptée,
restituée, restituée avec report proposé — la valeur **livrée par défaut** est **restituée avec
report**. Le comportement reste paramétrable aux quatre portées de `RegleAnnulation`, dont l'activité.

**C'est le défaut le plus généreux des trois, et c'est un choix assumé** : un client qui ne vient pas
ne perd rien et se voit proposer un autre créneau. Ce qui protège le praticien dont l'agenda est rare,
c'est le **paramétrage par activité** — un salon de massage peut basculer sur « décomptée » là où la
piscine du même établissement reste indulgente. La générosité est le défaut, pas la règle.

**La conséquence qu'il faut voir venir : ce défaut s'appuie sur un module qui n'existe pas.** D24 pose
que « restituée avec report » **émet un événement** plutôt que d'ouvrir un écran — c'est Smart Flow
qui propose le créneau. Or Smart Flow n'est ni écrit ni spécifié (SF-0, SF-2).

**Dégradation choisie, en attendant.** Le crédit est restitué — cette moitié fonctionne dès CQ-5 — et
l'événement est publié. Aucun créneau n'est proposé tant que SF-2 n'existe pas. Ce n'est donc pas
cassé, c'est incomplet, et la différence doit être visible : **l'exploitant ne doit pas voir promis à
son client un report que personne ne lui enverra**. Tant que Smart Flow n'est pas livré, l'interface
annonce la restitution du crédit, rien de plus.

**Ce que ça change dans les priorités.** Smart Flow cesse d'être un module « à faire un jour » : il
porte désormais la moitié du comportement par défaut de la plateforme sur le no-show. C'est le
premier cas d'usage concret de SF-2, et il rend la question posée à Maxime — quel usage de Smart Flow
compte en premier — largement tranchée par les faits.

### 2026-08-24 · D28 — Le hook se réinstalle à chaque fusion, et il doit le vérifier lui-même
Le hook `pre-receive` existe en deux exemplaires : **versionné** dans `hooks/` et **installé** sur le
dépôt nu. J'ai fusionné chaque mise à jour du premier sans jamais réinstaller le second. Résultat
mesuré par claude-C le 24/08 : **quatre garde-fous sur sept ne s'exécutaient pas à la poussée**, alors
que je les rapportais comme actifs depuis deux jours.

**Ce qui était vrai et ce qui ne l'était pas.** Les cliquets lancés à la main donnaient des chiffres
justes — la dette était bien mesurée. Mais **l'application** ne l'était pas : une poussée fautive sur
l'un des quatre contrôles manquants passait sans être refusée. La mesure fonctionnait, la barrière
non.

**C'est D21 appliquée à moi.** J'avais écrit qu'une amélioration de garde-fou ne protège personne tant
qu'elle n'est pas fusionnée. Le corollaire m'a échappé : **elle ne protège personne non plus tant
qu'elle n'est pas installée.** Fusionner un hook donne le sentiment d'avoir agi, et c'est précisément
ce sentiment qui a masqué deux jours d'inaction.

**Règle : toute fusion touchant `hooks/` est suivie de la réinstallation, dans le même geste.**

**Et parce qu'une règle que je dois me rappeler est exactement ce qui vient d'échouer, elle ne suffit
pas.** Le contrôle doit être porté par l'outil : le hook installé compare son propre contenu à la
version présente dans l'arbre poussé, et **avertit bruyamment** s'il est périmé. Un hook capable de
signaler sa propre obsolescence ne dépend plus de la mémoire de l'intégrateur. Demandé à claude-C, à
qui `hooks/` appartient.

**Portée du contrôle : avertir, pas refuser.** Refuser une poussée parce que le hook est périmé
bloquerait justement la poussée qui apporte sa mise à jour. L'avertissement suffit : il est visible de
qui pousse, donc de moi, à chaque intégration.

### 2026-08-24 · D29 — La flotte : une boîte aux lettres par instance, un battement de quinze minutes
Maxime lance **six sessions supplémentaires**. À neuf instances, deux mécanismes qui tenaient à trois
cassent, et il faut les remplacer avant d'ouvrir les vannes.

**1. Plus jamais de fichier partagé en écriture.** `MESSAGES.md` a produit **deux conflits de fusion en
une seule journée** à trois instances. À neuf, ce serait permanent. Chaque instance a donc désormais
deux fichiers qui n'appartiennent qu'à elle :

- `COORDINATION/ORDRES/<id>.md` — **écrit par claude-A seul**, lu par l'instance ;
- `COORDINATION/RAPPORTS/<id>.md` — **écrit par l'instance seule**, lu par claude-A.

Un fichier n'a jamais deux auteurs, donc il n'a jamais de conflit. `MESSAGES.md` reste pour les
annonces générales, écrites par l'intégrateur uniquement.

**2. Le battement de quinze minutes remplace la bonne volonté.** Une instance ne « pense » pas à
regarder si on lui a écrit. Elle exécute une boucle : tirer `main`, lire ses ordres, agir, écrire une
ligne de rapport, pousser. Toutes les quinze à vingt minutes, y compris quand il n'y a rien à dire —
une ligne « rien de neuf » est une information, le silence n'en est pas une. C'est le prolongement de
D25, qui avait déjà retiré au silence sa valeur de signal.

**3. Le périmètre vient de Maxime, jamais de moi.** claude-C a refusé le 24/08 de travailler hors de
son périmètre malgré mon accord explicite, et **il avait raison** : un pair ne peut pas lever une
consigne qu'il n'a pas posée. Le document de lancement est donc signé par Maxime et c'est lui qui fait
autorité. Je répartis le travail **à l'intérieur** des périmètres qu'il fixe ; je ne les déplace pas.

**4. Deux flottes, un seul intégrateur.** La session `claude-D` travaille sur l'**administration de
l'éditeur et le site vitrine** — l'outil de Maxime pour vendre et gérer son activité. Les autres
continuent la **solution destinée aux clients**. Les deux passent par la même branche `main` et le même
intégrateur, parce que D12 pose que l'administration de l'éditeur **vit dans la plateforme** et non à
côté.

**Ce que je m'engage à tenir** : lire les neuf rapports et répondre dans les ordres à chaque point
horaire. Une instance qui signale un blocage doit avoir sa réponse au battement suivant, pas au
lendemain — c'est le défaut que j'ai répété toute la semaine avec claude-B, et il ne passe pas à
l'échelle.

### 2026-08-24 · D30 — Règle zéro : une session ne se ferme jamais
Consigne de Maxime, posée au lancement de la flotte. **Aucune session n'est fermée, archivée,
déconnectée ni mise en veille** — ni en fin de tâche, ni la nuit, ni faute de travail. Une session sans
tâche en prend une autre dans son périmètre ; une session qui attend une réponse pose sa question dans
son rapport et continue.

**C'est la règle zéro parce que toutes les autres la supposent.** Périmètres disjoints, battement de
quinze minutes, boîtes aux lettres sans conflit : rien ne sert si l'instance n'est plus là. Sur la
semaine du 19 au 24 août, à trois instances, **la première cause de retard n'a été ni un bogue ni un
blocage technique — c'est qu'une instance était arrêtée.** Quinze heures un jour, dix-neuf un autre.

**Corollaire assumé :** si une session meurt malgré tout, elle se relance avec le même brief et
**sans rattrapage**. Tout l'état vit dans le dépôt — ordres, rapports, carnet, décisions. C'est
précisément pourquoi rien d'important ne doit jamais vivre dans le fil d'une conversation.

**Cinq règles d'exploitation l'accompagnent, toutes tirées de pannes réellement observées** et non de
précautions théoriques : une seule session par worktree (deux `claude-A` se sont écrasés le 19/08) ;
jamais de `push --force` ni de commit direct sur `main` (qui contourne les hooks) ; le claim se fait
dans son propre rapport et non dans `TASKS.md` (dernier fichier partagé en écriture, déjà en conflit
le 24/08) ; une pile de test par identité et démontée en sortant (vingt-six piles oubliées ont saturé
Docker le 24/08) ; et **jamais la suite complète**, qui coûte deux heures et appartient à
l'intégrateur.

### 2026-08-24 · D31 — Collision SF-0 : la spec canonique est celle du propriétaire du périmètre
Deux specs SF-0 vivent sur `main` : `spec-sf0-smart-flow.md` (415 lignes, claude-B) et
`spec-smart-flow.md` (345 lignes, claude-E). Même périmètre, écrites en parallèle, **et j'ai intégré
les deux sans le voir**. C'est `claude-E` qui l'a découverte en fusionnant `main`, et qui me l'a
remontée plutôt que de choisir seule.

**La cause est mienne et elle est simple** : `TASKS.md` portait encore SF-0 au nom de `claude-B`
quand j'ai donné Smart Flow à `claude-E` dans le document de flotte. Deux sources de vérité sur la
même tâche, et personne n'avait tort.

**Arbitrage : `spec-smart-flow.md` (claude-E) devient la spec canonique.** Non parce qu'elle est
meilleure — les deux sont solides, 22 et 23 règles nommées — mais parce que **le périmètre décide, pas
la qualité**. Smart Flow appartient à `claude-E` par le document de flotte, que Maxime a posé. Choisir
sur la qualité ouvrirait la porte à ce que chacun écrive partout en espérant gagner l'arbitrage.

**Rien du travail de claude-B n'est jeté.** Sa spec porte deux sections que celle de E n'a pas :
**critères d'acceptation** et **cas limites**. Celle de E porte deux sections que B n'a pas :
**écrans-ou-modales (D13)** et **sécurité & cloisonnement** — deux invariants du projet. Les deux
manques sont réels : `claude-E` reprend les critères d'acceptation et les cas limites de B dans la
spec canonique, et cite leur origine.

**La leçon d'organisation, qui vaut plus que l'arbitrage** : à trois instances, une tâche assignée dans
deux endroits se voyait. À neuf, non. **Une tâche n'a qu'un seul propriétaire, et `TASKS.md` en est la
seule source** — le document de flotte fixe les périmètres, le carnet fixe les tâches, et c'est à moi
de les tenir cohérents. Je ne l'ai pas fait, et cela a coûté 415 lignes de travail parallèle.

### 2026-08-24 · D32 — Une migration générée n'est jamais committée telle quelle
`doctrine:migrations:diff` compare les métadonnées Doctrine à la base **entière**. Il ramasse donc
toute la dérive laissée par les autres sessions, et il la présente comme si c'était le travail de
l'auteur. Signalé par `claude-H` à 19:05 : sa migration, générée depuis un worktree à jour de `main`,
proposait `DROP TABLE messenger_messages`, la suppression de l'index FULLTEXT du module Support, la
création de la table de `claude-D`, et une quinzaine de renommages d'index Finance / DMS / Compta / Stay.

**Ce n'est pas nouveau, et c'est ce qui rend la décision nécessaire.** Quatre migrations portent déjà
l'avertissement dans leur en-tête : `Version20260820120931`, `Version20260820151917`,
`Version20260821102107`, `Version20260822090000`. Le piège a été rencontré quatre fois en trois jours,
documenté quatre fois **à l'endroit exact où personne ne le lit** — dans le fichier qu'on écrit après
être tombé dedans — et jamais corrigé là où il s'arrêterait.

**Trois causes structurelles, toutes permanentes :**
1. `messenger_messages` est créée par migration et **aucune entité ne la mappe**. Le diff proposera sa
   suppression à chaque fois, éternellement.
2. Un index `FULLTEXT` **n'est pas exprimable** en mapping ORM (le code du module Support le dit
   lui-même). Le diff proposera sa suppression à chaque fois, éternellement.
3. Une entité écrite sans sa migration — c'est le cas de `subscription_provisioning_request` — apparaît
   dans le diff de **toutes** les autres sessions.

**Règle : le fichier généré est un brouillon, jamais un livrable.** On le relit ligne à ligne, on
garde ce qu'on a soi-même provoqué, on jette le reste. Une migration ne contient que ce que son lot a
introduit. Et **une entité neuve part avec sa migration dans le même lot** — la laisser sans migration
fait porter le coût à toutes les autres sessions.

**Horodatage en heure locale.** Le conteneur PHP tourne en UTC, deux heures derrière. Une migration
générée à 19:00 naît `Version...164417` et se classe **avant** une migration déjà appliquée : elle
s'exécute hors séquence sur toute base existante. On renomme en heure locale.

**Et cela demande un garde-fou, pas une consigne de plus.** Quatre avertissements écrits n'ont rien
empêché ; un contrôle au push l'aurait fait dès le 20/08. Confié à `claude-C`.

#### 2026-08-24 19:55 · D32, suite — deux causes taries, une seule reste irréductible
La décision listait trois causes. `claude-H` en a trouvé une quatrième en régénérant son diff, et elle
est la plus grosse en nombre : **sept index créés par migration et jamais déclarés dans le mapping**
(quatre en DMS, deux en Compta, un en Support). Ils ressortaient en `DROP INDEX` chez tout le monde.

**État réel des causes, après vérification :**

| Cause | État |
|---|---|
| `messenger_messages` non mappée | **tarie** — `schema_filter` dans `doctrine.yaml`, **prouvé** par `claude-H` sur une base repartie de zéro : zéro occurrence dans le diff régénéré |
| Index existants non déclarés | **en cours** — trois posés par `claude-A` (Compta, Support), quatre confiés à `claude-B` (DMS) |
| Entité sans migration | **ouverte** — `subscription_provisioning_request`, confiée à `claude-D` |
| Index `FULLTEXT` du module Support | **irréductible** — non exprimable en mapping ORM |

**Ce que cela change pour le garde-fou demandé à `claude-C` :** une fois les sept index déclarés, le
FULLTEXT devient **le seul cas légitime**. Le garde-fou n'a plus une douzaine d'exceptions à connaître,
mais une. C'est une simplification obtenue depuis un module qui n'était pas le sien, par une session
ouverte depuis une heure.

**Et la leçon de méthode, qui vaut au-delà des migrations :** j'ai posé `schema_filter` en écrivant dans
le commit qu'il n'était **pas prouvé à l'exécution**, plutôt que de le déclarer fonctionnel. C'est la
correction directe des trois défauts trouvés le même soir — garde-fou de topologie, réinstallation des
hooks, démontage des piles — tous des mécanismes déclarés bons sans qu'on regarde ce qu'ils produisent.
**Un mécanisme non vérifié se marque comme tel ; il ne se raconte pas comme vérifié.**

### 2026-08-24 20:45 · D33 — Capacité de second niveau : le créneau visé et les créneaux consommés
`claude-G` a livré ACT-1 point 1 (la quantité consommée) et pose la question du point 3 : comment
exprimer « soixante couverts **sur le service de 20 h** » alors que le second niveau existant
(`Ressource.occupationCourante` vs `capacitePropre`) est **global et aveugle au temps**.

**Sa proposition** : le « service » est un `Creneau` posé sur la ressource **mère**. Une réservation
sur le créneau d'une table consommerait aussi le créneau de la salle qui la couvre dans le temps. Deux
niveaux, deux créneaux, une seule mécanique de jauge. Elle ne l'écrit pas sans feu vert parce que cela
change ce qu'est un `Creneau` — aujourd'hui une réservation en vise exactement un (RG-M5-01).

**Arbitrage : la proposition est retenue, avec une distinction qu'elle n'avait pas posée et qui lève
son objection.** Une réservation garde **un seul créneau visé** — celui que le client a choisi, celui
qui s'affiche, celui dont parle RG-M5-01 — et gagne un ensemble de **créneaux consommés**, résolus à la
réservation et stockés : le créneau visé, plus les créneaux des ressources ancêtres qui le couvrent
dans le temps.

**Pourquoi stocker plutôt que dériver.** On pourrait ne rien stocker et faire remonter le contrôle
l'arbre des ressources à chaque vérification. C'est plus léger et c'est faux : la jauge existante est un
compteur qu'on incrémente sous verrou, et un contrôle dérivé d'un comptage se course avec lui-même dès
deux réservations simultanées sur la même salle. Ce qu'on décrémente doit être ce qu'on a incrémenté.

**Ce que cela préserve** : « on réserve un créneau » reste vrai, l'affichage ne change pas, et
RG-M5-01 n'est pas réinterprétée — elle parle du créneau **visé**. Ce qui est neuf est la consommation,
qui n'était de toute façon écrite nulle part.

### 2026-08-24 20:45 · D33-bis — La quantité ne se déduit pas des participants
`claude-G` demande si une réservation avec trois participants nommés doit consommer au moins trois
unités. **Non, et son refus d'inventer la règle était le bon.** Un cours de trois personnes consomme
trois places ; une piste de bowling réservée par trois personnes nommées consomme **une** piste. La
même réservation, les mêmes participants, deux comptages justes.

**L'ambiguïté n'est pas dans la réservation, elle est dans la ressource** : une place de cours se compte
par personne, une piste et une table se comptent par unité. Tant que la ressource ne **déclare** pas ce
qu'elle mesure, toute valeur par défaut est une devinette — y compris celle d'aujourd'hui, qui vaut 1.

La quantité reste donc **explicite et non devinée**, et la déclaration par la ressource entre dans
ACT-0, où elle a sa place : c'est exactement la composition d'activités de D15.

### 2026-08-24 20:50 · D34 — Remote Control ne s'arrête jamais
Consigne de Maxime, du même ordre que la règle zéro de D30 et pour la même raison.

**Remote Control est ce qui rend une session joignable.** Une session qui ne l'a pas est invisible pour
l'intégrateur : elle ne peut être ni relancée, ni corrigée, ni débloquée — elle ne peut que s'arrêter et
attendre que quelqu'un la rouvre à la main. C'est exactement ce qui s'est passé aujourd'hui : sept
sessions sur neuf se sont arrêtées après avoir livré, et je n'avais aucun moyen de les rappeler. La
seule qui a travaillé trois heures sans interruption, `claude-H`, est aussi la seule qui s'était
présentée et que je pouvais joindre.

**Donc :** Remote Control reste actif en permanence, sur les trois postes. On ne le coupe pas, on ne le
déplace pas vers une autre session « juste pour essayer », on ne ferme pas la session qui le porte. Le
réglage `Enable remote control by default` (Réglages → Claude Code) le rétablit à chaque nouvelle
session — c'est lui qui doit rester coché, pas une commande à retaper.

**Corollaire pour toute session :** ta première obligation en démarrant n'est pas de lire tes ordres,
c'est de **te présenter** à l'intégrateur. Tant que tu ne l'as pas fait, tu es hors de portée, et une
session hors de portée ne reçoit ni arbitrage, ni correction, ni relance. Liste tes pairs, trouve
`claude-A`, écris-lui. Ensuite seulement, va lire ta boîte.

**Ce que cela ne fait pas :** Remote Control n'empêche pas une session bureau de s'arrêter quand elle a
fini de répondre. Il la rend *rappelable*. La différence est décisive — un mur qu'on peut franchir de
l'extérieur n'est plus un mur.

### 2026-08-24 21:00 · D35 — Présentation horaire à l'intégrateur, quoi qu'il arrive
Consigne de Maxime, donnée directement aux neuf sessions le 24/08 au soir. Elle complète D34 et la
rend opérante.

**Toutes les heures, quoi que tu fasses, tu écris à `claude-A`.** Deux cas, deux contenus :
- **tâche en cours** → tu dis laquelle et où tu en es, en deux lignes ;
- **rien en cours** → tu demandes une tâche. Tu ne cherches pas à t'en inventer une, et tu ne t'arrêtes
  pas non plus : tu demandes.

**Pourquoi cette règle et pas un simple battement dans le rapport.** Un rapport est un fichier : je dois
aller le lire, et je ne sais pas qu'il a changé. Un message me réveille. Surtout, **la liaison ne
s'établit que dans ce sens-là** — je ne peux pas appeler une session qui ne s'est jamais annoncée. Le
message horaire est donc ce qui crée et entretient la joignabilité, pas seulement ce qui informe.

**Sa limite, qu'il faut connaître pour ne pas s'y fier seule.** Une session bureau arrêtée ne se
réveille pas toute seule au bout d'une heure : la règle vaut tant qu'elle tourne. Ce qu'elle garantit
vraiment, c'est qu'une session **redémarrée se re-présente**, et que je peux la relancer ensuite.
D34 + D35 forment donc une boucle : elle se présente, je peux la joindre, je la relance, elle se
re-présente. Chacune seule ne suffit pas.

**Corollaire pour moi.** Une présentation appelle une réponse. Une session qui écrit et ne reçoit rien
apprend que l'exercice est inutile, et cessera. Je réponds à chacune, même brièvement — et si elle
demande une tâche, elle en repart avec une.

### 2026-08-24 22:00 · D36 — Dix-sept commandes de domaine, et rien pour les exécuter
`claude-H` demandait où brancher deux entrées de planification pour son module. **Il n'y a nulle part
où les brancher.** Vérifié : le dépôt contient dix-sept commandes de domaine et **aucun ordonnanceur** —
ni Symfony Scheduler, ni cron dans les conteneurs, ni entrée sur l'hôte. Les seules occurrences de
« Scheduler » sont dans du JavaScript vendu avec API Platform et dans un fichier généré.

**Ce ne sont pas des tâches de confort.** Deux au moins sont des défauts de sécurité :

| Commande | Ce qui ne se produit jamais |
|---|---|
| `ExpirerEscalades` | une élévation **temporaire** de privilèges est en fait permanente |
| `ExpirerDelegations` | une délégation de droits n'expire jamais |
| `BasculerNoShow` | le no-show ne bascule jamais — D27 promet au client une séance restituée avec report, et rien ne l'exécute |
| `AppliquerConservation` | la conservation des données ne s'applique jamais |
| `ExpirerPmv` | le porte-monnaie virtuel n'expire jamais |
| `LibererPaniersExpires` | un panier abandonné retient sa place indéfiniment |
| `PurgeDocuments`, `RecalculerFenetresBadges`, `TraiterEcheancesSortie`, `AgregerMesures`, `ExecuterRapports`, … | — |

**C'est le quatrième mécanisme du jour qui existe sans tourner**, après le garde-fou de topologie, la
réinstallation des hooks et le démontage des piles de test. Mais celui-ci n'est pas de l'outillage :
c'est **toute la couche batch du produit**. On a écrit dix-sept fois « et ensuite une commande passe et
fait le nécessaire », et la commande ne passe jamais.

**La règle que j'en tire, et elle vaut au-delà de l'ordonnanceur :** une commande qui doit s'exécuter
périodiquement entre dans le dépôt **avec sa planification**, dans le même lot — exactement comme un
événement entre avec son émetteur (D22) et une entité neuve avec sa migration (D32). Écrire l'exécutant
sans l'exécution, c'est écrire du code mort qui a l'air vivant.

**Périmètre : `claude-A`.** L'ordonnanceur touche Platform et l'infrastructure, et il ne doit pas être
fait à moitié — une commande branchée deux fois est pire qu'une commande jamais branchée.

### 2026-08-24 22:00 · D36-bis — Le front n'appartenait à personne, et cela se voit
`frontend/**` **n'apparaît pas une seule fois** dans FLOTTE.md. J'ai réparti neuf périmètres — accès,
GED, réservation, séjour, social, verticales, administration, outillage, intégration — et j'ai oublié
l'interface.

**Conséquence mesurée** : le dernier commit touchant `frontend/` date du **19/08 à 02:26**. Cinq jours.
Aucune source du front n'a été modifiée depuis, donc le build n'est pas périmé : c'est le développement
qui s'est arrêté. Neuf sessions construisent une API que rien n'affiche.

**Attribution provisoire à `claude-H`**, qui vient de terminer SOC-1 à SOC-3 et dont le périmètre est
vide. Ce n'est pas un déplacement de périmètre — `frontend/**` n'appartenait à personne, je ne prends
rien à personne. **Maxime tranche** : s'il préfère que le front revienne à `claude-I`, encore à ouvrir,
`claude-H` repasse sur le social sans discuter.

### 2026-08-24 23:15 · D37 — L'instant métier d'un événement ne se devine pas
`claude-D` a trouvé, en testant le chemin complet plutôt que l'abonné isolément, que
`SubscriptionActivator` publiait `subscription.activated` **sans horodater l'événement**. `DomainEvent`
tombait donc sur son défaut documenté — `null` = maintenant — et l'abonné calculait les capacités
actives à l'instant d'**exécution** au lieu de l'instant **métier**.

**Ce que cela produisait :** un abonnement prenant effet plus tard était provisionné **sans les options
achetées** — la formule seule, parce que les lignes n'étaient pas encore actives à la date du calcul.
Aucune erreur, aucune trace, un établissement livré incomplet. Personne ne l'aurait vu avant que le
client ne cherche son module.

**Ce n'est pas un cas isolé. J'ai compté : vingt et un fichiers émettent un `DomainEvent`, et un seul
passe un instant explicite — celui que `claude-D` vient de corriger.** Les vingt autres reposent sur le
défaut, y compris deux que j'ai écrits moi-même en SF-1 (`AnnulerReservationProcessor`,
`BasculerNoShowCommand`).

**Le défaut du contrat est le vrai coupable.** `?\DateTimeImmutable $occurredAt = null` se lit comme
« optionnel », alors qu'il signifie « je certifie que l'instant métier est maintenant ». Pour une
annulation traitée dans la seconde, c'est vrai. Pour une activation différée, une bascule de no-show
nocturne ou une purge, c'est faux — et faux silencieusement.

**Traitement : un cliquet, pas un grand soir.** Rendre le paramètre obligatoire casserait vingt et un
appels d'un coup, répartis sur sept périmètres, et bloquerait tout le monde une soirée. On applique donc
ce que ce dépôt fait déjà six fois : **la dette est gelée à 20, et elle ne peut que descendre.** Toute
émission **neuve** doit passer son instant métier explicitement ; les vingt existantes se corrigent au
fil de l'eau, par le propriétaire de chaque module, quand il repasse dessus.

Garde-fou demandé à `claude-C`. Et la règle de lecture, pour ceux qui corrigeront : l'instant métier est
celui où **le fait s'est produit pour le client** — la date d'effet de l'abonnement, l'heure du créneau
manqué, la date de la demande de purge — jamais l'heure à laquelle le code s'exécute.

**Ce que `claude-D` en tire et que je reprends :** ce défaut n'était visible qu'en testant **à travers le
bus**. Ses tests du provisionnement seul passaient, parce qu'ils appelaient l'abonné à la main avec le
bon instant. Un abonné testé isolément prouve que l'abonné est juste, pas que l'émetteur lui dit la
vérité.

### 2026-08-25 · D38 — L'application du client final : web **et** native
Décision de Maxime, prise après avoir constaté que ce chantier n'apparaissait **nulle part** dans la
répartition. C'est le troisième trou du même genre en une soirée, après `frontend/**` et les dix-sept
modules serveur sans propriétaire : ma répartition couvrait ce que j'avais pensé à lister.

**Le socle est bien plus avancé qu'il n'y paraissait**, et c'est ce qui rend la décision réaliste. Il
existe déjà : `CompteClient`, `CreationCompteHandler`, `MeCompteClientProvider`, dix-huit points
d'entrée sous `/boutique/**` — panier, bénéficiaires, consentement, paiement, billets, commandes,
demandes de remboursement, souscription d'abonnement — et **douze permissions en `_soi`** taillées pour
exactement cet usage : `crm.lire_soi`, `crm.modifier_soi`, `crm.pmv_lire_soi`, `crm.pmv_recharger_soi`,
`boutique.lire_soi`, `boutique.demander_remboursement_soi`, `facturation.lire_soi`, plus les quatre du
padel. Le rôle « Client final » est par ailleurs **le seul du dépôt** à porter des droits par migration.

**Ce qui manque n'est donc pas le moteur, c'est le produit** : un espace personnel, « mes
réservations », « ma carte et son solde », le porte-monnaie côté client, et l'authentification du
client — le parcours actuel est pensé pour le personnel.

**Web d'abord, native ensuite, et ce n'est pas une préférence technique.** Publier une application
native suppose des comptes développeur Apple et Google, donc **l'immatriculation de la société** —
exactement le blocage qui tient déjà `SOC-4` (adaptateurs Meta) en statut `EXTERNE`. D19 s'applique :
on consigne, on n'attend pas. La web app est utilisable le jour où elle est prête, sans dépendre de
personne ; la coquille native peut être construite en parallèle mais **ne sera pas publiable** avant.

**Une contrainte d'architecture qui découle des deux cibles** : tout ce que l'application client
consomme passe par l'API publique, et **rien** par une route pensée pour le personnel. Deux clients
différents sur la même API obligent à cette discipline dès le premier écran ; l'ignorer maintenant
coûterait une reprise entière au moment du natif.

### 2026-08-25 · D39 — Qui rejoue une règle d'autorisation la rejoue **entière**
Le 24/08 vers minuit, Maxime s'est retrouvé avec **une colonne de menu entièrement vide** sur un écran
de caisse par ailleurs fonctionnel. Il venait de recevoir le rôle le plus puissant du logiciel.

**La chaîne du défaut, bout à bout.** Le socle accorde des permissions **joker** — `*` × `lire`, dont le
code effectif est `*.lire` ; ma migration du soir y a ajouté `*.*`. `CalculateurDroits` les rend **tels
quels**, et `PermissionVoter` les interprète correctement : l'API répondait normalement. Mais le menu du
front, écrit deux heures plus tôt, testait `droits.includes('caisse.lire')` — une **égalité stricte**.
Un administrateur porte `*.*` et **jamais** `caisse.lire`. Aucune entrée ne pouvait correspondre.

**Ce qui rend ce défaut instructif, c'est que la précaution avait été donnée et qu'elle n'a pas suffi.**
J'avais écrit à `claude-H` : « vérifie chaque nom de permission contre le catalogue serveur, pas contre
ton intuition ». Elle l'a fait. Sa propre analyse de l'échec est plus juste que ma consigne : *« j'ai
relevé la liste des permissions DEMANDÉES par les expressions `security`, et jamais la forme des
permissions ACCORDÉES. Ce sont deux choses différentes. »*

**La règle : un client qui rejoue une règle d'autorisation du serveur la rejoue entière, ou ne filtre
pas du tout.** Un filtrage partiel est pire qu'aucun filtrage : il produit un refus muet, à un endroit
où personne ne cherche la cause. Cela vaudra pour l'espace client final et le tableau de bord mobile
(D38), qui auront tous deux à filtrer sur des droits.

**Et un plancher de sûreté quand le filtrage porte sur la navigation** — posé par `claude-H`, et je le
retiens : si le filtre ne laisse rien, on retombe sur ce qui n'a pas de contrainte. Sa justification est
la bonne : *un menu vide enferme quelqu'un hors de son propre logiciel, sans moyen d'en sortir ni de
comprendre pourquoi ; un menu trop permissif se corrige tout seul, parce que l'API refuse et que le
refus est lisible.* Entre deux erreurs possibles, on choisit celle qui se voit.

**L'alternative écartée, pour qu'on ne la re-propose pas sans le savoir.** J'avais commencé à faire
**développer** les jokers par `codesEffectifs()` — le serveur aurait rendu la liste concrète, et aucun
client n'aurait eu de règle à connaître. C'est défendable, et je l'abandonne pour deux raisons : le
contrat actuel est **explicitement affirmé par un test** (`assertSame(['*.lire'], $codes)`), et une
permission créée après l'appel resterait couverte par le joker mais absente de la liste développée —
donc deux vérités selon le consommateur. Si quelqu'un veut y revenir, qu'il change le test **et** la
décision, pas seulement le service.

**Le fait déclencheur compte autant que le défaut** : neuf lots de front livrés sans que personne ait
jamais vu un écran. Ni la compilation ni la lecture ne pouvaient voir ça — une capture d'écran l'a
montré en trois secondes. **Quand on touche à ce qui est visible, quelqu'un doit regarder, et vite.**
Aucune session ne peut le faire : la préproduction demande des identifiants, et nous n'en saisissons
pas. Maxime est le seul œil de la flotte, et c'est une dépendance à assumer, pas à contourner.

### 2026-08-25 · D40 — Une date relative n'est pas une date distincte
`EclairageTest` était rouge sur `main`. **Aucune ligne de code n'avait changé depuis trois jours.**
`claude-G` a trouvé la cause, et elle n'est imputable à personne : c'est le calendrier.

La fixture de démonstration posait son créneau à « next tuesday », avec un commentaire annonçant un
créneau « délibérément distinct des scénarios de test ». Le test, lui, travaille sur « next monday ».
**Distinct par le jour de la semaine, pas distinct dans le temps** : « next tuesday » ne tombe après
« next monday » que cinq jours sur sept. Lancée un dimanche ou un lundi, la suite devient rouge.

Nous étions lundi soir — 22h23 côté conteneur pour 00h23 en heure locale, le décalage UTC de D32 qui
frappe ici sous un autre déguisement.

**Ce test était donc rouge deux jours par semaine depuis son écriture.** Personne ne l'avait vu parce
que personne ne lance `Padel` : le module appartient à `claude-I`, que Maxime n'a jamais ouverte. Il a
fallu qu'un lundi soir tombe pendant qu'une session regardait pour que ça se voie.

**Règle : deux jeux de données qui doivent rester ordonnés dans le temps s'ancrent sur la MÊME
référence, avec un écart explicite.** « Distinct » doit vouloir dire « distinct quel que soit le jour du
lancement », et un décalage d'une semaine entière est le seul écart qui le garantisse. Le correctif de
`claude-G` fait exactement cela : la démo passe à « next monday + une semaine », donc toujours huit à
quatorze jours devant, quel que soit le jour où la suite tourne.

**Deux conséquences qui dépassent ce test :**

1. **Un module sans session ouverte n'est lancé par personne.** La règle 8 impose de tester son module
   et `tests/Platform` — juste, mais elle ne couvre que les modules qui ont un propriétaire. Les cinq
   verticales de `claude-I` n'en ont pas. Tant que cette session n'est pas ouverte, leurs tests ne sont
   exécutés que par accident.
2. **Une suite verte ne prouve rien sur un jour de la semaine qu'on n'a pas essayé.** C'est la
   troisième forme que prend le temps dans ce dépôt, après les assertions à l'horloge murale (D20) et
   l'horodatage UTC des migrations (D32). Le temps est le piège récurrent de ce projet.

**Signalé sans être corrigé, et je le consigne pour ne pas le perdre** : `CommanderEclairageCommand`
balaie **toutes** les réservations sans borne de date et rattrape donc tout le passé à chaque
exécution. Inoffensif aujourd'hui grâce au contrôle d'événement déjà émis — c'est la cause structurelle
du symptôme, pas le symptôme. `claude-G` ne l'a pas touché parce que je lui avais ouvert Padel pour
rendre un test vert, pas pour le refondre. Cela revient à `claude-I`.

### 2026-08-25 · D41 — Le cloisonnement filtre les lectures, et personne ne garde les écritures
`claude-H` a trouvé que `PointDeVente` accepte son `etablissement` **depuis le corps de la requête**,
sans processor et sans contrôle. Quelqu'un qui a `caisse.gerer` sur son établissement peut donc créer un
point de vente **chez un autre établissement** — et un point de vente est ce sur quoi s'ouvre une caisse.

**J'ai compté avant de traiter, et ce n'est pas un cas : c'est une classe. Trente-cinq entités.**

    Acces          Equipement, EspaceAcces
    Boutique       PartenaireOTA, Vitrine
    Caisse         PointDeVente
    Caution        GrilleRetenue
    Crm            ParametrePmvEtablissement
    Musee          AllocationQuotaOTA, Audioguide, ContingentGratuite, Exposition, Guide,
                   ParametreMuseeEtablissement, PartenaireOTA, PassAnnuel, PolitiqueDelestage,
                   Salle, SousQuotaSalle
    Organisation   Espace
    Padel          ParametragePadel, PlageHoraire
    Patinoire      ParcPatins, SaisonEphemere, ZonePatinoire
    Personnel      RattachementEmploye
    Piscine        Bassin, ParametrePiscineEtablissement, Poss, QualificationEncadrant
    Recouvrement   PolitiqueRecouvrement
    Reservation    Activite, RegleAnnulation, Ressource
    Stock          Fournisseur, ParametrageStock

**Pourquoi personne ne l'avait vu, et c'est le point qui compte.** Les extensions Doctrine de ce dépôt
bornent les **lectures** au périmètre de l'utilisateur — vingt-six modules en ont une, et elles
fonctionnent. Une **écriture** qui reçoit son établissement du corps de la requête n'est vue par
personne : ni par l'extension, qui ne s'applique qu'aux requêtes de lecture, ni par le garde-fou de
cloisonnement, qui inspecte les **résolutions d'entités** et non les **groupes de sérialisation**.
Les seize IDOR trouvés en cinq jours étaient tous des lectures ou des résolutions. Ceux-ci sont d'une
autre nature, et notre outillage était structurellement aveugle.

**Ce que ça permet concrètement** : créer une salle dans le musée d'un autre client, un fournisseur chez
un concurrent, un bassin dans sa piscine, une plage horaire sur ses terrains. La victime verra ces
objets apparaître dans ses propres écrans — puisque ses lectures, elles, sont bien filtrées sur son
périmètre. C'est une pollution de données invisible à l'auteur comme à la victime.

**Le traitement : un mécanisme unique, pas trente-cinq processors.** Écrire trente-cinq gardes serait
répéter trente-cinq fois la même décision, dans douze périmètres différents, avec la certitude qu'on en
oublierait et que la trente-sixième entité écrite demain ne l'aurait pas. C'est exactement le motif que
`claude-H` a nommé cette nuit : *« le remède n'est pas de faire attention — c'est de rendre le cas
général impossible à écrire »*. Un décorateur du processeur de persistance d'API Platform, appliqué
globalement : toute entité écrite qui porte un établissement voit cet établissement confronté au
périmètre de l'appelant, et refusé en **404** s'il en sort.

**Et un garde-fou pour la classe, demandé à `claude-C`** : refuser qu'une entité neuve expose un
`Etablissement` dans un groupe d'écriture. Le mécanisme protège ce qui existe ; le garde-fou empêche
d'en ajouter. Sans lui, on répare trente-cinq fois et on recommence.

### 2026-08-25 · D42 — Campagnes marketing : ce que c'est, et surtout ce que ce n'est pas
Demandé par Maxime. **La première chose à trancher n'est pas ce que le module fait, c'est ce qui le
distingue de Revenue Recovery** — sans quoi on refait la collision SF-0, qui a coûté 415 lignes de
travail parallèle et une décision d'arbitrage.

**La frontière, et elle est nette :**

| | Revenue Recovery | Campagne |
|---|---|---|
| déclencheur | un **événement** arrivé à **un** client | une **décision** de l'exploitant |
| population | un individu, à la fois | une **audience**, choisie |
| moment | dès que l'événement survient | choisi, ou récurrent |
| finalité | récupérer ce qui allait être perdu | **provoquer** une venue qui n'allait pas avoir lieu |

Panier abandonné, facture échue, devis expiré : Revenue Recovery. « Les abonnés qui n'ont pas nagé
depuis trois mois » : campagne. Si un lot ne rentre dans aucune des deux cases, il faut se demander
lequel des deux modules ment.

**Trois choses existent déjà et il ne faut surtout pas les refaire :**

1. **Le consentement RGPD est modélisé et il est bon.** `Consentement`, `CanalConsentement`
   (email / sms / courrier), `EtatConsentement` (accordé / refusé / à renouveler). C'est la colonne
   vertébrale légale du module. **Un envoi de campagne se fait à travers ce contrôle, jamais à côté** —
   et pas « en le vérifiant », mais en le rendant impossible à contourner, au même endroit que l'envoi.
2. **Le port de notification client existe** — `SmartFlow\Port\ClientNotificationInterface`, avec un
   adaptateur qui **écrit dans un journal**. Autrement dit : *rien n'envoie quoi que ce soit à un client
   aujourd'hui.* C'est une bonne nouvelle déguisée en manque, voir plus bas.
3. **Les données de comportement sont là** : dernière visite, chiffre cumulé, abonnement, solde de
   carte, activités pratiquées, établissement. C'est ce qu'aucun outil généraliste ne possède.

**Ce qui n'existe pas, et c'est le cœur du module : l'audience.** Aucune notion de segment, de ciblage
ni de population dans tout le dépôt. C'est là que va l'effort.

**L'argument de fond, pour ne pas construire un Mailchimp de plus.** Un outil d'emailing dit qui a
ouvert. Cette plateforme peut dire **qui est revenu, ce qu'il a acheté, et combien ça a rapporté** —
parce qu'elle tient la vente. L'attribution est le seul avantage qu'un généraliste ne peut pas copier ;
si le module ne la porte pas, il ne vaut pas la peine d'être écrit.

**La dépendance externe, consignée et non attendue (D19).** Envoyer des courriels ou des SMS en volume
exige un prestataire — délivrabilité, désinscription, réputation d'expéditeur — donc un contrat, donc
l'immatriculation. Même famille que les adaptateurs Meta et les magasins d'applications.

**On ne l'attend pas.** Le module se construit **contre le port existant** et se livre avec
l'adaptateur de journal : audience, message, planification, consentement, mesure — tout est écrit,
testé et démontrable sans qu'une seule adresse ne soit contactée. Le jour où le prestataire existe, on
écrit un adaptateur et rien d'autre ne bouge.

**Une décision d'architecture qui découle de tout ça** : `ClientNotificationInterface` vit dans
`SmartFlow` alors que **trois** modules en ont besoin — Smart Flow, Revenue Recovery et Campagnes. Il
remonte dans `Platform`. Le laisser où il est obligerait deux modules à dépendre d'un troisième pour
une capacité qui n'appartient à aucun (D3/D8).

**Périmètre : à ouvrir.** Le module est proche de Revenue Recovery, donc de `claude-E` — muette depuis
vingt et une heures et jamais présentée, donc injoignable. Maxime tranche : soit `claude-E` reprend et
prend les deux, soit une session dédiée s'ouvre. **Ne pas l'attribuer par défaut à qui passe** : c'est
exactement ainsi que le front est resté cinq jours sans propriétaire.

### 2026-08-25 · D43 — Carte d'abord, prélèvement en repli : les deux se recueillent ensemble
Demandé par Maxime : la carte porte tout au départ ; en cas de rejet, bascule automatique sur le
prélèvement. Et au guichet comme en ligne, il faut pouvoir recueillir les deux.

**Le point non négociable, et il commande tout le reste : NOUS NE STOCKONS JAMAIS UN NUMÉRO DE CARTE.**
Ni le numéro, ni le cryptogramme, ni la date d'expiration. Vérifié : aujourd'hui rien n'en stocke, et
`DomainEvent` porte déjà une liste de censure (`card_number`, `pan`, `cvv`, `cvc`, `iban`, `bic`, …)
qui montre que quelqu'un y avait pensé avant moi. **Cette propriété ne se perd pas.**

Ce qui existe et qu'on utilise à la place :
- **au guichet**, la carte passe par le terminal (`Vente\Tpe\TerminalPaiementInterface`). Nous
  recevons un **résultat** et une référence de transaction. Le numéro ne transite jamais par nos
  serveurs, et « saisir le numéro de carte » veut dire *sur le terminal*, jamais dans un formulaire
  de l'application ;
- **en ligne**, les champs hébergés ou la redirection du prestataire bancaire. Même règle ;
- **pour un paiement récurrent**, un **jeton** rendu par le prestataire, qui ne vaut que pour notre
  compte marchand et ne permet à personne de reconstituer une carte.

Un numéro de carte dans notre base engagerait la conformité PCI-DSS de Maxime et de chacun de ses
clients. Ce n'est pas une contrainte technique, c'est une responsabilité qu'on ne prend pas.

**L'IBAN, lui, est stocké — et c'est légitime.** Un mandat de prélèvement en a besoin par nature. Il
est chiffré au repos (`Sepa\Service\ChiffreurIban`), et le module `Sepa` porte déjà `MandatSepa`,
`RemiseSepa`, `LigneRemiseSepa` et `RejetSepa`.

**La subtilité qui détermine tout le parcours.** Un repli automatique vers le prélèvement suppose
**un mandat déjà signé** : on ne crée pas un mandat sans la signature du client, et surtout pas au
moment où sa carte vient d'être refusée. Donc **les deux moyens se recueillent ensemble, à la
souscription** — la carte pour porter les échéances, le mandat signé pour prendre le relais. C'est
exactement ce que Maxime décrit, et c'est la seule séquence qui fonctionne.

**Ce qui suit du recueil des deux, et qu'on ne peut pas éluder : il faut l'expliquer.** Un client à qui
l'on demande une carte **et** un IBAN soupçonne un piège si on ne lui dit pas pourquoi. Le parcours doit
dire, en une phrase et avant la saisie : *« votre carte est débitée à chaque échéance ; le mandat ne
sert que si elle est refusée ou expirée, et vous serez prévenu avant tout prélèvement. »* Cette phrase
fait partie du lot, pas de la documentation.

**Ce qui existe déjà pour le rejet** : `RejetSepa` et son processeur, en **saisie manuelle**, faute de
lecteur de retour bancaire réel (`RetourSepaInterface`, aucun analyseur `pain.002`). Le rejet **carte**,
lui, n'existe pas du tout. La bascule automatique est donc un lot neuf, et elle dépend du prestataire.

**EXTERNE, consigné et non attendu (D19)** : le prestataire bancaire commande le jeton récurrent, les
champs hébergés en ligne, et la lecture automatique des retours. Comme partout ailleurs, **on construit
contre le port et on livre avec la simulation** — `TpeMock` existe déjà, la saisie manuelle du rejet
aussi. Tout le parcours, la bascule et l'explication au client sont écrits et démontrables avant qu'un
contrat ne soit signé.

### 2026-08-25 · D44 — Un seul produit, plusieurs tarifs : le modèle le fait déjà, l'écran l'empêche
Maxime, après un échange avec un gestionnaire de salle de sport : *« une entrée unitaire peut avoir
plusieurs tarifs et plein d'options ; il faut qu'il n'y ait qu'un seul produit créé et que ce soit
juste la tarification qui change. »*

**Le modèle fait exactement cela, et il le fait bien.** Vérifié :
`GrilleTarifaire` est un **quadruplet unique** — `produit × typeTarif × saison × trancheQuotientFamilial`.
Un même produit porte donc autant de lignes tarifaires qu'on veut : adulte, enfant, réduit, haute et
basse saison, tranches de quotient familial. Et `OptionProduit` rattache des **groupes d'options**
partagés à plusieurs produits.

**Rien à concevoir. Le problème est ailleurs, et il est net :**

`Caisse.jsx` ligne 141 — `const tarif = typeTarifId(l.produit)` — le guichet **choisit un tarif tout
seul** et n'offre aucun choix. L'API, elle, accepte `typeTarif` par ligne et résout le prix par
tarif × saison × quotient (`AjoutLigneHandler`, `ResolveurPrix`).

**Conséquence, et c'est l'explication de la prolifération** : un exploitant qui veut vendre une entrée
au tarif enfant n'a aucun moyen de le faire à l'écran. Il crée donc « Entrée enfant » comme produit
distinct. **Le modèle est propre, la pratique est sale, et c'est l'interface qui a forcé le
contournement.** C'est encore un cas des 181 opérations non branchées — le plus coûteux trouvé
jusqu'ici, parce qu'il ne se voit pas comme un manque mais comme une habitude.

**Traitement** : offrir le choix du tarif au guichet, et n'afficher les options que d'un produit. Aucun
changement de modèle, aucune migration.

**Et un garde-fou de conception, pour ne pas réparer l'écran et garder la mauvaise habitude** : quand
deux produits ne diffèrent que par leur tarif ou leur public, ce sont **un** produit et deux lignes
tarifaires. À écrire dans la spec des options (`UI-2`), et à rappeler dans l'écran de création.

### 2026-08-25 · D44-bis — Vendre sans caisse
Le même gestionnaire *« n'a pas besoin d'un outil de caisse, mais doit pouvoir vendre depuis le
catalogue directement »*.

**Aujourd'hui c'est impossible**, et par règle explicite : `CreerVenteProcessor` refuse toute vente
sans session de caisse ouverte — `RG-M2-01`, « Aucune session ouverte : vente impossible ».

Cette règle est **juste pour une caisse** : elle porte la responsabilité du fonds, la clôture Z et la
traçabilité de l'argent liquide. Elle est **absurde pour une salle de sport** dont le gérant encaisse
trois abonnements par carte dans le mois et n'a jamais vu un tiroir-caisse.

**La bonne réponse n'est pas d'assouplir la règle**, ce qui casserait la traçabilité pour tout le
monde. C'est de reconnaître qu'il existe **deux manières de vendre** :

| | Caisse | Vente directe |
|---|---|---|
| session | obligatoire, avec fonds et clôture Z | aucune |
| espèces | oui | **non** — c'est ce qui permet de se passer de session |
| responsabilité | le caissier répond du tiroir | la transaction répond d'elle-même |

**Sans espèces, il n'y a rien à compter, donc rien à clôturer.** La séparation tient à cette seule
phrase, et elle est vérifiable : la vente directe refuse les moyens de paiement fiduciaires.

À trancher par Maxime avant tout code : est-ce un **mode d'établissement** (cette salle ne fait pas de
caisse) ou une **permission** (ce vendeur peut vendre sans caisse) ? Les deux se défendent, et ce n'est
pas la même chose à l'usage.

### 2026-08-25 · D45 — Corriger un moyen de paiement : ce n'est pas une modification, et la date est celle du geste
Maxime : *« un caissier a renseigné espèces alors que c'était de la carte bancaire, on doit pouvoir
faire la modification… La question est de savoir si on le date du jour de l'action ou du jour de la
vente. »*

**Première réponse, et elle n'est pas négociable : on ne modifie pas.** Vérifié dans le dépôt —
`Vente\Nf525\InalterabiliteListener` et `OperationInalterableException` **refusent** toute écriture sur
une opération scellée, et `HashChainSignataire` chaîne les empreintes. Ce n'est pas une politique qu'on
pourrait assouplir : c'est le mécanisme qui donne sa valeur à la chaîne. Le jour où l'on peut réécrire
une vente validée, **plus aucune vente n'est probante**, y compris les milliers qui étaient justes.

**Ce qu'on fait à la place.** Le dépôt sait déjà le faire pour l'annulation et le remboursement :
`ContrePassationHandler` produit un **avoir** horodaté, motivé, signé de son auteur, rattaché à la vente
d'origine, scellé à son tour — *« sans jamais supprimer de ligne d'origine »*.

**Mais pour un moyen de paiement, l'avoir est le mauvais outil.** Un avoir suivi d'une nouvelle vente
annule et rejoue le chiffre d'affaires : on fait bouger deux fois le résultat pour corriger une erreur
qui n'a rien changé au montant. **Le montant de la vente n'est pas en cause — seule sa ventilation
l'est.** Il faut donc une **écriture de correction de règlement** : −X en espèces, +X en carte,
rattachée à la vente, motivée, signée, scellée. La vente reste intacte et sa somme ne bouge pas.

**Réponse à la question de la date : le jour du geste, pas le jour de la vente.** Trois raisons, dans
l'ordre de force :

1. **La chaîne NF525 est chronologique.** Insérer une écriture datée d'hier dans une chaîne scellée
   aujourd'hui la rendrait incohérente — c'est-à-dire invérifiable.
2. **La clôture Z d'hier est fermée.** Si la correction remontait au jour de la vente, il faudrait
   réécrire un Z déjà tiré, c'est-à-dire refaire l'histoire du fonds de caisse. Un Z qu'on peut
   réécrire ne prouve plus rien.
3. **La correction est un fait réel**, qui a eu lieu aujourd'hui, décidé par quelqu'un. Le dater d'hier
   effacerait la seule information qui compte en cas de contrôle : **quand s'en est-on aperçu**.

**La conséquence qu'il faut assumer, et le dire à l'exploitant** : le Z d'hier garde sa ventilation
fausse, celui d'aujourd'hui porte la correction. Ce n'est pas un défaut, c'est **ce qui rend le Z
digne de foi**. Et ce n'est pas un problème d'analyse : la correction pointe la vente d'origine, donc
un état par date de vente reste calculable. C'est une question de **restitution**, pas de donnée.

**Le droit qui va avec.** Maxime a raison : c'est une permission, et elle n'existe pas. Elle est
sensible — quelqu'un qui peut déplacer des espèces vers la carte peut masquer un manquant. Elle doit
donc être **distincte** de `caisse.gerer`, portée par l'administrateur du club, et chaque usage doit
être lisible dans le journal d'audit, pas seulement dans la chaîne.

### 2026-08-25 · D45-bis — Vente directe : une permission, et trois documents qui n'existent pas
**Permission, pas mode d'établissement** — tranché par Maxime. Certains clubs n'ont même pas le module
de caisse et doivent pouvoir vendre. La règle vérifiable reste celle de D44-bis : **la vente directe
refuse les espèces**, donc il n'y a rien à compter et rien à clôturer.

**Et trois documents manquent, vérifié :** `Facturation` porte `Facture`, `LigneFacture`,
`ReglementFacture`, `SerieNumerotation`, `DestinataireFacturation` — **la facture existe et elle est
sérieuse**. En revanche **devis**, **bon de commande** et **bon de livraison** n'existent nulle part.
Le seul objet approchant est `Stock\Entity\ReceptionAchat`, qui va dans l'autre sens — ce qu'on
achète, pas ce qu'on vend.

Ces trois-là forment une chaîne : devis accepté → commande → livraison → facture. Chaque étape reprend
la précédente sans la ressaisir, et chacune peut s'arrêter là. C'est un lot cohérent, pas trois lots.

### 2026-08-25 · D46 — Corriger sans caisse, et chercher un écart sans noyer l'œil
Trois questions de Maxime, dont la dernière porte une idée qui vaut mieux que les deux autres.

#### 1. Un manager corrige sans ouvrir de caisse : où atterrit l'écriture ?

**Elle n'a pas besoin de session, parce qu'elle ne touche aucun tiroir.**

C'est la distinction qui manquait à D45. Une correction de ventilation — espèces vers carte — ne
déplace **aucun billet** : elle constate que l'argent n'était pas là où on l'a écrit. Une session de
caisse et sa clôture Z servent à **compter du liquide**. Rien à compter, donc rien à ouvrir.

L'écriture est donc rattachée à **la vente**, pas à une session, et datée du jour du geste (D45).
Seule une correction qui déplacerait réellement des espèces exige une session — et c'est alors un
mouvement de caisse, ce qui existe déjà.

**Et il y a mieux : la correction peut pointer l'écart qu'elle explique.** `AlerteEcartCaisse` existe
et se rattache à une `ClotureZ` et à sa session. Si le Z d'hier a constaté 50 € de manquant, la
correction d'aujourd'hui doit pouvoir dire *« c'est ce manquant-là »*. **Un écart expliqué cesse d'être
un écart** — c'est ce qui transforme une liste d'alertes qu'on finit par ignorer en une liste qui se
vide.

#### 2. Les trois dates

Vérifié : `Facture` porte déjà `dateEmission`, `dateEcheance`, `acquitteeLe` et `creeLe`. **Il manque la
date de modification**, et l'ensemble n'existe pas au niveau de la **vente**.

Elles entrent, mais avec une réserve qui répond à la vraie demande de Maxime — *« que les listes ne
soient pas débordantes de chiffres »* : **on affiche l'écart, jamais les dates brutes.** Trois dates
côte à côte obligent l'œil à soustraire, à chaque ligne, toute la journée. Un « en retard de 12 j » ou
un « corrigée 3 j après » se lit sans calculer, et c'est ce qu'on cherchait.

Les dates restent disponibles au détail. Elles ne sont simplement pas ce qu'on met dans une liste.

#### 3. Le drapeau par origine — l'idée qui structure le reste

Maxime : *« une vente faite en caisse attend un paiement immédiat ; une vente faite depuis l'outil de
gestion peut avoir des conditions à trente ou soixante jours. »*

**C'est juste, et ça change la nature du problème.** Chercher des incohérences devient une requête
qu'il faut savoir formuler ; **porter une attente de paiement** en fait une propriété de la vente, que
n'importe qui peut vérifier :

| Origine | Attente | Anomalie |
|---|---|---|
| guichet | immédiate | non soldée à la clôture de session |
| vente directe | terme convenu (30 j, 60 j…) | non soldée **après** l'échéance |

**Une anomalie n'est plus un cas à chercher : c'est un écart entre ce qui était attendu et ce qui est.**
Le même écran sert alors aux deux mondes sans qu'on ait à expliquer la différence à personne, et une
vente à 60 jours cesse d'apparaître en rouge le premier jour — ce qui est précisément ce qui fait qu'on
n'ouvre plus la liste.

`Facture` porte déjà `dateEcheance` : la moitié du chemin est faite. Ce qui manque est de la **poser à
la vente** et de la **dériver de l'origine** plutôt que de la saisir.

**Conséquence à assumer** : l'origine d'une vente devient une donnée porteuse de sens, pas un
renseignement. Elle doit être posée à la création et ne plus bouger — une vente de guichet requalifiée
en vente directe effacerait l'anomalie au lieu de la traiter.

#### 2026-08-25 · D46-bis — L'attente de paiement est **par canal**, et l'OTA n'est pas un client
Maxime, immédiatement après D46 : *« n'oublie pas qu'il y a vente en ligne, appli, borne, etc. »* Il a
raison et mon tableau à deux colonnes était faux.

**État vérifié** : `Offre\Enum\Canal` porte **trois** valeurs — `guichet`, `en_ligne`, `borne`. Il en
manque déjà deux que Maxime a décidées cette semaine, plus un cas particulier :

| Canal | Attente de paiement | Anomalie | Qui paie |
|---|---|---|---|
| `guichet` | immédiate | non soldée à la clôture de session | le client, en face |
| `en_ligne` | immédiate, **avant** confirmation | panier payé à moitié, retour bancaire perdu | le client |
| `borne` | immédiate, sans espèces en pratique | transaction acceptée sans contrepartie | le client |
| `appli` *(à créer — D38)* | immédiate | idem en ligne | le client |
| `gestion` *(à créer — D45-bis)* | **terme convenu** (30 j, 60 j…) | non soldée après l'échéance | le client, plus tard |
| `ota` *(à créer)* | **différée et groupée** | écart de rapprochement | **le partenaire, pas le client** |

**Le cas OTA est celui que personne n'avait soulevé, et il ne rentre dans aucune des deux cases.** Une
vente OTA non soldée **n'est pas une créance client** : le visiteur a payé son agence, et c'est
l'agence qui reverse — `Boutique\Entity\ReversementOTA` existe déjà pour ça. La traiter comme un
impayé enverrait une relance à quelqu'un qui a payé, ce qui est le pire résultat possible pour une
fonction censée récupérer de l'argent.

**Donc l'attente ne suffit pas : il faut aussi savoir QUI doit.** Deux propriétés, pas une.

**La règle qui empêche la prochaine omission — et c'est le vrai enjeu de cette décision.** Maxime a
trouvé le trou en trois secondes parce qu'il connaît son métier ; le prochain canal sera ajouté par
quelqu'un qui ne le connaîtra pas. **Un canal ne peut pas exister sans déclarer son attente de paiement
et son débiteur.** Ce n'est pas une consigne : la donnée est exigée à la construction, et un canal
ajouté sans elle ne compile pas.

C'est exactement le motif qu'on répète depuis trois jours — *un mécanisme qui dépend de la vigilance
n'est pas un mécanisme* — appliqué à une énumération qui va grandir de trois valeurs cette année.

### 2026-08-25 · D47 — Un garde-fou qui accuse à tort est pire que pas de garde-fou
`claude-H` a écrit un contrôle qui refuse la comparaison brute de droits dans le front (D39). En le
livrant, elle a constaté qu'il **accusait `claude-D` à tort** : il signalait que l'administration
éditeur rendait un composant sans lui passer ses droits, alors que l'éditeur importe **son propre**
`Clients.jsx`, qui ne teste aucun droit. Le contrôle comparait des **noms** sans résoudre les imports —
et deux composants peuvent porter le même nom dans deux dossiers.

**Ce qui aurait suivi, si elle ne l'avait pas vu.** Une session envoyée chercher une faute inexistante,
**dans le périmètre d'une autre**, sur la foi d'un outil. Le coût n'est pas le temps perdu : c'est que
la deuxième fausse alerte fait désinstaller le garde-fou, et qu'on perd alors aussi les vraies.

**Règle : un garde-fou se vérifie sur du code SAIN avant d'être livré, pas seulement sur le défaut
qu'il cherche.** Le vérifier rouge sur le défaut prouve qu'il voit quelque chose ; le vérifier vert sur
du code juste — **et en particulier sur celui d'un autre périmètre** — prouve qu'il ne voit pas
n'importe quoi. Les deux sont nécessaires, et seule la seconde manquait à nos habitudes.

**Et la leçon qu'elle en tire, qui vaut au-delà de l'outillage.** Elle s'est fait prendre **trois fois**
par le même piège d'échappement — une expression régulière construite dans un gabarit de chaîne, où
`\s` vaut `s` : le contrôle redevenait muet, vert en ne vérifiant rien. La troisième fois, elle n'a pas
corrigé l'échappement une fois de plus : **elle a retiré toutes les expressions régulières construites
du fichier.**

> *« Trois fois le même piège, c'est que le remède n'était pas le bon : ce n'est pas la vigilance qui
> manquait, c'est la construction qui était fragile. »*

C'est la formulation la plus aboutie du motif qu'on suit depuis trois jours. On avait *« un mécanisme
qui dépend de la vigilance n'est pas un mécanisme »* ; on a maintenant son corollaire pratique —
**quand la même erreur revient une troisième fois, on ne la corrige plus, on supprime ce qui la rend
possible.**

### 2026-08-25 · D48 — Les dix-sept modules orphelins reçoivent un propriétaire
Maxime : *« vas-y, occupe-toi de tout. »* Délégation explicite après que je lui ai présenté la carte
des affinités et le compte : **802 fichiers, 17 modules sans propriétaire** — à peu près autant que ce
que les neuf sessions possédaient déjà.

**J'avais proposé de n'en attribuer que trois et de déclarer les quatorze autres comme orphelins.
Je change d'avis, et voici pourquoi** : un propriétaire endormi **peut être réveillé** ; un module
orphelin ne le peut pas. Le premier est un risque visible avec un nom dessus, le second est un angle
mort — et on sait ce que les angles morts ont coûté cette semaine : cinq jours de front sans personne,
un test rouge vingt-quatre heures, trente-cinq entités sans protection d'écriture.

**La répartition, par affinité et non par équilibrage :**

| Modules | Propriétaire | Raison |
|---|---|---|
| `Facturation` `Compta` `Sepa` `Finance` | `claude-D` | elle y vit depuis deux jours et n'avait pas le droit d'y écrire |
| `Vente` `Caisse` `OptionProduit` | `claude-G` | elle possède `Offre` ; produit et vente sont une seule chaîne |
| `Crm` `Recouvrement` | `claude-E` | Revenue Recovery **est** du recouvrement |
| `Autorisation` `Support` | `claude-B` | droits et documents, prolongement direct d'`Acces` et `Dms` |
| `Boutique` `Stock` `Caution` | `claude-F` | boutique, stock et cautions vont avec le séjour |
| `Personnel` `Reporting` `Ocr` | `claude-A` | **aucune affinité honnête** — je les garde plutôt que de les forcer |

**Les trois derniers sont chez moi par défaut d'affinité, pas par compétence.** Je les rendrai à la
première session dont le périmètre les touchera vraiment. Les mettre chez quelqu'un « parce qu'il reste
de la place » serait la façon la plus sûre de recréer un orphelin avec un nom dessus.

**Ce que cette décision ne résout pas, et qu'il faut dire.** Quatre des huit propriétaires dorment
depuis six à huit heures — `B`, `C`, `E`, `F`. Leur attribuer des modules ne les réveille pas. Mais
`FLOTTE.md` porte désormais une colonne d'état : **un propriétaire muet est un risque nommé**, pas un
trou. C'est la même règle que les entrées de menu grisées de `claude-H` — *un manque déclaré s'arbitre,
un manque implicite se découvre par accident.*

**Trois lots deviennent débloqués immédiatement** : les trois filtres de `Vente` qui bloquent
l'historique des ventes depuis ce matin, le moyen de paiement préféré dans `Crm` demandé par Maxime, et
le droit d'écrire dans `Facturation` pour `claude-D`.

---

### 2026-08-26 · D50 — Le format d'écriture ne sera **pas** déclaré globalement, et le contrôle vaut mieux que la règle

**Le signalement.** Maxime, en essayant d'ajouter un tarif : *« The content-type "application/json" is not
supported. Supported MIME types are "application/ld+json". »* Un 415, sur la fonctionnalité livrée la
veille.

**Ce que j'ai mal mesuré.** J'ai compté par `grep` les POST dépourvus du drapeau `ld: true` : 33. C'était
un comptage de syntaxe, pas un diagnostic. `claude-H` a trouvé la cause réelle : une opération à
`uriTemplate` sur mesure porte `input: false` et son processor lit le corps brut — **elle se moque du
format**. Seules les opérations standard désérialisent et exigent `ld+json`. **13 cassées, 27 indemnes.**
Sur mon chiffre, on aurait modifié la caisse, qui fonctionne.

**L'ampleur réelle : 236 écritures standard exigent `ld+json`, 232 sur mesure s'en moquent.** Presque la
moitié de l'API bascule sur une distinction qu'aucune signature ne rend visible.

**Sept des treize appels cassés l'étaient depuis avant l'arrivée de `claude-H`** : créer un client, un
utilisateur, un moyen de paiement, un groupe d'options, une valeur d'option, une option produit, une
affectation. Tous en 415 **depuis toujours**. Personne ne l'avait signalé — ou plutôt, personne ne
l'avait signalé *sous cette forme* : on ne voit pas un code HTTP, on voit un bouton qui ne fait rien.
Plusieurs plaintes de Maxime qu'on avait classées comme des manques d'écran étaient en réalité ce 415.

**L'arbitrage : on ne déclare pas `formats` globalement.** Ajouter `json` aux formats du serveur
réparerait les 236 écritures d'un geste, et changerait du même coup la négociation en **lecture**. Les
collections répondent en JSON-LD, que tout le front lit par la clé `member`. On échangerait un défaut
d'écriture connu contre un risque de casse de **toutes les listes**, y compris celles de `claude-D` et
de la vitrine. `claude-H` a jeté sa propre première version pour cette raison, et la formule est à
garder : **corriger un défaut en modifiant ce qui marche, sans pouvoir le retester, c'est échanger un
bogue connu contre un risque inconnu.**

**Ce qu'on fait à la place : un contrôle.** `verifier-formats` croise chaque POST du client avec les
`uriTemplate` déclarés dans `app/src` et signale tout appel standard sans `ld: true`. Vérifié dans les
deux sens — vert sur l'état corrigé, rouge sur exactement l'appel qui bloquait Maxime.

**Réserve posée, et elle vaut pour tous les contrôles du dépôt.** Un script `npm` ne tient que si
quelqu'un le lance, et rien ne le lance. Le jour même, j'ai resserré deux cliquets que les garde-fous
annonçaient résorbés **à chaque push depuis plusieurs jours**, commande à l'appui : le message
s'affichait, personne ne le lisait, le plafond réautorisait ce qu'on venait de corriger (couverture
34 → 30, transfrontière 5 → 3). **Un garde-fou facultatif n'est pas un garde-fou, c'est une
documentation exécutable.** `verifier-formats` doit être branché dans `pre-receive` comme les sept
autres. Question ouverte à `claude-H` : ses scripts tournent-ils dans l'image node figée du
déploiement, sans réseau — le hook s'exécute en `--network none`, exprès. Si non, on les réécrit en PHP
plutôt que de les laisser facultatifs.
