> ⛔ **ARCHIVE — canal retiré.** L'ancienne coordination artisanale (`ORDRES/`, `RAPPORTS/`, `TASKS.md`, `MESSAGES.md`, battement de 15 min) est **remplacée par branches + PR + Issues** — voir `CLAUDE.md`, section « Ce qui a été retiré ». Fichier conservé pour mémoire : **ne rien y écrire, ne pas le lire comme un canal actif.**

| 14:17 | **UI-3 : vue rapide du billet.** On saisit le numero imprime sur le billet ou la carte, on obtient le support, son etat, et son droit actif. **Ce qui s'affiche depend du type, et c'est le coeur de l'ecran** : une carte a quota se juge sur ses entrees restantes, un abonnement sur ses dates. Montrer les deux a chaque fois obligerait le lecteur a savoir lequel regarder — c'est le raisonnement du no-show, mais dans l'autre sens : la il fallait separer, ici il faut choisir. **Et D23 est respectee sans etre contournee** : si un support porte plusieurs droits actifs, l'ecran ne les liste pas comme une richesse — il affiche une alerte disant qu'une carte ne devrait en avoir qu'un, puisqu'une recharge incremente le droit existant. Un defaut se nomme, il ne se maquille pas. Etats vides utiles : « ce support existe mais aucun droit actif n'y est rattache : c'est le cas d'une carte vendue et pas encore chargee, ou d'un droit revoque ». **Appelees : 156 -> 160.** | Creer et modifier un role, puis creer un etablissement. | **MON PROPRE GARDE-FOU A FAILLI ACCUSER claude-D A TORT, et c'est l'evenement le plus instructif de ce lot.** Il a signale que `EditeurApp` rendait un `<Clients>` sans lui passer `droits`. **Faux** : l'editeur importe SON PROPRE `editeur/pages/Clients.jsx`, qui ne teste aucun droit. Mon controle comparait des NOMS sans resoudre les imports — deux composants peuvent porter le meme nom dans deux dossiers. J'aurais envoye quelqu'un chercher une faute imaginaire, **dans un perimetre qui n'est pas le mien**. Corrige : un usage n'est signale que si le composant rendu est bien celui qui teste un droit, import resolu. **Et en le corrigeant je me suis fait prendre une TROISIEME fois par le meme piege d'echappement** — `\s` dans un gabarit de chaine vaut `s`, donc ma resolution d'import ne trouvait jamais rien et le controle redevenait muet. Je n'ai pas corrige l'echappement une fois de plus : **j'ai retire toutes les expressions regulieres construites de ce fichier.** Trois fois le meme piege, c'est que le remede n'etait pas le bon. Verifie dans les deux sens : vert sur le code sain, rouge sur le vrai defaut, a la bonne ligne. |
| 00:35 | **CORRECTIF URGENT — Maxime etait bloque : « The content-type application/json is not supported ».** Cause : `api_platform.yaml` ne declare **aucun format**, donc toute operation API Platform **standard** — celle qui deserialise le corps — n'accepte que `application/ld+json`. Le client envoyait `application/json`. **Ce n'etait pas propre aux tarifs : 13 appels de creation etaient casses, et 7 sont anterieurs a mon arrivee** — creer un client, un utilisateur, un moyen de paiement, un groupe d'options, une valeur d'option, une option produit, une affectation. Personne ne l'avait signale, ou alors sous la forme « je n'arrive pas a... ». **Ma premiere version corrigeait les 40 POST du client, y compris ceux de la caisse qui FONCTIONNENT aujourd'hui en `application/json`. Je l'ai jetee.** Corriger un defaut en modifiant ce qui marche, sans pouvoir le retester, echange un bogue connu contre un risque inconnu — et la caisse est ce qui fait vivre l'exploitation. **Le discriminant est lisible dans le code serveur** : une operation declaree avec un `uriTemplate` sur mesure porte `input: false`, son processor lit le corps brut et se moque du type ; une operation standard deserialise et exige le format. J'ai donc croise les 40 appels avec les `uriTemplate` declares dans `app/src` : **13 standard corriges, 27 sur mesure laisses intacts.** `npm run build` et `npm run verifier-droits` verts. | Mesure demandee par A sur l'ampleur du piege, puis les roles. | **Ce defaut est le quatrieme de la meme famille cette semaine, et le plus couteux : une information que le code possede et qu'il ne dit pas.** Rien, dans la liste des operations, ne distingue celles qui exigent `ld+json`. On l'apprend par un 415, et seulement si quelqu'un essaie. Sur les 181 operations a brancher, je ne pouvais pas savoir lesquelles portent ce piege — **je viens de l'apprendre par Maxime, en production**. La parade que j'applique desormais : croiser chaque nouvel appel de creation avec les `uriTemplate` du serveur avant de l'ecrire. La vraie correction est une declaration globale de formats, et **A a raison de ne pas la poser a l'aveugle** : ajouter `json` aux formats changerait aussi la negociation en LECTURE, et mon extracteur de collections lit la cle `member`, qui est du JSON-LD. On reparerait l'ecriture en cassant toutes les listes d'un coup. |
| 10:25 | **Mesure demandee par A sur le piege des formats, et ma recommandation : NE PAS declarer les formats globalement.** Le chiffre : **236 ecritures standard exigent `ld+json`, 232 sur mesure s'en moquent** — la moitie de l'API est concernee, donc « passer le drapeau la ou il faut » ne se fait pas a la main de facon durable. Mais la declaration globale est pire, et A avait raison de ne pas la poser a l'aveugle : **ajouter `json` aux formats changerait aussi la negociation en LECTURE.** Les collections repondent en JSON-LD et tout le front lit la cle `member` ; on reparerait 236 ecritures en risquant toutes les listes d'un coup. **Troisieme voie, ecrite et prouvee : `frontend/scripts/verifier-formats.mjs`** (`npm run verifier-formats`, et `npm run verifier` lance les deux controles). Il croise chaque POST du client avec les `uriTemplate` declares dans `app/src` : un POST vers une operation **standard** sans `ld: true` est signale avec sa ligne et la raison. Il ne touche a rien cote serveur et rend la faute impossible a livrer. Verifie dans les deux sens : vert sur l'etat corrige, rouge sur exactement l'appel qui a bloque Maxime, a la bonne ligne. | Les roles, enfin. | **Ce que je retiens de la journee, et ce n'est pas le correctif.** Trois defauts en trois jours ont la meme forme : une information que le code possede et qu'il ne dit pas. La permission joker, le contrat de la recherche, et maintenant le format accepte. **A chaque fois j'ai devine au lieu de lire, et a chaque fois quelqu'un d'autre l'a decouvert en production.** J'ecris maintenant un controle plutot qu'une resolution : `verifier-droits` et `verifier-formats` verifient mecaniquement ce que je ne peux pas garantir par la vigilance. Ce sont les deux seules choses de la semaine dont je sois sure qu'elles tiendront quand je ne serai plus la pour y penser. |
| 10:40 | **Reponse a A sur le branchement de mes deux garde-fous : OUI, ils tournent dans le hook, et c'est verifie et non suppose.** Son objection etait juste : « un garde-fou facultatif n'est pas un garde-fou, c'est une documentation executable ». Mes deux scripts n'utilisent **que des modules natifs de Node** (`node:fs`, `node:path`, `node:url`) — aucune dependance, donc aucun `npm install`, donc aucun reseau. **Preuve executee** : `docker run --rm --network none ... node:20-alpine node scripts/verifier-{droits,formats}.mjs` -> les deux sortent en code 0 sur le code sain, **et en code 1 avec le message exact sur un defaut reintroduit**, sans `node_modules` et reseau coupe. L'image `node:20-alpine` est deja celle du deploiement, elle est presente sur le VPS. **Un point de branchement a ne pas manquer : `verifier-formats` lit `app/src` pour connaitre les `uriTemplate` du serveur. Le montage doit donc etre la RACINE du depot, pas `frontend/`**, avec `-w /repo/frontend`. Monte a `frontend/` seul, il ne verrait aucune operation sur mesure et signalerait 27 faux positifs — exactement le mode d'echec que j'ai deja commis une fois avec la resolution des imports. | Les roles. | **Et je corrige ce que j'ai ecrit hier soir.** J'affirmais que ces deux controles etaient « les deux seules choses dont je sois sure qu'elles tiendront quand je ne serai plus la ». **C'etait faux tant que rien ne les lance**, et A a eu raison de me le dire. Ils tiendront une fois dans `pre-receive` ; d'ici la ils dependent de ma memoire, ce qui est exactement le defaut qu'ils sont censes supprimer. C'est le meme motif que les dix-sept commandes sans ordonnanceur et que les plafonds jamais rabaisses : **le mecanisme existe, l'appel manque.** Je l'avais nomme trois fois cette semaine et je venais de le commettre. |
| 10:55 | **D44 livree : un seul produit, plusieurs tarifs.** claude-G a prouve par un test que le serveur accepte deja un `typeTarif` par ligne — deux lignes du meme produit a 5,50 et 4,00. **Toute la contrainte etait dans `Caisse.jsx` ligne 141**, qui devinait un tarif unique pour tout le panier. Consequence, et c'est ce qui rend ce defaut si couteux : un exploitant qui veut vendre au tarif enfant n'avait aucun moyen de le faire, alors il creait « Entree enfant » comme produit distinct. **Le manque ne se voyait pas comme un manque, il se voyait comme une habitude.** Deux principes de caisse respectes : **un clic reste un clic** — un produit a tarif unique s'ajoute exactement comme avant, on ne fait payer le choix qu'a ceux qui en ont un, une caisse se juge au nombre de gestes par vente ; et **deux tarifs font deux lignes** — le panier est indexe par produit ET par tarif, un adulte et un enfant du meme produit coexistent avec leurs quantites propres. Les fusionner obligerait a ressaisir, or c'est la vente courante d'une famille au guichet. Le tarif apparait sur la ligne du panier **et sur le ticket** : sans lui, deux lignes du meme produit a des prix differents sont illisibles pour le client comme pour le caissier qui le relit. `npm run build` et `npm run verifier` verts. | Historique des ventes : brancher les filtres date/client que claude-G vient de livrer. Puis les roles. | **Trois choses sur la maniere, parce qu'elles se repetent.** 1. **Mon ancre de remplacement indentee de 8 espaces etait contenue dans une ligne indentee de 20** — `count` compte des sous-chaines, pas des lignes. Le controle a refuse plutot que d'ecrire au mauvais endroit : c'est exactement ce qu'on lui demande, et c'est la deuxieme fois qu'il me sauve. Je suis passee en remplacement ligne entiere. 2. **`??` et `||` ne se melangent pas sans parentheses** — la compilation l'a attrape, elle. C'est la difference avec les quatre defauts silencieux de la semaine : une erreur de syntaxe se voit, une propriete absente non. 3. **claude-G m'a donne un avertissement que je n'aurais pas devine** : un `SearchFilter` pose sur une reference libre (`?Uuid`, pas une relation) ne leve rien et **rend une liste vide**, la valeur n'etant jamais convertie vers le type binaire. Si je vois un filtre serveur qui rend systematiquement zero, c'est cette famille-la. Note pour moi, et pour qui lira ce rapport. |
| 11:10 | **Deux corrections, l'une demandee par A, l'autre rendue possible par claude-G.** **1. Le message de `verifier-formats` ferme desormais la MAUVAISE correction.** Il disait quoi ajouter, pas pourquoi : quelqu'un qui n'a jamais entendu parler d'`input: false` aurait ajoute `ld: true` partout, **c'est-a-dire refait ma premiere version — celle que j'ai jetee parce qu'elle touchait la caisse.** Le message dit maintenant explicitement de ne pas le faire, et pourquoi. Remarque de A, et elle est juste : **un message d'echec doit fermer la mauvaise correction, pas seulement ouvrir la bonne.** Verifie en situation. **2. L'historique des ventes filtre enfin par date.** claude-G a livre `order[date]`, `date[after]`, `date[before]`. J'avais refuse de filtrer en memoire et affiche franchement « la recherche par date n'est pas possible » ; elle l'est. **Le plus important des trois n'etait pas le filtre mais le TRI** : sans `order[date]`, « les cinquante dernieres ventes » n'etait pas une promesse qu'on pouvait tenir — l'ordre etait celui que la base rendait — **et je l'affichais pourtant.** J'ai corrige le libelle en meme temps que le code. Et j'ai reecrit le commentaire d'en-tete qui expliquait l'impossibilite : un commentaire qui survit a sa propre correction devient un mensonge que quelqu'un lira comme une contrainte. | Les roles. | **La lecon que A me transmet et que je note pour tout ce que j'ecrirai ensuite** : son filet de completude, cense verifier que mes deux controles sont bien appeles, cherchait `verifier-droits.mjs` alors que sa boucle ecrivait `verifier-droits` sans extension. **Il aurait declare mes controles « appeles par aucun hook » et fait refuser toutes les poussees, indefiniment.** Le filet se serait referme sur ce qu'il protege. C'est le deuxieme auto-blocage du meme mecanisme en deux jours. **Un mecanisme qui verifie les autres mecanismes doit etre verifie contre lui-meme, et c'est le controle qu'on oublie le plus — parce que son echec ne ressemble pas a un echec, il ressemble a un depot qui refuse tout.** J'ecris des controles maintenant : ca vaut pour moi en premier. |
| 11:35 | **Lot 6 : creer et modifier un role.** Quatre operations — creer, modifier, dupliquer, supprimer — qui n'avaient aucun bouton **sur l'ecran qui s'appelle « Utilisateurs et droits »**. C'est de la securite, et l'ecran est concu pour ca, pas seulement branche. **1. Les droits sont groupes par module, jamais en liste plate** — ta consigne, et elle est juste : deux cents cases a la suite ne se lisent pas, on coche par fatigue, et personne ne peut dire ensuite ce qu'un role autorise. Par module, on lit « ce role touche a la caisse et au catalogue » d'un coup d'oeil, avec un compteur coches/total et un « tout / rien » par module. **2. Le JOKER est traite a part, en haut, avec un avertissement rouge.** Une permission dont le module est `*` donne ce droit sur **tous** les modules, y compris ceux qui n'existent pas encore. Noyee au milieu des autres, elle se coche comme n'importe quelle case et ouvre tout. **C'est exactement ce qui rendait l'administrateur invisible a mon propre filtre de menu il y a deux jours** : je cherchais `caisse.lire`, il portait `*.lire`. Le meme joker, vu depuis l'autre bout. Et la liste des roles affiche « tous les modules » en rouge pour un role qui le porte. **3. Un role modele ne se modifie pas** : le socle les installe, les alterer changerait le point de depart de tous les etablissements. On propose de les dupliquer, ce que le serveur sait faire. **4. La suppression dit ce qu'elle casse** : « tous les comptes qui le portent perdront ces droits immediatement, sans preavis et sans qu'on puisse dire lesquels etaient concernes apres coup ». **Appelees : 160 -> 167 (16 %).** `npm run build` et `npm run verifier` verts — et ce sont maintenant tes hooks qui le verifient, pas ma memoire. | Creer un etablissement, puis la patinoire. | Rien de bloquant. |
| 12:05 | **Lot 7 : creer et modifier un etablissement.** Branche en reutilisant `ReferentielEditable` — c'est le premier lot ou le composant ecrit hier n'a rien coute a reutiliser, et c'etait l'objet de l'ecrire. **UN CHOIX QUE JE SIGNALE PARCE QU'IL VA A L'ENCONTRE DE L'API : je n'expose PAS la suppression, alors que `Delete` existe.** Un etablissement n'est pas une ligne de referentiel : c'est le perimetre auquel tout le reste est rattache — ventes, clients, caisses, droits d'acces, comptabilite. Le supprimer ne retire pas une entree, ca coupe le rattachement de tout ce qui a ete fait dessus. Le serveur refusera sans doute, contraintes obligent, **mais un bouton qui echoue une fois sur deux enseigne surtout qu'on peut reessayer**. Ce qu'un exploitant veut reellement, c'est **desactiver** : le site cesse d'etre propose, l'historique reste consultable, et c'est reversible. Une fermeture definitive, si elle doit exister, merite un geste dedie avec ses propres avertissements — pas la meme croix que pour un taux de TVA. **Deux pieges silencieux corriges au passage**, et ils valent pour tout branchement de relation : une relation arrive du serveur en **objet** (`{id, nom}`) alors que le formulaire manipule une **IRI** — sans transformation, le champ s'affiche vide et l'enregistrement **efface la valeur existante sans que personne ne l'ait demande** ; et un choix vide doit partir a `null`, pas en chaine vide, que le serveur refuse avec un message de deserialisation illisible. J'ai ajoute deux crochets explicites au composant (`versValeur` / `versCorps`) plutot que de bricoler dans le descripteur : le prochain qui branche une relation trouvera le chemin trace. **Appelees : 167 -> 169 (16 %).** | La patinoire : affutages, locations de patins, liste d'attente. | Rien de bloquant. |
---

## Annexe — matière pour SOC-0, écrite depuis l'implémentation

> Écrite par `claude-H` dans son propre fichier, comme le reste de ce rapport. `specs/social/**`
> appartient à `claude-A` : ceci n'est pas une spécification, c'est ce que trois lots d'implémentation
> ont appris et qu'il faut y verser. Chaque point porte la raison qui l'a produit — sans elle, une
> règle se relit comme une préférence et se contourne à la première contrariété.

### 1. Le modèle : un post, N publications

Un message est rédigé une fois et porte N lignes, une par réseau visé. Chaque ligne a son état, son
identifiant distant, son adresse web, son code d'erreur brut et son nombre de tentatives.

**Pourquoi ce n'est pas négociable.** Cinq réseaux, trois qui passent, un quota dépassé, un jeton
expiré : c'est le cas *normal*, pas l'exception. Un modèle qui ne saurait dire que « publié » ou
« échoué » perdrait exactement l'information qu'on cherchera le jour de l'incident. Et c'est cette
ligne qui portera les statistiques — donc la jointure entre ce qu'on a publié et ce que ça a rempli.

L'état du message est un **résumé recalculé** depuis les lignes, jamais une vérité posée à la main.
Deux sources qui peuvent diverger sur le même fait finissent toujours par diverger.

### 2. Le coffre à jetons

- Chiffré au repos (libsodium, nonce par message), clé dédiée `SOCIAL_TOKEN_ENCRYPTION_KEY`, **aucun
  repli codé en dur** : conteneur qui refuse de démarrer si elle manque.
- **Un jeton ne sort jamais** : aucun groupe de sérialisation sur les champs chiffrés, seul un booléen
  « y a-t-il un jeton » est lisible. Le champ en clair est transitoire et vidé dans le processor.
- **La valeur stockée porte l'identifiant de la clé qui l'a chiffrée** (`v<n>:<charge>`). Sans cela,
  changer la clé obligerait chaque établissement à repasser l'autorisation de chaque réseau — donc
  personne ne la changerait jamais, donc on ne pourrait pas la révoquer le jour où elle fuit.
- **Révoquer, c'est cesser de détenir** : les jetons ne sont effacés que sur révocation explicite. Sur
  une simple expiration on les garde — c'est le jeton de rafraîchissement qui permettra de se rétablir
  sans redemander à l'utilisateur de tout reconnecter.

**Ce que le mécanisme de rotation demandera** (le format est posé, le mécanisme ne l'est pas) :
une variable portant les anciennes clés en déchiffrement seul ; la bascule de la version courante
**dans le code** et non dans l'environnement, parce qu'une rotation est un acte délibéré qui se relit
et se date ; une commande de rechiffrement par lots, reprenable et idempotente ; une commande de
statut affichant la répartition par version, pour vérifier **avant** de retirer une clé plutôt que de
découvrir la perte au premier envoi ; et la règle qu'on ne retire une clé qu'une fois le compteur à
zéro. Aucune de ces commandes ne journalise un jeton — seulement des compteurs.

### 3. Le cloisonnement : trois portes d'entrée, pas une

C'est le point où ce module peut échouer silencieusement, et il a trois portes distinctes :

1. **L'établissement d'un compte ou d'un message** : toujours dérivé de la session serveur, jamais du
   corps de la requête. Hors de tout groupe d'écriture.
2. **Les comptes visés par un message** : ils arrivent, eux, du client. Chacun est revérifié un par un
   contre le périmètre actif. Ne pas se reposer sur le fait que la résolution des IRI passe déjà par le
   cloisonnement — *un contrôle qu'on ne voit pas dans le code est un contrôle qu'on supprimera sans le
   savoir*.
3. **L'identifiant qui arrive dans un message asynchrone** : il n'y a là aucune session dont dériver un
   périmètre. Le handler rétablit le contexte depuis l'entité résolue et vérifie l'invariant — le
   compte visé appartient au même établissement que le message. Sans ce contrôle, la file devient un
   chemin de contournement du cloisonnement.

Échec en **404**, jamais 403 : un 403 distinguerait « existe, pas à toi » de « n'existe pas », donc
énumérerait les comptes sociaux des autres établissements.

### 4. Le travail sortant

- Les messages sont dépêchés **après le commit**. Avant, on met en file du travail pour un fait qui
  peut ne jamais avoir eu lieu.
- Le message ne porte **qu'un identifiant**. Y recopier le jeton le ferait dormir en clair dans la
  table de la file, donc dans les sauvegardes ; y recopier l'état figerait une situation que le compte
  a pu quitter entre-temps.
- **Rejouer ne republie pas** : une file redélivre, c'est sa nature. Une publication déjà terminale est
  ignorée.
- **L'état terminal ne dépend pas de la configuration de la file.** Le compte de tentatives vit dans la
  donnée. Sinon une publication épuisée resterait « en attente » pour toujours en base, visible nulle
  part — et un futur ajustement des reprises changerait silencieusement la sémantique de l'historique.
- **Une erreur réessayable repasse en attente, pas « en cours »** : si le worker meurt avant la
  reprise, l'état en base doit dire qu'il reste quelque chose à faire.
- **Réessayable ou définitif est la distinction qui rend l'asynchrone tenable.** Réessayer cinq fois un
  jeton révoqué ne le rendra pas valide : cela retarde de vingt minutes une erreur corrigeable tout de
  suite et consomme le quota pour rien. À l'inverse, marquer définitivement échoué un dépassement de
  quota perdrait une publication qui serait passée dix minutes plus tard.
- Le code d'erreur est **celui du réseau, tel quel**. Un code réécrit en vocabulaire maison fait
  diverger le diagnostic de ce que la documentation du réseau permet de chercher.

### 5. Ce que les réseaux imposent, et qui n'est pas symétrique

| | Mastodon | Bluesky |
|---|---|---|
| Ce que le coffre stocke | jeton d'accès OAuth | **mot de passe d'application** |
| Hôte | **obligatoire** (fédéré : le jeton ne vaut que pour son instance) | facultatif (`https://bsky.social` par défaut) |
| Longueur | 500 caractères | **300 caractères** |
| Publication | un appel | **deux** (ouverture de session, puis écriture) |
| Idempotence | `Idempotency-Key` honorée | **aucune** |

**Deux conséquences à écrire noir sur blanc dans la spec :**

- **Bluesky peut produire un doublon.** Sans clé d'idempotence, une coupure survenue après l'écriture
  mais avant la réponse republie à la reprise. On limite le risque en ne réessayant que sur transport,
  quota et panne serveur — jamais sur un refus applicatif — mais on ne le supprime pas. C'est une
  propriété du réseau, pas un défaut de notre code : elle doit être écrite plutôt que découverte par un
  client qui verra son message paraître deux fois.
- **Le mot de passe d'application de Bluesky est une bonne propriété**, pas un pis-aller : il est
  révocable individuellement, donc le retirer à un établissement ne coupe pas son compte.

**La longueur est refusée à la rédaction, par réseau visé** — elle est connue d'avance. La découvrir à
l'envoi produirait un message paru sur trois réseaux et refusé sur un quatrième pour une raison que
l'auteur corrigeait en dix secondes. Et **on ne tronque jamais** : personne n'a le droit de raccourcir
le texte de quelqu'un d'autre sans le lui dire.

### 6. Les statistiques (SOC-3)

Les plateformes ne conservent pas l'historique et redéfinissent leurs métriques entre versions d'API.
D'où deux exigences liées : **des instantanés planifiés dès le premier jour** — sans eux l'historique
n'existera pas et sera irrattrapable — et **la charge brute conservée en plus de la vue normalisée** —
sans elle, une redéfinition de « portée » rendra le passé incomparable.

Le nom des métriques diffère par réseau (`favourites` / `likeCount`, `reblogs` / `repostCount`). La vue
normalisée est donc une **interprétation**, et c'est précisément pour cela qu'on garde ce qu'on a reçu.

### 7. Ce qui reste dehors

Les adaptateurs Meta (Page Facebook, Instagram) attendent l'immatriculation de la société : consignés
au registre des bloqueurs externes, jamais attendus. Aucune valeur d'énumération n'est déclarée pour un
réseau qu'aucun adaptateur ne sait servir — cela donnerait un compte connectable et jamais publiable.

Publier une image sur Instagram exigera par ailleurs une URL publiquement accessible, donc la GED.

### 8. Points que la spec doit trancher, et que l'implémentation ne peut pas décider seule

1. **La modification d'un message déjà parti.** Aujourd'hui : interdite. Modifier le texte d'un message
   paru sur trois réseaux ne le modifierait sur aucun des trois, mais changerait ce que la plateforme
   prétend avoir publié. Reste à décider si un brouillon dont aucune publication n'est engagée peut
   être corrigé — je pense que oui, et c'est un lot séparé.
2. **La suppression d'une publication chez le réseau.** Non traitée. Supprimer chez nous sans supprimer
   là-bas ferait mentir l'historique dans l'autre sens.
3. **Le devenir des publications d'un compte révoqué.** Aujourd'hui elles restent, avec leur historique.
4. **La périodicité des instantanés** et leur durée de conservation : ce sont des données personnelles
   agrégées, la question de la purge se pose.
5. **Ce que voit l'éditeur sur son propre établissement** par rapport à ce que voit un client : le même
   code, mais peut-être pas les mêmes tableaux.

---

## Annexe — l'écart entre ce que l'API offre et ce que le front utilise

> Mesuré le 25/08/2026 par `claude-H`, à la demande de `claude-A`. Méthode et chiffres reproductibles :
> le script est `/tmp/ecart_api_front.py` sur le VPS, il part de `debug:router` et non des attributs
> des entités — inférer une URL depuis un nom de ressource est le piège qui m'a coûté deux erreurs
> cette nuit.

### Le chiffre

**1 042 opérations d'API exposées. 135 appelées par le front. 13 %.**

Mais ce total mélange deux problèmes qui ne se règlent pas de la même façon, et c'est la séparation
qui répond à la question posée :

| | Ressources | Opérations exposées | Appelées | **Non appelées** |
|---|---|---|---|---|
| **Écran existant** (au moins un appel) | 64 | 322 | 135 | **187** |
| **Aucun écran** | 218 | 720 | 0 | 720 |

### La réponse à « treize écrans ou trente opérations »

**Ni l'un ni l'autre : 181 opérations à brancher, et 720 à construire.**

Sur les 187 non appelées d'écrans existants, **6 seulement sont des points d'entrée machine** —
poussée de passages par un contrôleur, ventes d'un connecteur OTA, activation publique de compte.
Les 181 autres sont des gestes d'exploitation que quelqu'un devrait pouvoir faire et ne peut pas.

Réparties en 90 créations, 67 lectures, 24 modifications, 6 suppressions.

### Ce que 181 veut dire concrètement

Les manques les plus lourds, sur des écrans que Maxime ouvre tous les jours :

- **On ne peut pas modifier un produit.** `PATCH /api/produits/{id}` n'est appelé nulle part. On peut
  en créer un, le publier, l'archiver — pas corriger une faute de frappe dans son libellé.
- **L'historique des ventes n'existe pas.** `GET /api/ventes` n'est jamais appelé. Ni consultation,
  ni recherche d'un ticket passé.
- **On ne peut pas rembourser.** `POST /api/ventes/{id}/rembourser` existe et n'a aucun bouton.
- **Le no-show n'a aucun écran.** `facturations-no-show/{id}/emettre-vente` et `/exonerer` ne sont
  appelés nulle part. C'est une décision produit de Maxime (D27), implémentée côté serveur, et
  invisible côté client.
- **Les absences du personnel ne peuvent être ni validées ni refusées** (16 opérations manquantes sur
  cet écran).
- **On ne peut ni créer ni modifier un rôle**, sur l'écran qui s'appelle « Utilisateurs et droits ».
- **On ne peut pas créer un établissement** depuis l'application.
- **Patinoire** : affûtages, locations de patins, liste d'attente — sept opérations, c'est-à-dire
  l'essentiel du métier d'une patinoire, sans interface.

### Ce que j'en conclus, et ce que je ne conclus pas

**Ce que je conclus.** Le motif que j'avais senti sur trois écrans se vérifie sur soixante-quatre :
ce dépôt n'a pas un problème de fonctionnalités manquantes, il a un problème de fonctionnalités
inaccessibles. Les quatre branchements de cette nuit — publier/dépublier, les référentiels, les points
de vente, les champs de la fiche client — ont chacun répondu à une frustration exprimée par Maxime
sans qu'il sache que la fonction existait déjà. **Il en reste 181 de la même nature.**

Et le rapport de coût est franc : brancher une opération sur un écran qui existe se compte en dizaines
de minutes. Construire un écran se compte en jours. Les 181 valent probablement moins cher, en temps
total, que trois des treize écrans manquants.

**Ce que je ne conclus pas.** Que les 181 soient toutes prioritaires, ni que les 720 puissent
attendre. Un module sans écran — le Stock, la Facturation, la Trésorerie — n'a pas 0 % d'usage parce
qu'on a négligé de brancher : il n'a rien du tout, et pour un exploitant c'est une absence bien plus
visible qu'un bouton manquant. Les deux chantiers sont réels. Ce chiffrage dit seulement lequel rend
le plus par heure passée, et il dit qu'on l'a sous-estimé.

**Une limite de la mesure, à connaître avant de s'en servir.** Elle compare des routes à des appels
écrits dans `api/client.js`. Un appel construit dynamiquement lui échapperait ; je n'en ai pas trouvé,
mais je ne peux pas le garantir. Et elle ne dit rien de la *qualité* de ce qui est branché : les 135
opérations appelées incluent celles que j'ai corrigées cette nuit parce qu'elles étaient appelées de
travers.
