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

---

### 2026-08-26 · D51 — Un référentiel se tranche sur **qui décide de la liste**, pas sur « est-ce partagé »

**La question de `claude-G`**, en attente depuis le matin : cinq entrées classées « référentiel présumé »
par le garde-fou de couverture — `Categorie`, `Saison`, `TypeProduit`, `TypeTarif`,
`TrancheQuotientFamilial` — faut-il les cloisonner ? Elle plaidait pour laisser `TypeProduit` et
`TypeTarif` globales : *« les cloisonner ferait descendre un compteur en cassant le partage de
référentiel qui fait l'intérêt d'une plateforme. »*

**Elle a raison, et le cadrage global/cloisonné rend pourtant la question insoluble.** Trois de ces cinq
listes ne sont pas décidées par nous.

**Le critère : qui décide de la liste.**

| Référentiel | Qui décide | Verdict |
|---|---|---|
| `TypeProduit` | **nous** — la valeur pilote du code | **global**, lecture seule pour les établissements |
| `TypeTarif` | nous pour le socle, **eux** pour le reste | **global + ajout local** |
| `Categorie` | **eux** — celle d'une patinoire n'est pas celle d'un musée | **global + ajout local** |
| `Saison` | **eux** | **cloisonné** |
| `TrancheQuotientFamilial` | **la collectivité** | **cloisonné**, sans discussion |

**Le quotient familial tranche seul.** Les tranches sont fixées par la commune ou la CAF. Deux
établissements de deux communes ont des grilles différentes, et un tarif calculé sur la mauvaise grille
est une **erreur de facturation opposable**. Il n'y a pas d'arbitrage à rendre.

**`TypeProduit` confirme `claude-G`, pour une raison plus forte que le partage** : sa valeur pilote des
branches d'exécution. Un établissement qui invente un type produit invente un chemin de code qui
n'existe pas. Ce n'est pas un référentiel, c'est une énumération qui a mal tourné.

**⚠ Ce que « global » engage, et que la question ne posait pas.** Une table globale que n'importe quel
établissement peut **écrire** est un trou transfrontière : A renomme un `TypeTarif`, le tarif de B change.
Silencieux et immédiat. **Garder une table globale engage donc à la rendre non écrivable par un
établissement** — sans quoi « partagé » signifie « modifiable par tout le monde ».

C'est D41, et `claude-C` vient d'en corriger le compte : **49 entités concernées et non 35** que j'avais
annoncées. Vingt-sept par groupe d'écriture, **vingt-deux sans aucun `denormalizationContext`** — c'est
l'absence de déclaration qui expose, et ma lecture, qui cherchait des groupes, ne pouvait pas les voir.
**La voie la moins visible était la plus large.** Son garde-fou est fusionné.

**Forme laissée à `claude-G`** (colonne `etablissement` nullable, ou table d'extension), avec deux
exigences : le socle n'est écrivable que par la plateforme ; une lecture d'établissement voit le socle
**plus** ses ajouts, jamais ceux d'un autre. Vérifier d'abord qu'un patron nullable n'existe pas
ailleurs — en inventer un second serait pire que le problème.

---

### 2026-08-26 · D51-bis — Une session est muette si sa **branche** ne bouge pas, pas si on ne la joint pas

J'ai annoncé à Maxime que je ne pouvais plus joindre `claude-G` ni `claude-D`, et j'en ai déduit une
flotte à moitié silencieuse. **`claude-D` m'a corrigé** : le canal fonctionnait, sept échanges depuis la
veille. Ce sont les **noms** qui avaient disparu de l'annuaire, pas les sessions.

Sa règle remplace la mienne, et la mesure la confirme : **sept sessions sur neuf avaient poussé dans
l'heure** (A, B, C, D, F, G, H ; `E` à huit heures, `I` jamais ouverte).

C'est la deuxième fois de la semaine qu'une mesure non réactualisée devient une affirmation — et c'est
`claude-D` qui l'avait déjà signalé la première fois, en se trompant elle-même sur le compte de la flotte.
La leçon est symétrique : **on ne mesure pas l'activité par le canal qui la rapporte.**

---

### 2026-08-26 · D52 — « Ne lève pas » n'est pas « idempotent »

**La règle.** Un rechargement de données ne se juge pas à l'absence d'erreur, mais au **nombre de lignes**.
Une entité dont la seule unicité porte sur son identifiant technique — régénéré à chaque construction —
ne produit **aucune** erreur au second chargement : elle se duplique en silence.

**Comment on l'a trouvée, et pourquoi la méthode compte autant que la règle.**

`claude-G` avait signalé quatre familles d'entités uniques dans `SocleFixtures`. J'en ai gardé trois et
déclaré la fixture idempotente. Elle avait annoncé le résultat : *« corriger la seule famille qui bloque
fait avancer le curseur d'un cran »*. Le double chargement a effectivement buté un cran plus loin, sur
`Utilisateur.email`.

J'ai alors arrêté de suivre les erreurs et **inventorié ce que le fichier construit**. Sept types.
Quatre gardés, un oublié, et **trois qui ne crieront jamais** : `Groupe`, `Region`, `Etablissement`. Un
second chargement y crée un second « Groupe Loisirs Métropole », une seconde « Région Est », **deux
Piscine A**.

**Suivre les `Duplicate entry` ne les aurait jamais révélés, puisqu'ils n'en produisent aucun.**

**Pourquoi celui-là est le plus grave, et pas le moins.** Un établissement en double n'est pas un doublon
de données, c'est une **frontière de cloisonnement dupliquée**. `claude-G` l'a formulé en constatant que
tout son module s'y appuie : `PerimetreReservationExtension` joint `etablissement` à une `Affectation`,
`PerimetreProduitExtension` fait pareil par les sites d'un produit. **Deux « Piscine A », et la question
« cet utilisateur a-t-il le droit ? » a deux réponses selon la ligne tirée.**

**Le contrôle qui l'attrape** est dans `FixturesIdempotentesTest` : compter les lignes de toutes les
tables peuplées avant et après la seconde passe, et échouer si un compte a bougé. Exigé par `claude-D`,
qui avait vu que ma première version ne voyait que les tables assez bien contraintes pour crier.

**Ce qu'on en retient au-delà des fixtures :** quand un défaut ne se manifeste que par une erreur, on ne
corrige que les cas assez contraints pour en produire une. **Il faut inventorier ce qu'on construit,
pas suivre ce qui casse.**

---

### 2026-08-26 · D53 — Un garde-fou ne met jamais son propre contournement dans son message d'échec

**Le constat, apporté par `claude-D` après trois refus dans la même semaine.** Les trois fois, la porte
de sortie était offerte par le garde-fou lui-même : *retirer les mots du lexique* (nommage anglais),
*documenter l'entité comme globale dans `MESSAGES.md`* (couverture), *relever le plafond* (cliquet). Les
trois fois, refuser a produit du meilleur code.

**Le problème n'est pas que la porte existe, c'est qu'elle est mise en avant.** Un garde-fou qui propose
son propre contournement transforme une question technique en question de caractère — et le caractère
cède un vendredi soir. Le remède affaiblit alors le contrôle pour faire passer le code, ce qui est
exactement l'inverse de sa raison d'être.

**Le critère opérationnel, tel que `claude-D` l'a formulé :** le message d'échec explique **ce qui est
cassé et comment le réparer**. Le contournement, lui, vit dans la **documentation** du garde-fou, pas
dans sa sortie d'erreur. *Qui le cherche le trouve ; qui est pressé ne tombe pas dessus.*

**Cas d'espèce :** `claude-D` a renommé ses classes plutôt que d'accepter le retrait des mots du lexique,
sur un module tout neuf — *« prendre la porte de sortie au moment où le module vient de naître aurait
été le pire moment »*.

À appliquer aux douze garde-fous. `bin/` est le périmètre de `claude-C`.

---

### 2026-08-26 · D54 — Le geste qui exige un droit plus fort que l'écran qui le porte

**Trois modules indépendants ont inventé le même motif dans la même journée**, sans se concerter et sans
que personne l'ait nommé :

| Module | Le geste | Le droit distinct |
|---|---|---|
| Facturation (`claude-D`) | émettre la facture d'une pièce | `invoice`, plus fort que la lecture du document |
| Patinoire (`claude-H`) | retenir une caution **hors barème** | `patinoire.forcer_retenue` |
| Stock (`claude-H`) | régulariser un **écart significatif** d'inventaire | `stock.valider_ecart` |

Trois découvertes séparées le même jour, c'est un patron, pas une coïncidence.

**La forme commune :** un écran présente plusieurs gestes sur un même objet ; l'un d'eux engage
davantage — de l'argent, une pièce comptable, une correction que personne ne reverra — et exige donc un
droit que la simple consultation de l'écran n'implique pas.

**La règle d'écran, et elle va à contre-courant de l'habitude.**

`claude-H` avait déjà obtenu de Maxime la règle générale : *si les accès sont refusés, pourquoi laisser
l'affichage dans le menu ?* — donc **on cache ce qui est interdit**. Ce cas-ci est l'exception, et il
faut savoir pourquoi.

**Le bouton s'affiche, il annonce le droit qu'il exige, et il est désactivé pour qui ne l'a pas.**

Cacher un geste rare fait croire qu'il **n'existe pas** : l'agent qui a besoin d'une retenue hors barème
cherchera un contournement, appellera son responsable pour « le logiciel ne le permet pas », ou saisira
un montant faux dans le champ qui, lui, s'affiche. Un menu caché dit « ce n'est pas pour vous » ; un
geste caché dit « c'est impossible ». Ce n'est pas la même phrase, et la seconde est un mensonge.

La distinction tient donc à ceci : **on cache une zone entière du produit, on n'ampute pas un écran
qu'on affiche.**

**Corollaire, tiré de `claude-H` sur la patinoire :** un geste dont la conséquence n'est pas écrite à
côté de lui est un piège, indépendamment des droits. Trois états de retour de patins déclenchent trois
traitements de caution différents — l'agent qui coche « cassés » sans savoir qu'il déclenche une retenue
l'apprendrait par la réclamation du client.

**Et le seuil qui décide de « significatif » doit être lisible avant d'écrire la phrase.** Dans `Stock`,
`seuilEcartSignificatifPourcentage` et `seuilEcartSignificatifMontant` sont tous deux **nullables** : si
personne ne les a réglés, on ne sait pas si rien n'est significatif ou si tout l'est. Un écran qui
affirme « écart non significatif » sur un seuil jamais configuré ment à l'agent **avec l'autorité du
logiciel**.

---

### 2026-08-26 · D54-bis — `Stock` : soixante-trois opérations exposées, aucune appelée

Relevé de `claude-H` (`npm run mesurer-ecart --par-module`) : le module est **complet et cohérent** —
quatorze entités, dix-huit processors, le cycle d'achat entier, le cycle d'inventaire, les transferts
inter-sites, et trois modèles de lecture déjà calculés. **Rien n'y manque sauf une porte.**

Ce n'est pas de la dette : c'est du travail déjà payé qui ne sert à rien.

**Mais deux choses résistent aux écrans, et il faut les régler d'abord.**

**`ArticleStock` ne porte aucune quantité** — ni disponible, ni en stock. Le chiffre réel vit dans
`StockLot.quantiteRestante`, à agréger. Un écran « articles » afficherait donc une liste **sans le seul
chiffre qu'on vient y chercher**, ce qui est pire qu'un écran manquant : il donne l'impression d'avoir
consulté le stock. `ParcPatins::getQuantiteDisponible()` est le précédent à suivre — c'est lui qui a
permis à `claude-H` de faire tout l'écran patinoire en un lot.

C'est **le signal muet à l'envers** : d'ordinaire l'information existe et rien ne l'appelle ; ici elle
n'existe pas. `Stock` est le périmètre de `claude-F`.

---

### 2026-08-26 · D55 — Une liste de choses à traiter s'affiche avec le geste qui les traite, ou ne s'affiche pas

**Le relevé de `claude-H`**, demandé après sa remarque : *une liste qui ne descend jamais à zéro n'est pas
un signal, c'est du décor.*

| Liste | Affichée | Geste qui la vide | Branché |
|---|---|---|---|
| `AlerteEcartCaisse` | non | oui, **ailleurs** (`/ventes/{id}/corriger-reglement`) | non |
| `AlerteReappro` | oui | indirect (passer une commande) | non |
| `ConflitGlace` | oui | indirect (déplacer le créneau) | non |
| `DeclarationIncidentBadge` | non | oui (`annuler`) | non |
| `IncidentImpaye` | non | oui (`resoudre`, `forcer-reouverture`) | non |
| `AlertePresenceIsolee` | non | **aucun** — le seul POST en *crée* | — |

**Quatre listes affichées, zéro geste de résolution branché.** Toutes les listes d'alerte du produit sont
aujourd'hui du décor.

**La règle :** *une liste de choses à traiter s'affiche avec le geste qui les traite, ou ne s'affiche
pas.* Pas « on branchera la résolution plus tard ».

**Le motif, et c'est lui qui justifie la sévérité :** une liste qu'on ne peut pas vider **apprend à son
lecteur à l'ignorer**, et cet apprentissage ne se défait pas quand on branche le geste six mois après.
La liste sera toujours là ; l'habitude de ne pas la regarder aussi. On aura alors deux défauts : le
retard initial, et un signal durablement mort qu'aucun correctif ne ranime.

**Le corollaire, et il évite une erreur d'accusation.** `RejetSepa` et `VenteImpayeeRegie` n'ont **aucun
champ de statut** : ce ne sont pas des files d'attente qui ne se vident pas, ce sont des **journaux**, et
un journal qui grandit se comporte correctement. `claude-H` s'est arrêtée avant de les accuser, et elle a
eu raison.

**Donc le défaut n'est pas toujours dans l'API — il est souvent dans le cadrage.** Une même collection
est un journal ou une file d'attente **selon la façon dont l'écran la présente**. Un journal s'annonce
comme un journal : au passé, sans compteur en haut à droite. Une file d'attente s'annonce avec son geste.

**`AlertePresenceIsolee` est le cas dur** : elle porte bien un statut de chose à traiter, et son unique
opération d'écriture en **fabrique de nouvelles**. Rien, nulle part, ne permet d'en clore une. Périmètre
`Sport`, donc `claude-I` depuis D48.

---

### 2026-08-26 · D55-bis — Une demande de remboursement arrive sans que personne puisse y répondre

Neuf opérations sont **appelables** par le client HTTP du front sans qu'aucun écran ne les déclenche.
Deux d'entre elles comptent : `accepterRemboursement` et `refuserRemboursement`.

Le geste existe côté serveur, le client sait le former, **aucun écran ne le propose**. Une demande de
remboursement entre donc dans le produit et **personne ne peut y répondre**. Ce n'est pas une liste
morte : c'est une boîte aux lettres sans porte.

**Et c'est cette découverte qui a corrigé la mesure d'écart elle-même.** `claude-H` comptait comme
« branchées » les opérations que `client.js` sait appeler — donc y compris celles qu'aucun écran ne
déclenche. Elle comptait *ce que le code sait faire* en l'annonçant comme *ce que le produit permet*.
C'est exactement l'angle mort qu'elle signalait aux autres depuis la veille.

L'instrument rend désormais deux nombres — appelées depuis le client, **atteignables depuis un écran** —
et seul le second est reporté. La série publiée ce jour (157, 166, 168, 174) était surestimée d'une
dizaine ; le dernier valait **164**. Corrigé sur le tableau de bord, avec la raison écrite sur la page :
un chiffre publié faux qu'on remplace en silence est pire que le chiffre faux.

Elle avait elle-même créé un de ces orphelins le jour même (`stockValorisation`), et l'a retiré.

---

### 2026-08-26 · D56 — On ne dit plus « poussé ». On dit « sur `main`, commit X » ou « sur ma branche, commit X »

**Trois fois dans la même journée, chez trois sessions différentes**, une livraison annoncée « poussée »
n'était pas sur `main` :

- **moi**, le matin : la sérialisation du bénéficiaire. `claude-H` avait **déjà retiré son contournement**
  quand elle a vérifié ; sans cette vérification, l'écran de location de patins aurait été cassé pendant
  que tout le monde le croyait réparé.
- **`claude-G`** : `POST /ventes/{id}/corriger-reglement`. `claude-H` allait construire son écran dessus.
- **`claude-D`** : FAC-1 — le message du commit disait lui-même `WIP`.

**Dans les trois cas la phrase était vraie du point de vue de qui l'écrivait, et fausse pour qui
construisait dessus.** « Poussé sur ma branche » et « poussé sur `main` » n'engagent pas la même chose.

**La règle, proposée par `claude-H`, adoptée par `claude-G` avant même d'être consignée :**

> On ne dit plus « poussé ». On dit « **sur `main`, commit X** », ou « **sur ma branche, commit X** ».

**L'argument qui la rend obligatoire**, formulé par `claude-G` : *quand la même erreur se produit trois
fois chez trois personnes, ce n'est pas la vigilance qui manque, c'est le vocabulaire qui est ambigu.*
C'est la règle des mécanismes appliquée au langage — **un protocole qui dépend de la précision de chacun
n'est pas un protocole**.

**Le corollaire, et il ne se négocie pas :** `git fetch` avant de construire sur l'annonce de quelqu'un.
Y compris la mienne. Les trois fois, c'est le pair qui a vérifié plutôt que de croire, et les trois fois
il avait raison de le faire.

**Coût mesuré de l'ambiguïté** : plusieurs heures, trois sessions, en une journée. Coût de la règle :
trois mots.

---

### 2026-08-26 · D57 — La clôture journalière NF525 est confondue avec la clôture Z, et ce n'en est pas une

**Découvert en répondant à une question de `claude-G` sur D44-bis** (la vente directe sans session), et
vérifié dans le code avant d'être affirmé.

**Le contexte.** `ValiderVenteService:103` obtient le point de vente par
`$vente->getSession()?->getPointDeVente()` : **la chaîne d'empreintes NF525 est par point de vente**. Une
vente directe n'a pas de session, donc pas de point de vente, donc rien à quoi se chaîner.

**La sortie de secours évidente est la pire, et `claude-G` l'a refusée d'elle-même** : exempter la vente
directe du scellement créerait une **catégorie de ventes non probantes**, ce qui vide la chaîne de sa
valeur pour toutes les autres. Une chaîne dont on peut sortir n'est plus une chaîne. C'est l'argument de
D45 sur la modification, transposé.

**Décidé : un `PointDeVente` dédié par établissement**, créé à la demande, sur lequel les ventes directes
se chaînent. La chaîne reste continue et devient lisible — « les ventes sans caisse de cet
établissement » forment une chaîne identifiable, donc contrôlable.

---

**CE QUE LA QUESTION A RÉVÉLÉ, ET QUI EST PLUS GRAVE QUE LA QUESTION.**

`claude-G` demandait si un point de vente **sans clôture Z** est acceptable, son raisonnement étant :
*sans espèces il n'y a rien à compter.*

Le dépôt distingue bien deux clôtures : `Caisse\ClotureZ` (clôture de session, avec comptage) et
`Compta\ClotureHandler` (clôture d'une `PeriodeComptable` par profil exploitant, **sans dépendance à une
session**). La seconde n'oppose donc rien aux ventes directes.

**Mais `Vente\Enum\TypeOperationScellee` ne connaît que :**

    Vente · Avoir · CorrectionReglement · ClotureZ · ClotureMensuelle · ClotureAnnuelle

**Il n'existe pas de `ClotureJournaliere`.** Dans ce dépôt, **la clôture journalière NF525 *est* le Z** :
les deux ont été confondus parce que jusqu'ici tout passait par une caisse.

Le raisonnement de `claude-G` est donc vrai pour le **comptage** et faux pour la **clôture**. NF525 exige
une clôture journalière, mensuelle et annuelle ; la journalière n'est pas une opération de caisse, c'est
un **arrêté de totaux cumulés**. Un point de vente sans Z serait aujourd'hui un point de vente **sans
clôture quotidienne**.

**Ce n'est pas une nouveauté du modèle que sa question introduisait — c'est un défaut du modèle que sa
question a révélé.** Il ne se serait vu qu'au premier contrôle, ou au premier exploitant vendant sans
caisse.

**Décidé : `ClotureJournaliere` entre dans l'énumération, distincte du Z.** Le point de vente des ventes
directes en produit une, sans comptage. Lot séparé de D44-bis, qui reste livrable sans elle.

**La leçon générale :** deux notions qui coïncident tant qu'un seul cas existe finissent par être
représentées par une seule. Le jour où le second cas arrive, ce n'est pas une extension qu'il faut, c'est
une séparation — et personne ne se souvient qu'il y en avait deux.

---

### 2026-08-26 · D58 — Une référence libre ne se compare ni en DQL ni par filtre (garde-fou n°14)

**La convention.** Le dépôt franchit les frontières de module par un `?Uuid` nu — `billetSupportRef`,
`produitRef`, `creditDroitRef`, `alerteEcartRef`… — plutôt que par une relation Doctrine. C'est
délibéré : une relation créerait une dépendance de mapping entre deux modules qui doivent vivre
séparément. **Vingt-trois propriétés la suivent.**

**Le piège.** Doctrine convertit un type personnalisé quand il connaît la relation. Sur une colonne
`uuid` nue, il ne le fait pas — **et il ne s'en plaint pas** :

| Forme | Symptôme |
|---|---|
| `SearchFilter` sur `?Uuid` | rend une liste **vide** |
| `IN (:liste)` en DQL | ne trouve **rien** |
| comparaison sans type explicite | ne compte **rien** |

**Aucune ne lève.** En production, elles ressemblent exactement à « il n'y a rien ».

**Pourquoi un garde-fou et pas une consigne :** `claude-G` s'est fait avoir **trois fois cette semaine**,
sur trois modules, **en connaissant le piège**. Sa conclusion : *ce n'est plus de la vigilance, c'est une
propriété du terrain.* Règle constante du dépôt — quand la même erreur revient une troisième fois, on ne
la corrige plus, on supprime ce qui la rend possible.

**La règle exacte n'est pas « pas de DQL ».** Le type explicite fait la conversion :
`setParameter('ref', $uuid, 'uuid')` est licite. Pour une **liste**, aucun type scalaire ne s'applique —
`IN` reste toujours fautif, et il faut du SQL avec `UNHEX`.

**Deux défauts dans le garde-fou lui-même, trouvés en le vérifiant :**

1. **Il accusait une requête saine** (`MesFacturesProvider`), qui liait bien son paramètre avec le type.
   D47 : un garde-fou qui accuse à tort est pire que pas de garde-fou.
2. **Il disculpait une requête fautive.** Ma vérification du type cherchait dans **tout le fichier** ;
   un fichier contenant une méthode saine et une méthode fautive **avec le même nom de paramètre** —
   le cas courant, tout le monde appelle son paramètre `:ref` — voyait la fautive disculpée par la saine.
   Le garde-fou aurait été vert sur exactement le défaut qu'il existe pour attraper.

Ni l'un ni l'autre n'aurait été vu sans écrire **le cas sain et le cas fautif dans le même fichier
d'essai**. Vérifier séparément aurait donné deux verts trompeurs.

**Le vert local doit rester le vert distant.** Le garde-fou avait été branché dans `hooks/pre-receive` et
**pas** dans `bin/garde-fous.sh` — mon omission, découverte par `claude-D` dont la poussée a été refusée
après un vert local. C'est le pire écart possible : une session se croit prête, se fait refuser, perd une
fusion. Le filet de complétude du lanceur l'a signalé lui-même.

---

### 2026-08-26 · D59 — Un cycle qui s'auto-annule ressemble à du travail

**Trouvé par `claude-D` en écrivant la commande de préavis SEPA**, et c'est la trouvaille la plus fine de
la semaine.

`announce()` remet `sentAt` à l'instant courant — voulu : un montant qui change doit rendre au client la
totalité de son délai légal. **Mais une commande quotidienne qui réannonce tout repousse `sentAt` chaque
jour, donc plus aucune échéance n'atteint jamais les quatorze jours requis.**

Le mécanisme se neutralise **en tournant**. Tout s'exécute, rien ne casse, la commande annonce chaque
matin « 47 préavis envoyés », et **aucun prélèvement n'aboutit jamais**.

**Sa formule, à retenir : *l'absence finit par se voir, un cycle qui s'auto-annule ressemble à du
travail.***

C'est un cran au-delà du motif qu'on répétait depuis trois jours. *Le mécanisme existe, l'appel manque*
décrit un mécanisme **silencieux** — on finit par remarquer qu'il ne s'est rien passé. Ici le mécanisme
**s'exécute, produit des traces, remplit des compteurs, et se dévore lui-même**. Aucun des quatorze
garde-fous ne le verrait ; aucun relevé non plus, puisque le compteur monte.

**Le correctif : `alreadyAnnounced()` ne réannonce que ce qui a changé de montant.** Et `claude-D` a
raison de dire que **c'est ce test-là qui est le centre du lot, pas la présence de la commande** —
n'importe qui aurait écrit la commande ; ce qui la rend utile, c'est ce qui l'empêche de se dévorer.

**Corollaire du même lot :** la commande interroge **la même source** que la collecte relira. Une source
différente annoncerait des échéances qui ne sont pas celles qu'on prélèvera, et `covers()` reconnaîtrait
*une annonce qui ressemble à la bonne sans en être une*. Faute invisible : tout serait vert.

**Ce qu'on en tire pour les livraisons partielles :** une moitié livrée ne doit jamais pouvoir ressembler
à quelque chose qui fonctionne. Un service dont rien n'appelle le déclencheur doit **le dire** — par un
test qui échoue avec le nom de ce qui manque, ou par une déclaration qui refuse de tourner. Sans quoi
quelqu'un croira la fonctionnalité terminée, et il aura toutes les raisons de le croire.

---

### 2026-08-26 · D60 — Vérifier les usages n'est pas vérifier les garanties

**Déclaré par `claude-G` sur son propre commit déjà fusionné**, plutôt que corrigé en silence — et c'est
ce geste qui donne à la leçon sa valeur : j'avais relu ce commit en le fusionnant et **je n'avais pas vu
le trou**.

**Le cas.** D44-bis a déplacé l'invariant : `Vente.session` était `NOT NULL`, elle est devenue
`Vente.pointDeVente`. L'invariant est plus fort — c'est le point de vente dont la chaîne NF525 a
réellement besoin. Mais `Nf525\InalterabiliteListener::CHAMPS_VENTE_FIGES` fige `session` et **pas**
`pointDeVente`.

Avant, `pointDeVente` était protégé **par ricochet** : il découlait de la session, qui était figée.
Le ricochet a été coupé, la protection ne l'a pas suivi. Rien ne s'est rompu, aucun test n'a rougi.

**Conséquence :** on peut aujourd'hui changer le point de vente d'une vente **scellée** — donc la faire
disparaître d'un arrêté de totaux et apparaître dans un autre, sans qu'aucun contrôle ne parle.

**La règle, dans ses mots :**

> Quand une propriété passe de « déduite » à « portée », tout ce qui la protégeait par déduction cesse
> de la protéger — et rien ne le signale, puisque aucun de ces contrôles ne se rompt.

**Et son corollaire, qui est le geste manquant :** `claude-G` avait cherché `getSession()` dans tout
`src/` avant de commiter, et vérifié que tout était null-safe. C'était le bon réflexe et il était
insuffisant : le danger n'était pas chez ceux qui **lisaient** `session`, mais chez ceux qui
**s'appuyaient dessus pour protéger autre chose**.

**Vérifier les usages n'est pas vérifier les garanties.**

C'est D57 d'un cran plus loin : là, deux notions confondues devaient être séparées ; ici, une notion
séparée emporte avec elle des protections que personne n'avait déclarées.

**Le correctif structurel, pas la ligne.** `CHAMPS_VENTE_FIGES` est une liste écrite à la main sur une
entité que huit sessions modifient : elle dépend de la vigilance de qui ajoute un champ. Elle devient un
**test** dans `Vente` — pas un garde-fou dans `bin/` — qui confronte les colonnes réellement mappées à
la liste, avec les exceptions explicites et leur raison. La question « ce champ est-il fiscal ? » se pose
dans le module qui connaît la réponse, et le test grandit avec l'entité sans que personne n'y pense.

---

### 2026-08-26 · D60-bis — Huit sessions commitaient sans aucun contrôle

En recréant le worktree de `claude-G` — le seul rattaché au **dépôt nu** au lieu du clone
d'intégration —, j'ai découvert que son `pre-commit` venait de ce rattachement, et que **les worktrees
du clone n'en avaient aucun**.

Autrement dit : **huit sessions sur neuf commitaient sans le moindre contrôle**, couvertes uniquement
par `pre-receive` à la poussée. Les hooks étaient pourtant versionnés dans `hooks/` depuis le début ;
personne ne les avait installés dans le clone.

Installé pour tous. `claude-G` récupère ce qu'elle perdait au passage, et les huit autres l'ont pour la
première fois.

**Ce que ça dit :** elle signalait sa topologie cassée depuis des jours, poliment, sans insister. Le
symptôme était pour elle ; **la cause était pour tout le monde.** Un défaut qui ne gêne qu'une personne
est un défaut qu'on repousse — et c'est exactement celui qu'il faut regarder, parce que personne d'autre
ne le regardera.

**Et j'ai refusé de faire taire le garde-fou de topologie** en ajoutant un remote de façade, ce qui
aurait rendu le contrôle vert sans rien réparer — sur le contrôle dont le rôle est précisément de
détecter cette configuration (D53).

---

### 2026-08-26 · D61 — Un ticket qui n'existe que dans l'onglet du caissier n'est probant pour personne

**Trouvé en creusant une capture d'écran de Maxime**, qui signalait un ticket ne s'additionnant pas :
`1 × Test 10,00 €` pour un total de `15,00 €`.

**La base était juste** — quantité 1, prix unitaire 15,00, aucune remise. **L'écran mentait** : il
construisait le ticket depuis le panier local, avec repli sur le prix **indicatif du catalogue** quand la
ligne n'en portait pas. Le serveur avait appliqué une grille tarifaire ; l'écran affichait le prix de la
vignette.

**Le correctif n'était pas de mieux deviner, c'était d'arrêter de deviner** : le ticket se construit
depuis la vente validée. Le panier local est ce que l'utilisateur a **demandé** ; la vente validée est ce
qui a été **facturé**. Sur un ticket, seul le second a le droit de s'afficher.

**Mais la vérification a trouvé plus grave que le signalement.** `TicketProcessor` ne renvoie **aucune
ligne** : ni libellé, ni quantité, ni montant — seulement `numero`, `imprime`, `duplicata`,
`renvoiPropose`. Et `LigneVente` sérialise `produit` et `typeTarif` en `Uuid` nus : **tout l'argent y
est, aucun mot.**

**Conséquence : un ticket ne peut pas être réimprimé.** L'opération accepte pourtant
`mode: "duplicata"`. Le document n'existe que dans l'onglet ouvert du caissier ; il ferme la page, la
pièce n'est plus reconstituable. Chaque vente encaissée avant le correctif est **définitivement
irréproductible**.

**Et c'est une affaire de conformité, pas de confort.** `claude-G` l'a cadré ainsi : tout le module repose
sur l'idée qu'une vente validée est **probante** — c'est l'argument de D45 contre la modification d'un
règlement, et celui opposé à l'exemption de scellement de la vente directe. *Nous aurions une chaîne
d'empreintes irréprochable qui scelle des documents qu'on ne sait pas réémettre.*

C'est D59 sous une autre forme : **un mécanisme qui produit toutes les traces d'un fonctionnement
correct, et qui ne fait pas la chose.** Les empreintes sont bonnes, les totaux sont bons, les refus de
modification sont bons — et il n'y a rien à produire le jour où on demande la pièce.

**Décidé :** le libellé est **figé sur la ligne au moment de la vente**, pas relu du produit. Un produit
renommé six mois plus tard ne doit pas changer ce qu'un ticket d'hier affirme — même règle que le prix
unitaire, déjà stocké et jamais recalculé.

⚠ **Cette copie sera prise pour une redondance et quelqu'un proposera de la supprimer.** La raison va
dans le docblock de la propriété, pas dans cette décision — et un test la protège : renommer le produit,
relire une vente ancienne, vérifier que le libellé n'a pas bougé. **Un test qui échoue est plus difficile
à supprimer qu'un commentaire.**

---

### 2026-08-26 · D62 — Un signal qui ne ressemble pas à un signal, une image qui ressemble à ce qu'elle n'est pas

**Deux fautes corrigées le même jour par `claude-H`, sur deux écrans sans rapport**, et elle a vu qu'elles
étaient symétriques :

- **le matin** : un bandeau d'avertissement **sans couleur d'avertissement** — un signal réel qui ne
  ressemble pas à un signal, donc que personne ne lit ;
- **le soir** : un carré ressemblant à un QR affiché **à côté du texte « aucun support QR sur cette
  vente »**. Le composant était rendu sans condition, y compris quand il n'y avait rien à encoder.

**Le second est le pire des deux.** L'agent présente le QR au lecteur, ça ne marche pas, et il conclut
que **le lecteur est en panne** — pas qu'il n'y avait rien à scanner. Il cherchera au mauvais endroit,
avec de bonnes raisons. **Un faux signal coûte plus cher qu'un signal absent : l'absence fait chercher,
la fausse présence fait chercher ailleurs.**

Deux fois dans la journée, chez la même personne, sur deux écrans sans rapport : ce n'est pas une
inattention, c'est une classe de défaut qui n'avait pas de nom.

---

### 2026-08-26 · D63 — Un coût qui grossit passe devant un coût qui attend

**Question de `claude-G`** : le duplicata (une heure, conformité) passe-t-il avant PAY-3 (une demi-journée,
qui fait attendre `claude-D` depuis le matin) ?

Son argument était le mien de la veille — *une heure qui ferme un défaut de conformité passe avant une
demi-journée qui ferme un coût d'organisation*. Vrai, et ce n'est pas le critère décisif.

**Le vrai départ : l'un des deux coûts grossit, l'autre non.**

L'attente de `claude-D` est **fixe** : trois heures de plus ne l'aggravent pas, et elle n'est pas à
l'arrêt. Le duplicata **accumule** : chaque vente encaissée sans libellé figé devient définitivement
irréproductible. Ce n'est pas une dette qu'on rembourse plus tard, c'est une perte sèche, ligne par ligne.

**Un coût qui grossit passe devant un coût qui attend.**

---

### 2026-08-26 · D63-bis — Le remède ne doit pas avoir la forme de la maladie

**Formulé par `claude-G`** en posant à `claude-H` une contrainte qu'elle n'avait pas demandée : l'estimation
de tarif du panier appellera **le calculateur qui facture**, pas une copie de ses règles.

Une seconde implémentation reproduirait le défaut du jour — un prix annoncé différent du prix facturé —
avec **deux couches serveur** au lieu d'une couche serveur et une couche écran. Et cette fois personne ne
verrait la divergence, puisque les deux seraient « côté serveur ».

**Deux fois le même jour, la bonne réponse a été : un seul calcul, deux appelants.** `claude-D` l'avait
appliquée à la simulation de clôture, en faisant rendre les montants par le même code que la clôture
elle-même — *un calcul parallèle aurait divergé, et la divergence se serait découverte sur un arrêté
fiscal.*

---

### 2026-08-26 · D64 — Un événement ne peut pas être la seule trace d'un fait d'exploitation

**Question de `claude-G` sur PAY-3.** Le refus de carte doit partir quand le terminal refuse — à un endroit
où la vente n'est pas validée, où aucune transaction n'est ouverte, et où **rien n'est écrit en base** :
la méthode rend `['paiement' => null, 'statutTPE' => …]`. Publier l'événement immédiatement est plus
simple. Fallait-il persister le refus d'abord ?

**Oui, et l'argument général ne suffisait pas.** « Si l'abonné échoue, le refus n'a jamais existé » est
vrai partout et se discute. Ce qui tranche, c'est le cas d'espèce : `claude-D` a établi que la bascule
carte → prélèvement **n'a aucun client** aujourd'hui, aucun débit carte récurrent n'existant dans le
produit. Son abonné est le **seul** consommateur, et il ne fera rien de la totalité des refus.

**Publier sans persister ne laisserait donc littéralement aucune trace de la totalité des refus de
carte** — pas « en cas de panne », mais dans le fonctionnement normal, dès le premier jour. C'est D59
connu **avant** livraison : un mécanisme qui s'exécute, produit un événement, et ne fait rien.

**Et un refus de carte n'est pas une notification, c'est un fait d'exploitation.** Un exploitant voudra
les compter — combien ce mois-ci, sur quel terminal. Un client contestera un prélèvement en disant « ma
carte n'a jamais été refusée ». Ni l'une ni l'autre de ces questions n'a de réponse si le fait ne vit que
dans un message.

**Décidé : le refus est persisté, et l'événement référence la trace au lieu de la porter.** Deux gardes :
persister **avant** de publier — sinon l'événement référence ce qui n'existe pas encore — et faire porter
à la trace de quoi être comptée sans jointure : établissement, terminal, montant, horodatage.

**Règle générale :** un événement transporte, il ne conserve pas. Quand un fait doit pouvoir être compté,
contesté ou audité, il lui faut une trace qui ne dépend d'aucun abonné.

---

### 2026-08-26 · D64-bis — « Le risque est faible » n'est pas un argument, c'est une permission

**Déclaré par `claude-G` sur elle-même.** Elle a ajouté un fichier de test **pendant qu'une suite
tournait** — la règle qu'elle avait elle-même fait adopter à la flotte le 24/08, après avoir rendu deux
verdicts sur un arbre qui avait bougé. Son raisonnement : *PHPUnit fige sa liste de fichiers au
démarrage, le risque est faible.*

**La suite a rendu 82/82. Rejouée sur arbre figé : 86 tests, deux erreurs.**

Les quatre tests ajoutés n'avaient pas été collectés — et ils étaient **réellement cassés** : une session
de caisse mise en cache dans une variable `static`, qui survit d'une méthode de test à l'autre alors que
le harnais recrée le schéma à chaque méthode. La session mémorisée désignait une ligne disparue.

**Sans le rejeu, elle poussait un fichier de test que rien n'avait exécuté, en annonçant vert.** Le motif
du jour dans sa forme la plus pure : un résultat plausible, aucune erreur, et une vérification qui n'a
pas eu lieu.

**Sa formulation, qui vaut mieux que la règle :**

> « Le risque est faible » est l'argument que je refuse quand un autre me le sert. Il ne devient pas bon
> parce que c'est moi qui le formule.

C'est la version personnelle de la règle des mécanismes : **un raisonnement qui ne vaut que quand c'est
soi qui le tient n'est pas un raisonnement, c'est une permission.**

**Fait technique à retenir par tout le monde :** PHPUnit fige sa liste de fichiers au démarrage. Un test
ajouté pendant une exécution n'est pas collecté, et la suite rend un vert qui ne le concerne pas.

---

### 2026-08-26 · D65 — Un contrôle dit quoi faire dans les deux cas, ou n'ordonne rien

**Relevé par `claude-G` après un faux positif dont le remède affiché aurait fait exactement le dommage
que le contrôle existe pour empêcher.**

`CardDebitFallbackNonBrancheTest` est une sentinelle de `claude-D` : elle existe pour **échouer le jour
où PAY-3 atterrit**, et son message ordonne *« À FAIRE : supprimez ce fichier de test. »*

Elle a échoué — sur **un commentaire**. Le manifeste de `claude-G` nommait le consommateur par sa classe,
la sentinelle cherchait cette chaîne dans `src/`, et son service n'était **toujours appelé par personne**.

**Suivre le remède aurait retiré le signal en laissant le trou**, au moment précis où il devenait le plus
utile : quelqu'un lit la mention dans le manifeste, croit la chaîne complète, et plus rien ne le
détrompe.

**La conclusion de `claude-D`, tirée seule :** *une sentinelle dont le remède est faux dans le cas du
faux positif est pire qu'aucune sentinelle.*

**La règle générale, formulée par `claude-G` :** nous avons quatorze garde-fous plus des sentinelles.
Aucune décision n'exigeait jusqu'ici que **le remède affiché** soit vérifié aussi soigneusement que la
détection. Celui-là était faux dans un cas sur deux — et impératif.

> **Un contrôle doit dire quoi faire dans les deux cas — le vrai positif et le faux — ou ne rien
> ordonner du tout.**

C'est le complément de D53. D53 dit qu'un message ne met pas en avant son propre contournement ; D65 dit
qu'un message qui **ordonne** doit avoir raison dans les deux branches, et que la seconde est celle où
l'erreur coûte. Un impératif appliqué au mauvais cas ne se discute pas : il s'exécute.

**Corrections faites :** `claude-D` a durci la détection (`token_get_all`, commentaires écartés) et
réécrit le message avec ses deux branches. `claude-G` a retiré le nom de classe de son manifeste — **un
manifeste ne nomme pas la classe interne d'un autre module** (couplage documentaire, D2 ; et le nom peut
changer sans que l'événement bouge).

---

### 2026-08-26 · D65-bis — La trace d'un refus de carte est une pièce justificative, pas un confort

J'avais demandé la trace persistée (D64) pour une raison d'exploitation : compter les refus, répondre à
un client qui conteste.

**`claude-D` a trouvé la raison décisive, que ni `claude-G` ni moi n'avions vue : le prélèvement qu'un
client contestera, c'est celui que la bascule aura créé à partir de ce refus.** Ce qu'on peut produire
pour le défendre est un préavis — il prouve qu'on a **prévenu**, il ne prouve pas **pourquoi on a
prélevé**.

Sans cette ligne, **le fait générateur n'existe nulle part**, et on aurait prélevé quelqu'un sur la foi
d'un message disparu après traitement. `sale_card_rejection` est donc la pièce justificative d'un
prélèvement SEPA. C'est écrit dans le docblock de l'entité, pour que celui qui voudra la supprimer voie
ce qu'il enlève.

**Et un écart au contrat, assumé :** le champ s'appelle `rejectionId`, pas `paymentId`. Le second
n'existe pas — CA-10 veut qu'un refus ne crée aucun `Paiement`. Passer l'identifiant d'autre chose sous
ce nom aurait donné la clé d'idempotence attendue, **et le nom aurait menti**.

> Un `paymentId` qui ne désigne aucun paiement fonctionne parfaitement jusqu'au jour où quelqu'un fait
> une jointure dessus — et ce jour-là il ne cherche pas un problème de nommage, il cherche pourquoi sa
> requête ne rend rien. — `claude-G`

**Manque de modèle noté, pas inventé :** le dépôt ne donne aucune identité propre aux terminaux de
paiement. `PointDeVente` porte une *liste* de terminaux en configuration, et `ResultatTpe` ne dit pas
lequel a répondu. La trace porte donc le point de vente. Distinguer deux terminaux d'un même comptoir est
un chantier séparé.

---

### 2026-08-26 · D51-ter — Le patron « socle + ajout local » existait déjà, et ma consigne en créait un second

**Correction de D51, sur pièce.** J'avais arbitré la forme : `etablissement` nullable, `null` = socle
partagé, pas de table d'extension. `claude-G` a fait ce que D51 exigeait — *vérifier d'abord qu'un patron
n'existe pas ailleurs, en inventer un second serait pire que le problème* — et **elle en a trouvé un,
complet, fusionné, avec son extension Doctrine.**

`Support` porte déjà exactement ça, et le discriminant n'est **pas** `null` :

```php
#[ORM\Column(length: 6, enumType: PorteeArticle::class, options: ['default' => 'global'])]
private PorteeArticle $portee = PorteeArticle::Global;   // Global | Local
#[ORM\JoinColumn(nullable: true)]
private ?Etablissement $etablissement = null;
```

Et son extension applique déjà « le socle **plus** ses ajouts, jamais ceux d'un autre », avec `IDENTITY()`
— donc conforme à D58 avant que D58 n'existe.

**Ma consigne aurait donc créé le second patron que D51 interdit**, sur la décision qui interdit
justement ça.

---

**ET LE DISCRIMINANT EXPLICITE EST MEILLEUR QUE LE NULL, POUR UNE RAISON QUI N'EST PAS DE STYLE.**

Argument de `claude-G`, et il est décisif : **`null` sur `etablissement` porte deux sens différents** —
« cette ligne appartient au socle » et « personne n'a encore renseigné l'établissement ».

Le second arrive tout seul : un import, un processeur qui oublie l'estampille, une migration qui ajoute
la colonne. Et alors **une ligne locale mal remplie devient du socle** — donc visible par tous les
établissements, **silencieusement**.

Avec `portee`, le même oubli produit une ligne `local` sans établissement : **invisible partout**, ce qui
se remarque et se corrige.

> **Le défaut par défaut ne fuit pas.**

C'est le même critère que la clôture journalière le matin même — *le défaut est celui qui ne peut pas
mentir* — appliqué à une frontière de cloisonnement au lieu d'une date.

**Décidé : D51 s'aligne sur le patron de `Support`.** Le trait porteur (`portee` + `etablissement`) et le
fragment de requête réutilisable montent dans `Platform`, **une seule fois**, pour que le troisième
module ne le réinvente pas une troisième fois.

**Ce que je ne fais pas :** convertir `Support` au nullable pur. Ce serait remplacer le bon patron par le
moins bon pour satisfaire une consigne écrite trop vite.

---

### 2026-08-26 · D51-quater — Chercher la fonctionnalité, pas le numéro de décision

`claude-G` allait reconstruire les trois documents de D45-bis — devis, bon de commande, bon de livraison.
**`claude-D` les avait livrés le matin même**, sous le nom FAC-1 : `Facturation\Entity\CommercialDocument`,
`DocumentNature::Quote | SalesOrder | DeliveryNote`, huit opérations, la filiation complète.

Une demi-journée en double, évitée à la lecture.

**Et la règle existante ne suffisait pas.** « Fusionner `main` avant de claimer » est en place depuis
lundi, et `main` **était** à jour chez elle. Le travail était simplement arrivé **sous un autre nom que
celui de la décision** — D45-bis livré comme FAC-1.

> **Avant de proposer un lot, chercher la fonctionnalité dans le code, pas le numéro de décision dans le
> tableau.** — `claude-G`

Un identifiant de décision ne survit pas au passage à l'implémentation : celui qui livre nomme son lot
d'après ce qu'il construit, pas d'après ce qui l'a demandé. Le tableau de claim voit donc D45-bis
« libre » alors que la fonctionnalité existe.

---

### 2026-08-26 · D51-quinquies — Le quotient familial ne s'affiche pas au guichet

`claude-H` demandait un paramètre `beneficiaire` dans l'estimation de tarif. `claude-G` l'a refusé après
vérification : **il n'entre dans aucune règle de tarif du dépôt**. Ce qui fait varier le prix est le
**quotient familial**, déjà fourni par l'appelant.

*L'accepter pour l'ignorer aurait été pire que de ne pas l'accepter : l'écran aurait cru le prix
contextualisé, et le jour où deux bénéficiaires d'un même dossier ont des quotients différents, il
afficherait deux fois le même prix sans que personne sache pourquoi.* **Un paramètre ignoré est un
mensonge de signature.**

Reste la question qu'elle a fait remonter : si l'écran connaît le bénéficiaire mais pas son quotient, il
manque une résolution `bénéficiaire → quotient familial`, qui vit dans `Crm`.

**Non ouverte, et ce n'est pas une frontière de module.** Lire le quotient familial d'un bénéficiaire
depuis l'écran de caisse revient à **l'afficher au guichet, devant l'intéressé et devant les autres**.
C'est une question de dignité, pas d'architecture, et elle se tranche avec Maxime — pas entre nous, et
pas parce que ce serait techniquement commode.

---

### 2026-08-26 · D66 — Le joker d'un module ne peut pas contenir un droit qui dépasse ce module

**Trouvé par le test, pas par la relecture.** `claude-G` avait nommé `offre.gerer_socle` le droit
d'éditer le socle partagé des référentiels. Son test `testUnAjoutNaitLocalEtRattache` a échoué pour une
raison qu'aucune relecture n'aurait donnée :

**l'administrateur de groupe porte la permission joker `offre.*`, donc `offre.gerer_socle` lui était
accordé automatiquement.**

Conséquence : **chaque administrateur d'établissement devenait maître du socle commun**, sans que
personne ne l'ait décidé. Renommer un `TypeTarif` chez A aurait changé le tarif de B — le trou
transfrontière contre lequel D51 met en garde, ouvert par un **nom**.

**La règle :**

> Le joker d'un module ne doit jamais pouvoir contenir un droit qui dépasse ce module.

**Le nommage d'une permission n'est pas une convention d'affichage, c'est une frontière d'autorité.**
`offre.*` désigne « tout sur l'offre **de cet établissement** », pas « tout sur l'offre **de tout le
monde** ». Un droit qui porte sur le partagé appartient donc à un autre module — ici
`plateforme.gerer_socle`.

**Corollaire, et il fait le lien avec le défaut du menu de mardi :** un joker accorde du droit sur ce qui
n'existe pas encore. C'est sa propriété, et elle est utile — mais elle rend le nommage définitif : **on
ne peut pas ajouter une permission sous un préfixe existant sans la donner rétroactivement à tous ceux
qui portent le joker.** Personne ne relira les rôles pour vérifier.

**Conséquence assumée : personne ne détient `plateforme.gerer_socle` aujourd'hui.** Le socle est semé
par les jeux de données et les migrations. Le jour où la plateforme voudra l'éditer par l'API, il faudra
décider **à qui** on le donne — et ce n'est pas une décision qui se prend par défaut, en héritant d'un
joker.

---

### 2026-08-26 · D66-bis — Le critère de visibilité ne se partage pas avec le patron

`claude-G` avait repris tel quel le filtre de `Support` : « les établissements où l'utilisateur a une
affectation ». Son test a montré que l'administrateur, affecté à **A et B**, voyait les ajouts de B
**depuis le guichet de A**.

**Ce n'est pas un défaut du patron de `Support` — les deux cas ne sont pas les mêmes.** Un article d'aide
se lit légitimement depuis n'importe lequel de ses établissements. Un référentiel tarifaire, non : un
responsable qui vend au guichet de A ne doit pas voir les types de tarif de B, **il pourrait poser un
prix sur un tarif qui n'existe pas là où il encaisse, et le défaut ne se verrait qu'à la facture.**

La lecture porte donc sur **l'établissement actif**, et sans établissement actif : **le socle seul**,
fermeture par défaut.

**Ce que ça nuance dans la consigne « monte le patron dans `Platform` » :** le **trait** et la **forme**
du filtre se partagent ; **le critère de visibilité ne se partage pas** — il dépend de ce que la liste
sert à faire. Un patron qui imposerait son critère ferait porter à chaque module la question à laquelle
un seul avait répondu.

---

### 2026-08-26 · D66-ter — Une migration ne fabrique pas de donnée pour sauver une démonstration

**Question de `claude-G` sur `Saison` et `TrancheQuotientFamilial`**, à cloisonner et non à doter d'un
socle : **à quel établissement appartiennent les lignes existantes ?** Elles sont globales aujourd'hui.

Deux options proposées : rattacher au hasard à l'établissement de démonstration, ou laisser les lignes
orphelines et **visibles de personne**.

**Décidé : la migration laisse orphelin.** Une migration s'exécutera un jour sur des données réelles, et
rattacher au hasard produirait des tarifs calculés sur la saison d'un autre établissement — le quotient
familial étant le cas où D51 dit lui-même qu'une erreur est **opposable**.

**La démonstration se répare à la main, là où la donnée est admise comme fausse.** La préproduction ne
contient que des données de test ; l'y remettre en état est une opération d'exploitation, pas un `up()`.
Confondre les deux revient à inscrire dans le code de production une réparation qui n'a de sens que sur
un jeu d'essai.

> **Une migration ne fabrique jamais de donnée métier. Ce qui manque reste visiblement manquant.**

---

### 2026-08-26 · D67 — Un oracle de test est la seule exception à « un seul calcul »

**La règle standing :** un calcul existe une fois, plusieurs appelants — parce que deux implémentations
en **production** divergent en silence et que **les deux sont crues**. Appliquée quatre fois ce jour :
simulation de clôture, estimation de tarif, prix des options, jauge en lot.

**L'exception, demandée par `claude-G` et validée :** un **calcul de référence écrit dans un test** n'est
pas de cette nature.

- **Personne ne le croit** — sa seule sortie est une comparaison, jamais une donnée métier.
- **Sa divergence est le signal**, pas un défaut à corriger.
- **Il ne sert aucun appelant**, donc il ne peut pas devenir la version que quelqu'un utilise par erreur.

C'est un **oracle**, et c'est la seule façon de tester un calcul dont on ne peut pas écrire le résultat à
la main.

**Pourquoi il en fallait un ici.** J'avais demandé un test comparant la jauge en lot au chemin unitaire.
`claude-G` a vu ce que je n'avais pas vu : **`placesOccupees()` délègue désormais au lot**, donc le test
aurait comparé une fonction à elle-même — vert, et ne prouvant rien. *« Exactement le défaut que le test
est censé empêcher, retourné. »*

**⚠ SON SEUL MODE D'ÉCHEC, ET IL EST DÉCISIF : écrire l'oracle depuis le CODE plutôt que depuis la
RÈGLE.**

Ouvrir la requête de production et la ré-exprimer en PHP transcrit **aussi son défaut**. Les deux
s'accordent, le test est vert, et **il confirme l'erreur au lieu de l'attraper**. C'est le piège
classique de cette forme, d'autant plus facile que le code est sous les yeux.

L'oracle s'écrit depuis la spécification. S'il diverge, c'est une information **dans les deux sens** —
y compris quand c'est lui qui a tort.

**Condition de forme, de `claude-G` :** lent, naïf, sans SQL, sans optimisation. **Sa justesse doit se
lire d'un coup d'œil**, sinon on a deux choses à déboguer au lieu d'une.

---

### 2026-08-26 · D67-bis — Croire qu'on applique une règle parce qu'on vient de l'écrire

**Déclaré par `claude-G` sur elle-même, quatrième fois de la journée.**

Elle a écrit ce commentaire, avec la conséquence exacte :

> D58 — les identifiants et non les entités. Sur une relation à identifiant `Uuid`, un `IN` d'entités ne
> trouve rien et **ne lève pas** : la jauge rendrait zéro partout, donc « tout est libre » sur un
> calendrier complet.

**Et elle a commis la faute sur la ligne suivante.** D58 est pourtant sans nuance : pour une **liste**,
aucun type scalaire ne s'applique — `IN` reste toujours fautif, il faut du SQL avec `UNHEX`. Passer
`getId()` au lieu d'entités lui a suffi à croire la règle appliquée.

La jauge rendait **zéro partout**. Un écran de calendrier aurait laissé réserver un créneau plein.
Quatre tests l'ont attrapée ; le commentaire, non.

**Sa formulation, qui vaut mieux que le constat :**

> La constante n'est pas l'ignorance de la règle : c'est de croire qu'on l'applique parce qu'on vient de
> l'écrire.

**Quatre occurrences le même jour, chez la même personne, toutes déclarées :** le raccourci des chèques
documenté au-dessus de lui-même ; `promoEligible` failli « corrigée » en la déplaçant ; le `static` du
test de clôture, optimisation raisonnable ; et celle-ci.

**Ce n'est pas de la négligence, c'est le geste qui ressemble le plus à la conformité qui est le plus
dangereux** — pas celui qui l'ignore. Un commentaire ne protège pas ; seul un test protège.

---

### 2026-08-26 · D67-ter — Sixième forme du piège des identifiants : `IN` sur l'identifiant d'une entité jointe

Le garde-fou n°14 surveille les propriétés `*Ref` et les relations déclarées. Il **ne voit pas**
`IN (:liste)` posé sur `cs.id` — l'identifiant d'une **entité jointe**, qui n'est ni l'une ni l'autre.

C'est la sixième forme du même piège, et elle vient de coûter un faux « tout est libre ». À élargir,
avec le cas de reproduction de `claude-G` — et **vu refuser avant d'être livré**, pas seulement vert sur
le correctif.

---

### 2026-08-30 · D68 — La capacité d'un événement vit sur l'événement, jamais sur le produit

Tranché par **Maxime**, en réponse au constat que deux produits de préprod portaient un stock que
leur type ne déclarait pas.

Le concert du 12 mars a 200 places. Plein tarif, tarif réduit et scolaire sont **trois produits** qui
vendent dessus, et tous décomptent le **même compteur**.

**Raison :** c'est le seul modèle où vendre 150 pleins et 60 réduits ne met pas 210 personnes dans
une salle de 200. Si la capacité vivait sur le produit, chaque tarif aurait son compteur et rien ne
s'opposerait au dépassement — un défaut qui ne se voit qu'à la porte, le soir, devant les clients.

**Conséquence immédiate :** un `stock` sur une entrée unitaire est une **erreur de modèle**, pas un
besoin à accueillir. `ProduitProcessor` ne le purge plus en silence, il le **refuse en 422** et dit
où poser la donnée. Voir D69.

**Ce que la décision ne dit pas encore :** les sous-quotas par produit (« au plus 50 places en tarif
réduit ») ont été écartés pour l'instant. Maxime a choisi le compteur unique ; si la billetterie de
spectacle les réclame, ils s'ajoutent **sous** l'événement, jamais sur le produit.

### 2026-08-30 · D69 — Une écriture qui contredit le type est refusée ; ce qui existe n'est jamais détruit

Jusqu'au 30/08, `ProduitProcessor` appelait `purgerOrphelins()` à **chaque** enregistrement :
modifier la **couleur de caisse** d'un produit lui faisait perdre son stock. Réponse 200, aucun
message, aucune trace, et à l'écran la cause et l'effet n'ont aucun rapport.

Désormais, deux moitiés :

1. Une saisie contradictoire est **refusée en 422**, avec un message qui nomme le type *et* la
   destination — la boutique pour un stock de marchandise, l'événement pour une jauge.
2. Une donnée contradictoire **déjà en base est laissée en place**, et le produit reste modifiable.

**Raison de la seconde moitié, qui est celle qu'on oublie :** un refus total serait pire que le
défaut d'origine. Le silence détruisait une donnée ; un refus sans discernement **bloquerait le
produit** — on ne pourrait plus corriger son libellé tant que personne n'aurait réparé la donnée par
un autre chemin. On compare donc à l'instantané Doctrine et on ne refuse que ce qui vient d'être
écrit.

La purge subsiste pour le seul endroit où elle a du sens : la **conversion assistée de type**, où
l'exploitant a demandé le changement et où l'écran lui annonce ce qu'il perd.

⚠ **Cette décision n'était pas prenable avant D68.** Refuser un stock sur une entrée aurait rendu
« Place limitée, 200 places » inexprimable. C'est la réponse de Maxime sur *où vit une jauge* qui a
rendu le refus sans conséquence — et c'est la raison pour laquelle le défaut est resté ouvert un
jour de plus au lieu d'être corrigé de travers.

### 2026-08-30 · D70 — Carte cadeau et porte-monnaie virtuel sont deux objets distincts

Tranché par **Maxime**.

**Raison :** une carte cadeau s'achète **pour quelqu'un d'autre**, se transmet, et a un porteur
inconnu au moment de l'émission. Un PMV est **nominatif**, attaché à un client identifié. Les
confondre rendrait impossible d'offrir une carte — et le choix est **irréversible une fois des cartes
vendues**, ce qui l'a placé en deuxième position par coût d'erreur.

**État mesuré au moment de la décision :** le PMV existe et est câblé sur son vrai adaptateur
(`PorteMonnaieVirtuelAdapter`, pas le stub). Ses trois verbes sont `solde`, `debiter`, `recrediter`
— et `recrediter` est le **remboursement d'une vente annulée**, pas l'achat d'un avoir. Il manque
donc un **crédit à la vente** des deux côtés : pour recharger un PMV, et pour émettre une carte
cadeau.

### 2026-08-30 · D71 — Le créneau est un type de produit, et l'agenda échange dans les deux sens

Tranché par **Maxime** : « oui, et n'oublie pas qu'il peut aussi y avoir un lien avec l'agenda et
d'autres modules ».

Un créneau obtient sa grille tarifaire, sa TVA, sa catégorie comptable et son billet **comme
n'importe quel produit**. Une seule façon de vendre dans tout le logiciel.

**Raison :** l'alternative — une réservation vendable hors du catalogue — obligeait à dupliquer la
tarification et la comptabilité, et *une règle recopiée diverge au premier correctif*.

**L'agenda circule dans les deux sens :** on peut poser une séance depuis l'agenda ou depuis le
catalogue, et les deux se reflètent. ⚠ C'est le choix le plus confortable à l'usage et **le plus
exigeant** : deux écritures sur un même objet. Il faudra une seule source de vérité pour la séance,
et deux écrans qui écrivent dedans — jamais deux modèles qui se synchronisent.

### 2026-08-30 · D72 — Événement et créneau restent deux objets, mais partagent le mécanisme de capacité

Tranché par **Maxime**, **contre** la recommandation qui proposait de les fondre.

Un événement est **ponctuel et communiqué** — affiche, programme, plan de salle. Un créneau est
**récurrent et opérationnel** — le cours du lundi 14 h.

⚠ **La mise en garde énoncée avant le choix, et retenue :** deux objets, c'est deux mécanismes de
capacité à tenir en accord, et ils divergeront au premier correctif si on les écrit deux fois. La
décision est donc assortie d'une contrainte de mise en œuvre : **le décompte de places, la liste
d'attente et l'émargement s'écrivent UNE fois** et servent les deux objets. Ce qui diffère entre
événement et créneau est ce qui justifie la séparation — la communication, la récurrence — pas la
mécanique de remplissage.

### 2026-08-30 · D73 — Un service se vend à l'unité ET s'inclut dans une formule ; son lien au planning est optionnel

Tranché par **Maxime** sur les deux points.

Le même objet — une séance de coaching, un massage — se vend seul au comptoir **ou** entre dans un
abonnement. Et il occupe un créneau et une ressource **selon le service** : un massage prend une
cabine et une heure ; un forfait « prêt de serviette » ne prend rien.

**Raison :** c'est le plus proche du métier, et le plus exigeant — il faut que les deux chemins de
vente partagent **la même définition** du service, sinon le quota inclus dans la formule et le
produit vendu au comptoir désignent deux choses portant le même nom.

**État mesuré :** `Offre\Entity\ServiceInclus` existe mais n'est **pas** cet objet — c'est une
prestation incluse dans une formule, à quota décompté en semaine calendaire sans report, non
vendable seule. Le service vendable reste à construire, et devra englober celui-là plutôt que
coexister avec lui.

### 2026-08-30 · D74 — Une carte désigne ce que son crédit ouvre

Tranché par **Maxime**, qui a posé le cas : « une carte de 10 piscine va permettre l'entrée dans la
piscine ; par contre une carte de 10 = 12 aquagym va permettre de **réserver** son cours ».

Une carte porte donc deux choses : un **crédit** (dix entrées) et la **destination** de ce crédit —
une zone d'accès, où le tourniquet décompte, ou une activité, où la réservation décompte.

**Raison :** c'est le même objet métier — une carte multi-entrées — et il serait faux d'en faire deux
typologies. Un seul paramètre suffit à les distinguer, et il garde exprimable la carte mixte (dix
entrées utilisables à la piscine *ou* en aquagym), que deux types séparés rendraient impossible.

⚠ **Conséquence sur le modèle existant :** `CarteMultiEntrees` ne porte aujourd'hui qu'un nombre de
compostages. Il lui manque **ce que ces compostages achètent**. Et la projection d'accès
(`StubProjectionDroit`) produit un droit `carte_quota` **sans distinguer les deux cas** : une carte
aquagym projetée aujourd'hui ouvrirait un tourniquet.

### 2026-08-30 · D75 — Une carte de réservation décompte à la réservation, et rend l'entrée si l'annulation est à temps

Tranché par **Maxime**.

Réserver prend une entrée. Annuler avant le délai la rend. Un absent qui n'a pas prévenu la perd.

**Raison :** la place est tenue pour celui qui a réservé, le client peut se raviser, et le no-show
coûte — ce qui est aussi ce qui fait revenir les places dans le circuit. Décompter à la **présence**
aurait laissé quelqu'un réserver cinq cours et en faire un : les places partent et la salle reste
vide.

**Le délai d'annulation n'est pas fixé ici.** `Reservation\Entity\RegleAnnulation` existe déjà ; c'est
lui qui doit le porter, pas une constante.

### 2026-08-30 · D76 — Séance à l'unité et forfait coexistent, et décomptent la même capacité

Tranché par **Maxime** : on peut acheter la séance d'aquagym du lundi 14 h, **ou** le trimestre.

⚠ **La contrainte que cette décision impose, et qui est tout son coût :** les deux chemins de vente
doivent décompter **le même compteur de places**. Deux compteurs — un pour les abonnés, un pour les
ventes à l'unité — mettraient plus de monde dans le bassin que le bassin n'en contient, et le défaut
ne se voit qu'au bord de l'eau. C'est la même exigence que D68 pour l'événement, appliquée au
créneau : *une place, un compteur, plusieurs façons de l'acheter*.

### 2026-08-30 · D77 — L'inscription d'office au forfait est un paramètre de l'activité

Tranché par **Maxime** : « au choix de l'établissement ».

Payer le trimestre inscrit d'emblée sur toutes les séances **pour les activités configurées ainsi** —
un cours à effectif fixe, un stage. Pour les autres, le forfait paie et la place se prend séance par
séance, ce qui rend les absences aux ventes à l'unité et remplit mieux.

**Raison :** les deux régimes existent dans la vraie vie et ne se déduisent pas l'un de l'autre. Un
cours de natation enfant a une liste nominative ; un créneau de musculation n'en a pas.

⚠ **Et le paramètre porte sur l'ACTIVITÉ, pas sur le produit ni sur l'établissement.** Un même site
a des cours des deux régimes. Le mettre sur l'établissement obligerait à trancher pour tout le monde ;
le mettre sur le produit le dupliquerait à chaque tarif.

### 2026-08-30 · D78 — Une carte cadeau s'émet en code ou en support physique selon le canal, avec un seul solde derrière

Tranché par **Maxime** : « les deux, selon le canal de vente ».

Un code remis à l'achat en ligne, une carte physique au guichet — et **le même avoir** derrière.

**Raison :** offrir se fait par message autant que de la main à la main. ⚠ Le coût était énoncé avant
le choix et il est retenu : **deux chemins d'émission pour un seul solde**. La conséquence de
conception est que l'avoir est l'objet, et le code comme la carte n'en sont que des **supports** —
jamais l'inverse. Un modèle où le code *serait* l'avoir rendrait impossible de le remplacer après une
perte.

### 2026-08-30 · D79 — Un service est le même objet, qu'il soit inclus dans une formule ou vendu à l'unité

Tranché par **Maxime**, précisant D73.

« Séance de coaching » est défini **une fois**. Une formule peut l'inclure avec un quota ; la caisse
peut le vendre à l'unité.

**Raison :** deux définitions du même service divergeraient au premier correctif — on changerait sa
durée ou sa ressource d'un côté et pas de l'autre, et deux « coaching » porteraient le même nom sans
être la même chose.

**Ce que ça implique de reprise :** `Offre\Entity\ServiceInclus` n'est pas cet objet — c'est un quota
dans une formule, non vendable seul. Il devra être **englobé** par le service vendable, pas coexister
avec lui. Migration des formules existantes à prévoir.

### 2026-08-30 · D80 — En attente : la recharge d'une carte

Maxime, 30/08 : « à voir », et il préfère attendre son débrief sur les cartes.

**Ce qui se construit sans elle :** le crédit d'une carte, la destination de ce crédit (D74), le
décompte (D75), la validité. **Rien de tout cela ne préjuge** de la réponse — la recharge sera soit un
tarif de plus dans la grille du même produit, soit un produit distinct, et les deux se posent sur le
modèle ci-dessus sans le modifier.

C'est le cas où attendre ne coûte rien, et il est signalé comme tel pour qu'on ne le confonde pas
avec un blocage.

### 2026-08-30 · D81 — Ce qui décide qu'un billet vendu ouvre une porte : la zone déclarée sur le produit, et rien d'autre

Tranché par **Maxime**, en réponse au paramétrage QR qu'il avait annoncé vouloir détailler.

Un produit déclare quelle zone il ouvre (`Acces\Entity\ProductAccessZone`). Ses billets ouvrent cette
zone-là. Un produit qui ne déclare rien n'ouvre rien.

**Raison :** aucun réglage supplémentaire à expliquer, et le paramétrage est là où l'exploitant le
cherche — sur le produit. Un interrupteur par établissement en plus aurait créé deux endroits où
chercher quand ça n'ouvre pas.

⚠ **Et la variante « au premier scan » a été écartée pour une raison technique dite avant le
choix :** elle obligerait le tourniquet à interroger la vente en direct, donc supprimerait le
fonctionnement hors ligne — qui est la raison d'être même de la projection locale (valider en moins
d'une seconde, y compris coupé du réseau).

**Ce que ça a permis de construire :** `App\Acces\Adapter\SaleAccessPairingAdapter`, l'implémentation
du port `App\Vente\Port\AppairageAccesInterface` que le stub annonçait tenir en attendant. Voir D85.

### 2026-08-30 · D82 — Le courriel passe par un service transactionnel dédié ; le prestataire reste à désigner

Tranché par **Maxime** : un service transactionnel dédié, plutôt que le SMTP mutualisé de
l'hébergeur. Le prestataire exact (Brevo, Mailjet, Postmark…) est remis à plus tard.

**Raison :** le préavis SEPA a besoin d'une **preuve d'envoi** — sans elle, l'échéance reste exclue
de la remise et aucun prélèvement ne peut partir. Un SMTP mutualisé ne rend ni suivi de
délivrabilité ni retour de rejet exploitable, et ses quotas conviennent mal à des relances en série.

**Ce qui se prépare sans le prestataire :** la configuration lit déjà `MAILER_DSN`. Il n'y aura donc
qu'une variable à poser le jour venu. ⚠ **La clé n'est jamais manipulée par une session Claude** —
elle est posée par Maxime.

### 2026-08-30 · D83 — L'ordonnanceur démarre après le transport de courriel, pas avant

Tranché par **Maxime**.

Vingt-trois tâches planifiées sont écrites, aucune n'a jamais tourné.

**Raison, énoncée avant le choix :** sans transport, `sepa:preavis:annoncer` sort en « journalisé »,
et une échéance sans préavis délivré est **exclue de la remise**. Démarrer l'ordonnanceur maintenant
donnerait donc l'illusion que le système tourne, **sans qu'un seul prélèvement puisse partir** — une
panne plus coûteuse que l'arrêt actuel, parce qu'elle est invisible.

⚠ **L'ordre n'est pas réversible sans confusion.** Une fois l'ordonnanceur démarré, distinguer « le
préavis n'est pas parti parce qu'il n'y a pas de transport » de « le préavis n'est pas parti parce
que la tâche a échoué » demande de lire les journaux. Avant démarrage, la cause est unique.

**Et le démarrage lui-même reste en deux temps :** huit tâches anodines peuvent partir seules ;
quatorze exigent un premier passage supervisé, une par une — dont la facturation mensuelle,
l'effacement RGPD et la purge documentaire. Ce sont quatorze décisions séparées, pas une.

### 2026-08-30 · D84 — L'exemption durable de blocage : même droit que le forçage, jusqu'à retrait, motif obligatoire

Tranché par **Maxime**, sur les deux points.

La collectivité qui produit un impayé par mois et qu'on ne veut jamais bloquer obtient une exemption
**qui ne s'éteint pas toute seule** — c'est le point : la réinscrire chaque mois reviendrait au
forçage manuel qu'elle remplace. Motif obligatoire et agent tracé, comme le forçage.

**Le droit est celui qui existe déjà** (`forcer la réouverture`), plutôt qu'un droit dédié. ⚠ La
contrepartie était énoncée avant le choix et elle est retenue : **un agent de caisse peut exempter un
client pour toujours**. Le motif obligatoire et la trace de l'agent sont donc la seule garde — d'où
l'exigence qu'ils soient réellement obligatoires, pas seulement suggérés.

⚠ **Une date de fin obligatoire a été écartée, et pour une raison qui vaut d'être gardée :** une
exemption qui expire un lundi matin bloque un client à la porte **sans que personne n'ait rien décidé
ce jour-là**. Le retrait doit être un geste, comme la pose.

**Ce qui ne change pas :** le blocage lui-même reste prudent — posé à la détection, levé seulement
quand tous les dossiers du client sont réglés. L'exemption s'ajoute, elle ne l'assouplit pas.

### 2026-08-30 · D85 — Un billet vendu crée son support et son droit d'accès, sans appairage manuel

Conséquence directe de D81, et réponse à Maxime : « je vois qu'un QR code n'ouvre toujours pas le
contrôle d'accès ».

**Ce qui manquait n'était pas ce qu'on croyait.** `ValiderVenteService:149` appelait déjà
`appairer()` à chaque vente. Le port `App\Vente\Port\AppairageAccesInterface` était simplement câblé
sur `AppairageAccesStub`, qui bascule un statut et **ne parle jamais au module Accès**. Mesuré :
5 billets vendus, 5 supports d'accès, **aucun croisement** — le tourniquet vérifiait pourtant
correctement la signature du code avant de conclure « support inconnu ».

**⚠ Et on ne pouvait pas se contenter de rebrancher.** `DroitAcces::ouvre()` rend `true` quand aucun
espace n'est autorisé : **un droit sans espace ouvre tout**. Mesuré le 30/08 : **0 produit sur 17**
déclarait une zone, pour 8 espaces existants. Projeter sans condition aurait fait de chaque billet
vendu un passe-partout des huit espaces, à l'échelle de toutes les ventes, et en silence.

**⚠ Et « pas de zone » ne pouvait pas non plus être un échec.** `ValiderVenteService` traite un échec
d'appairage en bloquant la **remise du support** : rendre `false` pour un produit sans zone aurait
bloqué la remise de tous les billets de tous les produits. Et ce serait faux au fond — une bouteille
d'eau, un cadenas, un article de boutique n'ouvrent aucune porte, et c'est normal. **Un produit sans
zone ne rate pas son appairage : il n'en a pas.**

`false` reste donc réservé à un vrai échec — code déjà appairé, support bloqué — c'est-à-dire aux cas
où remettre le billet serait une faute.

**L'annulation révoque aussi côté accès.** Le stub se contentait du statut côté vente ; ne pas
révoquer laisserait un billet annulé continuer d'ouvrir la porte — un défaut que le stub ne pouvait
pas avoir, et que son remplacement aurait introduit si on l'avait oublié.

**Éprouvé par deux témoins qui discriminent :** remettre le stub fait rougir *seulement* « le billet
ouvre » ; retirer la garde de zone fait rougir *seulement* « sans zone, il n'ouvre rien ». Chacun
mesure sa moitié.

### 2026-08-30 · D86 — Un billet vendu est TOUJOURS connu du contrôle d'accès ; seules les portes qu'il ouvre dépendent de la déclaration

Tranché par **Maxime**, après qu'il a signalé le cas que la conception précédente ne couvrait pas :
« certains n'ont pas de contrôle d'accès, mais le billet pourra être quand même validé par un
contrôle manuel ».

**Deux questions que le code confondait, et qu'il faut séparer :**

    « ce billet est-il valide ? »            existence, fenêtre de validité, pas déjà consommé
    « ce billet ouvre-t-il CETTE porte ? »   la zone déclarée sur le produit

Un agent qui contrôle à la main n'a besoin que de la **première**, et n'a pas d'équipement.

⚠ **Ce que j'avais construit faisait l'inverse, et c'est corrigé par cette décision.**
`SaleAccessPairingAdapter` ne projetait RIEN quand le produit ne déclarait pas de zone — donc un
billet vendu sur un site sans matériel n'existait pas du tout côté accès, et **aucun agent n'aurait
eu quoi que ce soit à interroger**. La garde était juste contre le passe-partout, et fausse contre le
contrôle manuel. Je ne l'avais pas vu ; Maxime l'a nommé en une phrase.

**Nouvelle règle :** la vente projette toujours le support et le droit. La déclaration de zone décide
de ce qui s'ouvre, pas de ce qui existe.

⚠ **ET L'ORDRE DE MISE EN ŒUVRE EST CONTRE-INTUITIF, IL DOIT ÊTRE RESPECTÉ.** Retirer la garde avant
que `DroitAcces::ouvre()` ne devienne strict (D87) ferait de chaque billet vendu un passe-partout des
huit espaces — un droit sans espace ouvre tout aujourd'hui. **`ouvre()` d'abord, la garde ensuite.**

### 2026-08-30 · D87 — `DroitAcces::ouvre()` devient strict, et un outil de scan prend en charge le contrôle manuel

Tranché par **Maxime** en deux temps.

**D'abord :** un droit sans espace autorisé n'ouvre plus rien, quel que soit le chemin qui l'a créé —
vente ou appairage manuel au comptoir. Fini l'asymétrie où deux billets identiques se comportaient à
l'inverse selon un chemin invisible à l'exploitant.

**Raison, et c'est une fenêtre qui ne reviendra pas :** le comportement permissif existait pour ne pas
fermer des portes devant des porteurs déjà équipés. Maxime a rappelé qu'**il n'y a pas encore de
commercialisation** — trois droits en base, tous des données de test. Le changement est donc gratuit
aujourd'hui et coûteux dès le premier client.

**Ensuite, et c'est ce qui rend le strict tenable :** « on doit faire un outil de scan ».

Mesuré avant de poser la question : `POST /api/acces/passages` et `POST /api/acces/passages/manuel`
passent **tous deux** par `ValidationPassageHandler::valider()`, donc par le contrôle de zone, et
**exigent tous deux un équipement**. Il n'existe aujourd'hui aucun chemin pour « un agent contrôle un
billet sur un site sans matériel ». Ce que Maxime décrivait était un besoin, pas une capacité.

**Ce que l'outil de scan doit être, et ce qu'il ne doit pas être :** l'agent scanne ou saisit le code,
le système répond *valide* / *déjà utilisé* / *expiré*, et marque le billet consommé. **Sans
équipement, sans zone, sans porte.** ⚠ S'il passait par `ValidationPassageHandler`, il hériterait du
contrôle de zone et le problème reviendrait entier — c'est précisément le chemin à ne pas réutiliser
malgré la tentation, puisque tout le reste y est déjà.

**Ordre d'exécution :** `ouvre()` strict (module Accès) → retrait de la garde de zone dans
l'adaptateur de vente → outil de scan. Les deux premiers sont indissociables ; le troisième est ce qui
rend l'ensemble utilisable sur un site sans matériel.

### 2026-08-30 · D88 — Les zones d'un badge de personnel viennent de la FONCTION, pas du badge

Tranché par **Maxime**, contre les deux autres options proposées.

Les zones se rattachent au rôle — accueil, technique, direction, maître-nageur — et le badge en
hérite. Un agent d'accueil ouvre l'accueil ; un technicien ouvre les locaux techniques.

**Raison :** moins de saisie qu'un badge à la fois, et ça colle à la façon dont on recrute — un
saisonnier prend une fonction, pas un jeu de portes. ⚠ La contrepartie, énoncée avant le choix : **il
faut que les rôles existent déjà et soient justes.** Un rôle trop large donne à tous ceux qui le
portent les portes du plus privilégié d'entre eux.

⚠ **Le trou que cette décision comble, trouvé par `allaccess-8e` en éprouvant D87 au lieu de la
croire :** `EmissionBadgeStaffHandler` et `RecalculFenetreBadgeHandler` ne posent **aucune** zone —
mesuré, 0 occurrence de `addAuthorisedSpace`. Un badge de personnel n'a pas de produit, donc aucune
zone produit à hériter : **il n'avait, jusqu'ici, aucun moyen de dire ce qu'il ouvre.**

### 2026-08-30 · D89 — Les zones d'une réservation viennent de l'ACTIVITÉ réservée

Tranché par **Maxime**.

Un cours d'aquagym ouvre le bassin où il a lieu. L'activité connaît déjà sa ressource : c'est la
donnée la plus proche de la vérité, et elle existe — rien à ressaisir.

**Raison :** l'alternative (déclarer la zone sur le produit vendu) aurait obligé à répéter sur chaque
produit une information que l'activité porte déjà, avec la divergence garantie au premier changement
de bassin.

Même origine que D88 : `ProjectionAccesReservationHandler` ne pose aucune zone non plus.

### 2026-08-30 · D90 — Transitoire assumé : badges et réservations continuent d'ouvrir, la règle stricte s'applique aux billets vendus

Tranché par **Maxime**, en connaissance de ce que ça recrée.

    règle stricte                billets vendus, dès maintenant
    ancien régime maintenu       badges de personnel, droits nés d'une réservation

⚠ **On recrée volontairement l'asymétrie que D87 venait de supprimer.** La différence, et c'est toute
la différence : elle est **écrite, bornée et attribuée**, au lieu d'être un effet de bord que
personne ne nomme. Une asymétrie connue se répare ; une asymétrie invisible se découvre au pire
moment.

**Ce qui l'éteint :** la livraison de D88 et D89. Pas une date — une condition. Une date inventée
ici serait fausse le jour où elle passe sans que personne n'ait rien fait ; la condition, elle, se
vérifie.

**Forme exigée de la mise en œuvre, et ce n'est pas un détail :** l'exception doit être **une
constante nommée** portant les seuls types de source concernés, avec en commentaire ce qui la fait
disparaître. Pas une condition dispersée, pas un `if` implicite. Le jour où D88 et D89 sont livrées,
retirer la constante doit être un geste, et son absence doit se voir.

⚠ **Et elle ne doit pas survivre en silence.** Un contrôle doit refuser le jour où un droit d'un type
exempté porte *déjà* des zones déclarées : cela signifie que le mécanisme existe, donc que
l'exception n'a plus d'objet. C'est ce qui évite qu'un transitoire devienne un permanent —
exactement le sort du commentaire de `ValidationPassageHandler`, dont l'argument était mort avant
qu'on ne s'en aperçoive.

**Mesure au moment de la décision**, en préproduction :

    source_type    droits   avec zone
    billet              1           0
    booking             1           0
    carte_quota         2           1     ← celui vendu par le pont du 30/08

Quatre droits, tous de test. Aucun badge de personnel en base : le chemin existe, il n'a jamais
servi.

---

## D91 — Un produit publié ne peut pas perdre son dernier prix

**Décidé par Maxime le 31/08.** `PublicationGuard` (RG-M1-09) exige ≥1 prix valide pour publier, et
il n'était rejoué nulle part ensuite. Un `PATCH` sur une case de grille pouvait donc rendre
invendable un produit qui restait « Publié » : pas d'erreur, pas de changement de statut, rien.

**La règle vaut désormais aux deux portes.** `PriceGridProcessor` refuse une écriture qui ne
laisserait **aucun** prix valide à un produit **publié**.

⚠ **Il ne refuse que ce cas-là.** Vider un tarif parmi plusieurs reste permis : un prix null veut
dire « non commercialisé » (≠ gratuit, CA-5), et retirer un tarif de la vente est un geste métier.
Un brouillon reste librement modifiable.

**Deux chemins mènent au même état**, et le second ne vient pas à l'esprit : effacer le prix, ou
**déplacer la case vers un autre produit**. Les deux passent par ce `PATCH`.

**Forme exigée :** le contrôle interroge la BASE et non les collections en mémoire. `setProduit()`
est une affectation simple — la collection du produit de destination ne contient pas encore la case,
celle du produit d'origine la contient toujours. `Produit::aPrixValide()` répondrait faux des deux
côtés, en sens inverse.

## D92 — Un produit publié est rattaché à son site ; « aucun site » reste le socle

**Décidé par Maxime le 31/08**, après mesure : aucun des 8 produits publiés de la préprod n'avait
d'établissement. Deux règles se rencontraient là, chacune juste séparément :

    PublicationGuard          « ≥1 site est un PRÉREQUIS pour publier »
    PerimetreProduitExtension « aucun établissement = SOCLE, partagé par tous » (leftJoin voulu)

**Le mécanisme du socle est conservé** — il est délibéré, commenté, et il a un usage. **Ce qui est
tranché, c'est que la donnée ne doit pas y tomber par défaut.** L'exigence de site à la publication
est maintenue, et les produits existants ont été rattachés.

**Ce que ça valait, mesuré sur les deux vitrines publiques, sans authentification :**

    avant   Piscine A → 7 produits   ·   Patinoire B → les MÊMES 7
    après   Piscine A → 7 produits   ·   Patinoire B → "produits":[]

⚠ **La boutique publique d'un établissement servait le catalogue d'un autre.** En préprod, avec un
seul client, invisible. Le jour de la commercialisation, une fuite inter-clients sur le web public.

**Conséquence à retenir pour les écrans :** une liste de produits vide sur un établissement est
désormais une réponse JUSTE, à distinguer d'une lecture échouée.

## D93 — Le libellé d'une catégorie comptable ne porte jamais un numéro de compte

**Décidé par Maxime le 31/08** en tranchant le cas du type « Boutique (marchandise) », dont le
défaut comptable était « Billetterie (compte 7061) » : un mug vendu s'imputait en billetterie.

**Une marchandise va en « Boutique ».** Et la règle générale que ce cas révèle :

- **La catégorie est de la nomenclature** — `AccountingCategorySeeder` pose les huit libellés usuels
  en portée socle (D51). Ils sont courts, et **sans numéro** : « Billetterie », « Boutique »,
  « Locations ».
- **Le numéro vit dans `CompteComptable`**, et le rattachement de l'un à l'autre est un choix
  d'exploitant porté par `MappingComptable`.

⚠ Un libellé qui nomme un compte mentirait chez le premier client qui impute autrement — et il ne
lèverait rien : `DefaultCategoryResolver` **n'applique rien** quand le libellé n'existe pas pour
l'établissement, en silence.

**Ce qui reste ouvert et n'est PAS tranché :** sept produits publiés par les semis n'ont aucune
catégorie comptable, parce que les fixtures écrivent `setStatut(Publie)` en dur et ne passent par
aucune garde. Le trou est mesuré, pas comblé. `SemisSansPrixTrait` ne contrôle donc que le prix, et
le dit dans son en-tête ; il sera élargi à tous les prérequis quand les sept auront leur catégorie.

⚠ **Et une valeur reste inexpliquée :** la base de préprod portait un défaut comptable sur
`boutique_stock` qu'**aucune ligne du dépôt n'écrit** — `StockFixtures` crée ce type sans défauts.
Elle a été remplacée, son origine reste inconnue.

---

## D94 — Un canal non raccordé refuse ; il n'annonce jamais un succès

**Décidé par Maxime le 31/08.** Deux adaptateurs câblés en production rendaient `StatutEnvoi::Transmis`
sans rien transmettre :

    ChorusProStubAdapter::deposer()  →  Transmis     (dépôt B2G, Chorus Pro)
    PdpStubAdapter::deposer()        →  Transmis     (e-reporting, réforme française)

L'exploitant voyait ses factures B2G **marquées transmises**, et l'aurait découvert par une relance de
sa collectivité — au moment et par la voie les plus coûteuses. Côté e-reporting, l'enjeu dépasse une
facture : une déclaration marquée transmise est **une obligation déclarative que plus personne ne sait
manquante**.

⚠ **Le dépôt portait déjà les deux traitements opposés du même cas.** `ItboxAdapter` lève une
exception explicite pour ce motif exact, et le dit dans son en-tête : *« un adaptateur muet est pire
qu'un adaptateur absent »*. Deux réponses contraires à la même question, à deux modules d'écart.

**La règle, désormais générale :** un port sans implémentation réelle **refuse explicitement**. Il ne
rend jamais un statut de succès, et son message nomme ce qui **n'a pas eu lieu**.

**Forme retenue :** `ServiceUnavailableHttpException` (503). L'appelant n'a rien fait de mal et n'a
rien à corriger — un 4xx l'enverrait relire sa facture. Les deux handlers appellent `deposer()` avant
`persist()`/`flush()`, donc rien n'est écrit : aucun demi-état, et le dépôt reste rejouable tel quel le
jour du raccordement, sans nouveau numéro (RG-FACT-07 §7).

### Ce que le refus coûte, et comment on le paie

Le test d'API ne peut plus atteindre le rejeu-sans-nouveau-numéro : le canal refuse avant. Cette règle
a donc changé de niveau — elle est éprouvée contre un **adaptateur d'essai**, au niveau du handler.

**C'est plus juste, pas seulement plus commode :** cet invariant est le NÔTRE. Le vérifier à travers un
adaptateur qui ment revenait à faire dépendre notre propre règle d'une intégration absente.

⚠ **Et l'ancien test scellait le mensonge.** Il affirmait `statutEnvoi === 'transmis'` — assertion
**vraie et sans valeur**. Un test qui décrit un défaut le protège : il devient le gardien de ce qu'il
aurait dû signaler.

### Ce que cette décision ne règle pas

Aucun format de facture électronique n'existe dans le code — mesuré le 31/08 : ni **EN 16931**, ni
**UBL**, ni **CII**, ni **Peppol**, et « Factur-X » n'apparaît qu'une fois, dans une spécification,
comme question ouverte. La spécification de facturation le dit elle-même : *« aucune implémentation
n'est livrée »*. C'était le câblage qui affirmait le contraire ; il ne l'affirme plus.

**Bloquant avant commercialisation**, au même titre que le choix de la PDP.

---

## D95 — `reservation:no-show:basculer` ne démarre pas tant qu'aucun écran n'écrit la présence

**Interdiction, pas précaution.** Mesuré par `allaccess-c2` et `allaccess-b8`, deux mesures
indépendantes qui se recoupent :

    BasculerNoShowCommand:80   if ($reservation->isPresenceConfirmee())  → Honoree
                        :82   else                                      → NoShowFacture

    seul écrivain du drapeau, hors entité   EmargerProcessor:63
    appels du frontal à /emarger            0
    en base                                 6 réservations · 0 présence confirmée

⚠ **La branche `Honoree` est du code mort depuis l'origine.** Rien n'a jamais pu écrire ce drapeau,
donc `isPresenceConfirmee()` est faux pour toute réservation ayant jamais existé. Lancer la tâche
aujourd'hui produirait **six factures d'absence** — sur un créneau réel de vingt personnes toutes
présentes, elle en produirait vingt.

**C'est le symétrique exact de la garde tarifaire du même jour** (D91), dont le décompte rendait
toujours zéro et qui refusait donc *tout* vidage de prix. L'une refuse tout, l'autre laisse tout
passer ; dans les deux cas la protection est écrite, lisible, et **n'a jamais pu s'exercer**.

Et dans les deux cas, ce qui la démasque est le cas qu'on n'a aucune raison d'écrire — ici
« une personne présente ne doit PAS être facturée ».

**La condition de levée, et elle est vérifiable :** un écran appelle `/emarger`, et une présence
confirmée existe en base. `allaccess-c2` construit `emarger` et `annuler`, et a inverti son ordre
pour mettre `emarger` d'abord à cause de ceci.

**Ce qui reste ouvert :** `SourcePresence` déclare `EmargementManuel` **et** `PassageAcces`. Le
second n'est produit nulle part — le contrôle d'accès ne remonte pas la présence à la réservation.
Un chemin nommé dans une énumération et jamais construit se lit comme un fait ; c'est la même
famille que les vingt-trois commandes planifiées que rien ne déclenche.

## D96 — Les trois listes de garde-fous se comptent elles-mêmes

Un contrôle doit être appelé par `bin/garde-fous.sh`, `hooks/pre-commit` **et** `hooks/pre-receive`.
La règle existait ; rien ne la vérifiait pour les contrôles du frontal.

**Mesure du 31/08 :** cinq contrôles frontaux sur huit n'étaient pas câblés dans `pre-commit`.

    verifier-formats · verifier-imports · verifier-classes
    verifier-dates-locales · verifier-profil-charge

Rien ne passait — `pre-receive` les porte tous les huit. Ce qui se perdait est le **moment** du
retour : au push au lieu du commit, donc après plusieurs commits empilés, donc avec la tentation du
`--no-verify` pour ne pas tout refaire.

**La cause était dans la forme.** Les trois câblés l'étaient par trois copies du même bloc de sept
lignes ; ajouter un contrôle demandait d'en recopier une quatrième. Une règle recopiée diverge au
premier correctif — celle-ci a divergé **par omission**, ce qui est plus discret et se voit moins.

**Désormais :** un seul bloc (`lancer_front`) et huit appels d'une ligne, plus un filet de complétude
dans chacune des trois listes.

⚠ **Le prédicat vise l'APPEL, pas la mention** — sauf dans `pre-receive`, où le hook poussé appelle
ses contrôles par une boucle et ne contient donc jamais le nom littéral. La différence est voulue et
écrite sur place.

**Le nom sépare les deux familles du répertoire**, et c'est délibérément lisible :

    verifier-*.mjs · garde-fou-*.mjs   des CONTRÔLES, ils doivent tourner
    mesurer-*.mjs                      des SONDES, lancées à la main

---

## D97 — La reprise initiale d'un client passe avant les autres imports

**Décidé par Maxime le 31/08.** Mesure préalable : l'export est mûr (Cegid, Ciel, Sage, EBP,
reporting, audit, passages), **l'import n'existe qu'une fois** — les relevés bancaires — et rien
n'existe pour les données d'un client.

Trois familles, qui n'ont pas les mêmes règles : la **reprise initiale** (un coup, gros enjeu,
annulable), les **flux récurrents** (petits, fréquents, rejoués), les **corrections en masse**.

La reprise passe d'abord parce qu'elle bloque une signature : sans elle, un client ressaisit à la
main son fichier d'abonnés et les crédits restants de ses cartes. Elle est aussi la plus dure, donc
elle donne le patron aux deux autres.

## D98 — Un import refuse tout, ou n'écrit rien

**Décidé par Maxime le 31/08.** Sur dix mille lignes dont douze sont mauvaises : rien n'entre tant
que le fichier n'est pas propre, et la réponse **nomme les lignes**.

**Conséquence de forme, et c'est elle qui compte :** un import qui écrit et valide en même temps ne
*peut pas* tenir cette règle — quand il découvre la ligne 4 217, les 4 216 premières sont déjà là.
La décision impose donc **deux temps** :

    POST /imports                  analyse et valide TOUT, n'écrit rien en base métier
    POST /imports/{id}/appliquer   applique, en une transaction

⚠ La simulation cesse d'être une option à cocher : elle est la première phase. On obtient le refus
total **et** la prévisualisation sans avoir à choisir entre les deux.

**Ce que ça coûte, et il faut le savoir :** un client qui met trois jours à corriger douze lignes
n'avance pas pendant trois jours. C'est assumé — l'alternative, importer 9 988 lignes, exige une
idempotence ligne à ligne sans laquelle un second dépôt du fichier corrigé recrée les 9 988.

## D99 — Les ventes historiques ne sont pas reprises

**Décidé par Maxime le 31/08.** L'ancien logiciel garde son historique le temps légal ; le nôtre
commence à la bascule.

⚠ **NF525 scelle les ventes en chaîne** : chaque opération porte l'empreinte de la précédente. Y
injecter des ventes qu'on n'a pas produites fabrique des écritures scellées fausses — pas une
approximation, un faux au sens où un contrôle l'entend.

**Ce qui n'est PAS fermé :** un espace « antériorité » hors chaîne, consultable et exclu de tout
calcul comptable. Il demanderait de tenir la frontière dans chaque écran, chaque export et chaque
clôture — et une frontière tenue à 95 % en comptabilité ne vaut rien. La question se rouvrira avec un
expert-comptable, pas seule.

## D100 — Le rapprochement d'identité se fait par référence externe, jamais par le nom

**Corollaire de D97, posé à la conception.** C'est le point où une reprise se gagne ou se perd :
« Dupont Jean » existe-t-il déjà ? Une mauvaise réponse **fusionne deux personnes** ou **en duplique
une**, et les deux se découvrent des mois plus tard, par une réclamation.

⚠ **Aucune heuristique sur le nom n'est acceptable** — ni « nom + prénom », ni « nom + date de
naissance », ni un score de similarité. Elles marchent sur 98 % des lignes, et les 2 % restants sont
exactement les familles nombreuses, les homonymes et les fratries : la clientèle d'une piscine
municipale.

**La règle :** chaque ligne porte `externalRef`, la clé de l'enregistrement dans le logiciel
précédent du client. Obligatoire, unique par établissement et par type.

Elle fait deux choses d'un coup : le rapprochement devient **exact** (connue = mise à jour, inconnue
= création, sans devinette), et l'idempotence devient **ligne à ligne** — rejouer un fichier corrigé
ne duplique pas ce qui était déjà entré. Même garantie que la clé d'idempotence du rejeu hors ligne,
et pour la même raison.

⚠ **Ça déplace une charge sur le client**, et il faut le dire franchement : son extraction doit
porter ses identifiants. Tout logiciel en a ; peu les exportent spontanément. C'est un aller-retour
de plus à la reprise, contre une classe entière d'erreurs qui ne se rattrapent pas.

**Aucune notion de référence externe n'existe aujourd'hui dans le dépôt** — mesuré le 31/08, six
graphies cherchées, zéro occurrence. Elle est à introduire.

---

## D101 — Cinq axes pour les développements à venir

**Décidé par Maxime le 31/08**, après comparaison avec la place de marché Magicline (86 intégrations,
sept catégories).

    1. Appli mobile adhérent, en marque blanche
    2. Agrégateurs — et pas seulement fitness
    3. Balances et machines connectées
    4. Assistant IA
    5. L'API

⚠ **Le constat qui a produit cette liste n'est pas un manque de fonctionnalités.** Fluvia a 47
modules, plus de largeur qu'aucun concurrent fitness. Ce qui manque, c'est **le dehors** : sur 25
adaptateurs, **18 sont des simulacres** — paiement carte, prélèvement SEPA remis en banque, matériel
d'accès, facturation électronique, connecteurs OTA, fournisseur d'identité.

Et surtout : Magicline ne vend pas 86 fonctionnalités, il vend **le fait que 86 sociétés ont
construit dessus**. C'est un effet de réseau, et il ne se rattrape pas en développant plus vite.

## D102 — L'API passe en premier, parce qu'elle commande trois des quatre autres

**Ordre imposé par la dépendance, pas par la préférence.**

    appli mobile          consomme l'API
    agrégateurs           consomment l'API
    machines connectées   consomment l'API
    assistant IA          consomme l'API

⚠ **Construire les trois avant l'API produit trois couplages privés au lieu d'une surface publique.**
Chacun aurait son point d'entrée, sa version, ses règles — et la place de marché deviendrait
impossible à ouvrir sans tout reprendre.

**Ce qui manque aujourd'hui pour qu'un tiers puisse s'intégrer**, mesuré le 31/08 :

- aucune clé d'API délivrable à un tiers — les seules existantes servent à consommer *les leurs*
  (Anthropic, réseaux sociaux) ;
- aucun webhook sortant — les cinq occurrences sont internes ;
- ni OAuth, ni modèle de partenaire, ni portail développeur.

Un tiers qui voudrait s'intégrer à Fluvia aujourd'hui **n'aurait par où commencer**. C'est
exactement ce qu'a montré le guide remis à IT Cotation : notre seul intégrateur potentiel attend une
spécification de notre part, et il n'existe aucun chemin générique.

## D103 — Architecture de domaines pour `fluvia-app.com`

**Décidé par Maxime le 31/08.** Aujourd'hui la préprod sert TOUT sur un seul hôte : l'API derrière
une regex de chemin, l'application sur `/`, la boutique par slug d'URL.

    fluvia-app.com              vitrine marketing
    pro.fluvia-app.com          back-office exploitant
    api.fluvia-app.com          l'API publique, versionnée
    <client>.fluvia-app.com     la boutique publique de chaque client

### ⚠ La boutique publique ne partage jamais un hôte avec le back-office

Trois raisons concrètes :

- **Les cookies.** Un cookie de session du back-office ne doit pas être lisible depuis une page qui
  embarque le script d'un prestataire de paiement.
- **La politique de sécurité de contenu.** La boutique doit autoriser les scripts du PSP, le
  back-office doit les interdire. Une CSP unique pour les deux, c'est la plus permissive qui gagne.
- **L'indexation.** La boutique doit être référencée, le back-office jamais. Un `robots.txt` par hôte
  règle ça ; il n'y en a qu'un aujourd'hui.

**Règle qui va avec :** les cookies se posent sur l'hôte exact, **jamais sur `.fluvia-app.com`**.
Sinon la boutique d'un client peut lire la session d'un autre.

### Deux portes pour la même application

`api.` a son propre hôte parce qu'un partenaire ne doit pas être couplé à l'hôte du back-office.

⚠ **Le coût honnête** : un hôte distinct impose du CORS à notre propre frontal. La parade retenue —
le back-office continue d'appeler `pro.fluvia-app.com/api` (même origine, zéro CORS), les tiers
passent par `api.fluvia-app.com`. Même application, deux portes : l'une privée et rapide, l'autre
publique et contractuelle.

## D104 — Une boutique par sous-domaine, et le client se résout depuis l'HÔTE

**Décidé par Maxime le 31/08.** `piscine-ville.fluvia-app.com` plutôt qu'un chemin sur un hôte
unique.

⚠ **La conséquence technique est petite aujourd'hui et grosse plus tard.** Le code résout
actuellement la vitrine par un slug d'URL (`/boutique/vitrines/{slug}/catalogue`). Il doit apprendre
à la résoudre depuis l'hôte. Ajouter cela maintenant coûte peu ; le rétro-adapter quand vingt clients
ont des liens en circulation coûte cher.

**Ce que ça ouvre :** un client peut brancher son propre domaine (`billetterie.ville-x.fr`) sans
casser ses liens. C'est la condition de la marque blanche.

**Certificats :** un joker `*.fluvia-app.com` couvre les sous-domaines clients. Un domaine propre à
un client demande une émission par domaine — Let's Encrypt automatisé, à prévoir, pas à faire avant
le premier client qui le demande.

## D105 — Appli mobile : une commune, plus une déclinaison dédiée en option payante

**Décidé par Maxime le 31/08.** L'appli commune sert tous les clients, avec le logo et les couleurs
de l'établissement choisi. Une publication dédiée — nom, icône et fiche du club sur les magasins —
est vendue à ceux qui la veulent.

⚠ **La condition de viabilité, et elle est stricte : les deux doivent rester identiques
fonctionnellement.** Une seule base de code, un seul jeu d'écrans, la déclinaison ne changeant que
l'identité visuelle et la fiche du magasin.

Le jour où la version commune devient la parente pauvre, elle cesse d'être vendable — et on se
retrouve à maintenir autant d'applications qu'on a de clients, ce que la première moitié de la
décision existait précisément pour éviter.

**Ce que ça coûte, dit franchement :** chaque version publiée doit être revalidée par Apple et Google
autant de fois qu'il y a de déclinaisons. Ce coût croît avec le nombre de clients, pas avec le
produit — c'est le prix de l'option, et il doit se retrouver dans son tarif.

## D106 — Les sous-domaines techniques sont réservés, et la liste est dans le code

**Conséquence directe de D104, posée à la conception.** Le back-office, l'API et les boutiques
clientes partagent le **même espace de noms**.

Un client nommé « pro », « api » ou « www » entrerait donc en collision avec un hôte technique — et le
symptôme serait une boutique qui sert le back-office, ou l'inverse.

⚠ **Ce genre de collision ne se découvre pas en revue de code : elle se découvre le jour où un
commercial saisit le nom d'un nouveau client.** La liste doit donc vivre là où le nom est validé, pas
dans une consigne.

    pro · api · www · app · admin · mail · static · assets · cdn · status · dev · test

**Forme exigée :** une constante nommée, refusée à la création d'une vitrine, avec un message qui
dit pourquoi. Pas un contrôle dispersé, pas une convention orale. Ça coûte une constante aujourd'hui
et évite un incident de production plus tard.

---

## D107 — On n'émet pas une facture sans savoir à qui, et la règle ne porte pas sur le montant

**Décidé le 31/08.** RG-FACT-08 existait depuis l'origine, écrite et juste, et n'avait **aucun
appelant** — relevé avec témoin par `allaccess-b8` : une définition, zéro appel.

### Pourquoi pas le seuil de la facture simplifiée

Le droit admet une facture **simplifiée** en B2C sous un certain seuil. On aurait pu y adosser la
garde. ⚠ **On ne l'a pas fait, délibérément :** ce serait faire dépendre une mention légale d'un
nombre qu'on ne peut pas vérifier depuis le code et qui bouge avec les textes.

La règle porte donc sur **qui est le destinataire**, jamais sur combien il doit :

    personne morale · organisme public   raison sociale + SIRET + adresse, toujours, sans seuil
    particulier nommé                    adresse exigée — nommer quelqu'un, c'est pouvoir l'atteindre
    aucun destinataire                   ce n'est pas une facture, c'est un ticket

**C'est le troisième cas qui débloquait tout.** Neuf tests facturaient une vente **sans dire à qui**.
Ce n'est pas une facture simplifiée : aucune lecture du droit n'appelle ça une facture. Et le point
d'entrée accepte déjà un destinataire — c'est le geste du guichet : le client demande une facture,
l'agent lui demande son nom et son adresse.

### ⚠ Ce que la garde a révélé, et qui n'était pas un défaut de test

`SubscriptionInvoicer::destinataire()` recopiait le nom, le prénom et la raison sociale depuis la
fiche client — **mais ni le SIRET ni l'adresse**, que la fiche porte pourtant.

**Les factures d'abonnement de Fluvia à ses propres clients n'auraient comporté ni SIRET ni adresse
du destinataire.** Notre propre facturation n'était pas conforme, et personne ne le voyait parce que
rien ne demandait jamais si une facture était complète.

C'est la même famille que tout ce qu'on a trouvé cette nuit : **la donnée existait, le code ne la
portait pas.**

### Le coût, assumé

Aujourd'hui on peut facturer une vente anonyme, demain non. C'est un vrai changement de
comportement, pas un durcissement cosmétique. Il est défendable parce qu'une facture sans
destinataire identifiable n'a jamais été une facture.

## D108 — `fluvia-app.com` est un choix contraint, pas un oubli

`fluvia.com` et `fluvia.fr` **ne sont pas disponibles** (vérifié par Maxime le 31/08). La vitrine
marketing ira donc sur `fluvia-app.com`, malgré le « app » dans un domaine qui sert d'abord à
présenter le produit.

⚠ **C'est écrit ici pour que personne ne rouvre le sujet en croyant à une inadvertance.** Si l'un des
deux se libère un jour, le déplacement se fera — et il coûtera d'autant plus cher qu'il y aura de
liens en circulation. C'est une raison de plus pour que les clients aient leur propre sous-domaine
(D104) : leurs liens à eux ne dépendent pas du nôtre.

---

## D109 — La supervision se revendique, elle ne s'infère pas de la forme de l'appel

**Deux défauts mesurés par `allaccess-b8`, et le premier masquait le second.**

`RunScheduledTasksCommand` retient une tâche jamais exécutée et non marquée sûre au premier passage :
elle rattraperait tout son retard en une fois. **Quatorze tâches du catalogue sont dans ce cas**, dont
`dms:purge-expired-documents` (suppression), `crm:rgpd:appliquer-conservation` (effacement RGPD),
`subscription:facturer-le-mois` (facturation) et `padel:eclairage:commander` (matériel).

La levée du verrou se lisait `$supervise = is_string($only) && $only !== ''` — autrement dit
**« lancé avec `--only` » valait « regardé par un humain »**. Or `infra/ordonnanceur.sh` appelle
`--only` **pour chaque tâche, à chaque cycle**. Le verrou était donc court-circuité en permanence,
depuis sa naissance, et la supervision qu'il suppose n'a jamais existé.

> **Ce qui protégeait réellement n'était pas ce verrou, c'était la liste blanche du shell.** Les deux
> mécanismes avaient l'air complémentaires ; en réalité l'un désactivait l'autre.

**La règle.** La supervision est une **attestation**, pas une déduction. Un `--supervise` explicite,
absent par défaut. Qui l'oublie retombe sous le verrou : l'oubli échoue du côté conservateur. La forme
inverse — un `--automatique` que la boucle passerait — échouerait dans le mauvais sens, car l'oubli
vaudrait alors « un humain regarde » pendant que personne ne regarde.

**Le motif général :** *la forme d'un appel ne dit rien de qui l'a lancé.* Chaque fois qu'un contrôle
infère une intention humaine d'un détail syntaxique, il infère faux dès qu'une machine adopte le même
détail.

**Le filet.** `app/tests/Platform/Unit/VerrouPremierPassageTest.php`. Vu rouge avant d'être cru vert :
l'ancienne dérivation remise une minute, `vente:cloture:journee` — clôture de journée, non sûre — est
passée de « premier passage » à `due` + « 1 tâche exécutée ». **Deux des trois tests sont restés verts
pendant ce sabotage**, et c'est le signe que le filet désigne bien ce qu'il annonce.

**La levée, qui vit ici et nulle part ailleurs (D53).** Le premier passage d'une tâche non sûre se
lance à la main avec `--only=<tâche> --supervise`, après avoir regardé `--dry-run` et `--status`. Le
message d'échec, lui, ne nomme plus aucune option : il imprimait `--only=<tâche>`, c'est-à-dire
exactement la porte qui s'ouvrait toute seule à chaque cycle.

---

## D110 — Une liste évaluée au démarrage doit crier quand le fichier a bougé

**Le même signalement de `allaccess-b8`, et c'est lui qui rendait D109 invisible.**

`infra/ordonnanceur.sh` évalue `TACHES_AUTORISEES=` **une seule fois, au démarrage**, puis boucle sur
la variable. Le 31/08, une quatrième tâche a été ajoutée au fichier à 15h34 ; le conteneur tournait
depuis 12h42. Résultat mesuré : **zéro occurrence dans tout le journal, trois lignes dans la table de
traces au lieu de quatre**, pendant neuf heures.

> ⚠ **Et c'est invisible par construction.** Chaque cycle imprimait trois `ok`. Trois succès se lisent
> comme un ordonnanceur en bonne santé — c'est le **quatrième, absent, qui ne crie pas**.

**On ne recharge pas à chaud, et c'est délibéré.** Relire la liste à chaque cycle ferait prendre effet
une édition du fichier monté, sans relecture ni commit. Comme D109 vient de l'établir, cette liste
blanche est le seul mécanisme qui protégeait réellement les quatorze tâches non sûres : elle doit
rester une décision versionnée. **Le défaut n'est pas qu'un redémarrage soit nécessaire — c'est que
rien ne le disait.**

La boucle compare donc, à chaque cycle, la liste que le shell porte à celle que le fichier déclare, et
imprime une erreur tant qu'elles divergent. `--lister` le dit aussi. Témoin des deux côtés : l'alarme
sonne sur la divergence **et se tait sur la concordance** — un détecteur qui crie toujours ne vaut pas
mieux qu'un détecteur muet.

**Où D109 et D110 se rejoignent.** Tant que la liste ne bougeait pas au démarrage, le court-circuit de
D109 ne se voyait pas. Corriger D110 seul — redémarrer pour activer la quatrième tâche — aurait donc
**levé le verrou sur tout ce qu'on aurait ajouté entre-temps**. C'est pour cela que D109 a été corrigé
et prouvé **avant** le redémarrage, et non l'inverse.
