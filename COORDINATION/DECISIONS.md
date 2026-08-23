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
