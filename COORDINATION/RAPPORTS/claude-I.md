# Rapports de `claude-I`

> **Écrit par `claude-I` seul.** claude-A le lit, ne l'écrit jamais.
> Une ligne par battement, la plus récente **en bas**.

| Heure | Fait | En cours | Bloqué par |
|---|---|---|---|
| 18:26 | Session ouverte. Lu FLOTTE, PLAYBOOK, DECISIONS (D15/D16/D5/D13), ORDRES/claude-I. Worktree monte, inventaire des 5 verticales fait (238 fichiers, 81 entites d'API). | Je prends le volet **cles de vocabulaire** (ACT-0 applique aux verticales) : inventaire du vocabulaire en dur dans Piscine/Padel/Patinoire/Sport/Musee, puis catalogue de cles dans `specs/verticales/`. | Trois points d'arbitrage, detail ci-dessous. Je n'attends pas : j'enchaine sur l'inventaire. |
| 18:30 | Catalogue de 12 cles de vocabulaire pour les 5 verticales (specs/verticales/vocabulaire.md). Inventaire de composition des 5 verticales fait : **les neuf briques de D15 tiennent**, aucune n-a reclame un dixieme type ; ~2/3 de chaque verticale devient du paquet redige, 1/3 reste une regle propre (POSS, tournoi, saison ephemere, SOS, OTA). | J-attaque le point non negociable de D15 : identifiants stables + non-ecrasement sur les 5 DataFixtures. | Rien. Les 3 arbitrages du message ci-dessous restent ouverts mais ne me bloquent pas encore. |
| 21:20 | Format de paquet verticale redige (specs/verticales/paquet.md) + premier paquet **swimming-pool** comme banc d-essai. Deux trouvailles : une ligne supprimee par le client ne doit pas ressusciter (3e etat de la table de correspondance), et  `noupdate: false` n-a trouve **aucun** emploi sur la piscine -> le defaut `noupdate: true` est confirme. D32 lu et note. | Je redige les quatre paquets restants : padel, ice-rink, fitness, museum. | Rien. Arbitrages toujours ouverts (ACT-0, resolveur i18n, xid du socle) — je continue sans. |
| 21:22 | **Les cinq paquets verticales sont rediges** : swimming-pool, padel, ice-rink, fitness, museum (specs/verticales/). Trois trouvailles : (1) trois modules portent des `Uuid` nus vers Offre -> le padel n-est aujourd-hui **pas installable** sans qu-un humain recopie trois UUID, ce qui rend la table `xid` indispensable et non facultative ; (2) correction de mon inventaire : la visite guidee du musee n-est pas du code, c-est `competenceRequise` (D16) — un module de code de moins ; (3) deux chevauchements avec Smart Flow, signales ci-dessous. | J-attends tes arbitrages pour convertir ; en attendant je passe a la revue de coherence des cinq paquets contre les enums et entites reelles. | Rien qui m-arrete. |
| 21:35 | Revue de coherence des cinq paquets contre les entites et enums reelles : **six erreurs dans ma propre redaction**, corrigees. Deux ecarts noyau/verticale trouves au passage (periodicite hebdomadaire, preavis de resiliation) — detail ci-dessous. Regle qui en sort : on ne seme que ce qu-un exploitant retrouverait vide au premier matin, jamais une donnee transactionnelle. | Prochaine tache de mon perimetre : les cles de vocabulaire des quatre paquets manquants sont ecrites, je passe a la verification que chaque cle a bien un point d-affichage reel (sinon le catalogue est du decor). | Rien. |
| 21:45 | Verification des points d-affichage des 12 cles de vocabulaire contre le frontend reel. **Quatre cles sont vivantes, toutes les quatre dans `Reservation.jsx`** (Ressource, Reservation, Capacite, Acces) — quatre libelles, un fichier, et l-ecran cesse d-etre ecrit pour un metier generique que personne n-exerce. Les huit autres n-ont aucun point d-affichage : les ecrans de verticale sont des souches de 46 a 69 lignes. Ta correction sur l-alias SSH est lue — j-avais trouve `vps-preprod` de mon cote, c-est coherent. | Je passe a la relecture des cinq paquets pour en extraire ce qui est deja portable sans installateur. | **Un trou de propriete** : `frontend/` n-est attribue ni par FLOTTE.md ni par OWNERS.md. Detail ci-dessous. |
| 21:45 | D34 lue et appliquee. **Presentation horaire mise en place** : Maxime me demande de me presenter a toi toutes les heures quoi qu-il arrive — tache en cours si j-en ai une, demande de tache sinon. C-est desormais dans ma boucle, au meme titre que le battement. | Presentation 21:45 ci-dessous. Tache en cours : relecture des cinq paquets pour extraire ce qui est portable sans installateur. | **Ta session n-est pas joignable depuis ce poste** — detail ci-dessous. |
| 22:20 | **Cinq manifestes de module livres** (`PiscineModule`, `PadelModule`, `PatinoireModule`, `SportModule`, `MuseeModule`) — aucune des cinq verticales n-en avait, alors que Dms, Finance, Ocr, SmartFlow, Social et Stay en ont un. D2 est contract-first : ces cinq modules etaient invisibles au registre. `tests/Platform` **58/58 vert** sur pile isolee `claudeI` (VPS). D35 lue. | Suites des cinq modules en cours d-execution. | Rien. Trois constats a arbitrer ci-dessous — dont un qui donne enfin un point de chute a la suppression de `PresetVerticale`. |
| 22:40 | Suites des cinq verticales passees. Piscine, Patinoire, Sport, Musee **vertes**. Padel : **1 echec, preexistant sur `origin/main`** (verifie en rejouant le test sur main, pas suppose) — et je tiens la cause exacte : le test ne tombe **que le lundi**. Diagnostic complet ci-dessous. Pile `claudeI` demontee, worktree VPS rendu a sa branche. | Je prepare le correctif ; il touche du code de production, donc je te laisse une fenetre d-objection avant de le poser. | Rien. |
| 23:00 | **Padel repasse au vert** : 23 tests, 228 assertions. `tests/Platform` toujours 58/58. Le correctif ne prend aucune constante de reglage — deux regles physiques suffisaient, detail ci-dessous. Les cinq verticales sont donc vertes, manifestes compris. Pile demontee, worktree VPS rendu. | Je reprends le fil des paquets : ce qui est livrable sans installateur. | Rien. |
| 25/08 09:05 | **Silence de 22:52 a 09:05 : ma session ne s-est pas reveillee**, je le dis plutot que de le masquer. Reprise : ordres longs lus. Balayage D40 fait sur mes quatre autres modules — **aucun autre basculement**, detail ci-dessous. D41 verifie en profondeur : mes 9 entites exposees le sont bien, mais mes ~30 processeurs a persistance directe sont **deja corrects** via `ContexteEtablissement`. | Item 2 de mes ordres : `PrioriteOtaResolver` qui passe en `AnnuleeLibre` sans rien restituer. | Rien. |
| 25/08 09:45 | **Item 2 livre : `PrioriteOtaResolver` restitue.** Le billet perdant recupere son credit de carte (CQ-3/CQ-6), son droit d-acces est revoque, et la jauge de la ressource mere est liberee — il ne faisait aucun des trois. Test ecrit **verifie rouge sans la garde** (0 au lieu de 1) puis vert. Musee 23/23, Platform 62/62. Pile demontee, worktree rendu. | Item 5 : reprise des paquets verticales. | Rien. |

---

## 2026-08-24 18:26 · Prise de poste — trois arbitrages demandes a claude-A

**Je suis l'instance de bureau.** Je pousse sur **`claude-I-desktop`**, pas sur `claude-I` : si Maxime
ouvre le terminal 6 du runbook sur le VPS, deux sessions porteraient la meme branche et le meme
rapport (regle 4). A toi de trancher laquelle des deux garde le nom ; en attendant je reste suffixee
et tu fusionnes depuis `claude-I-desktop`.

**1. ACT-0 est chez toi et n'est pas livre.** Le carnet donne `ACT-0` (spec de composition d'activites,
format de paquet verticale, remplacement de `Metier`) en `CLAIM` sur **claude-A** depuis le 22/08. Or
mon ordre me demande de convertir les cinq verticales *vers le modele de D15* — je ne peux pas
convertir vers un format qui n'est pas ecrit. Deux sorties, dis-moi laquelle :
  - (a) tu livres ACT-0 et je consomme le format ;
  - (b) tu me delegues la redaction du **format de paquet verticale** dans `specs/verticales/`, et tu
    gardes le remplacement de `Metier` — c'est ton perimetre de toute facon.
J'ai une preference pour (b) : les cinq verticales sont le seul banc d'essai reel du format, et un
format ecrit sans elles se reecrira.

**2. Il n'y a aucune infrastructure i18n dans le depot.** Pas de `app/translations`, aucun service de
traduction dans `app/src` — D5 la suppose, personne ne l'a posee. Les cles de vocabulaire n'ont donc
pas de resolveur. Le resolveur est du noyau, donc **a toi** ; le catalogue de cles par verticale est
**a moi**. Je commence par le catalogue, qui ne depend de rien. Confirme que le resolveur est bien
dans ton perimetre et pas dans le mien — je ne le poserai pas sans ton accord (regle 2).

**3. `Metier` et `PresetVerticale` sont dans `app/src/Fonctionnalite`, donc chez toi.** Cites par
`Fonctionnalites.php`, `AppliquerPresetProcessor.php` et `FonctionnaliteFixtures.php`. Je ne les touche
pas. Quand tu les retireras, previens-moi au battement d'avant : mes cinq paquets devront etre prets a
prendre le relais le meme jour, sinon la conversion se fait fenetre ouverte.

**Ce que je fais sans attendre de reponse** : inventaire du vocabulaire en dur des cinq verticales
(libelles, enums exposees, noms de ressources d'API), qui est le prealable des trois sorties possibles.

---

## 2026-08-24 21:22 · Deux chevauchements avec le perimetre de claude-E, a arbitrer avant qu-il n-implemente

Deux verticales portent chacune un morceau de Smart Flow, ecrit avant lui :

- **Patinoire / `ListeAttentePointure`** — inscription, promotion quand une pointure rentre,
  proposition de la pointure voisine. C-est une **liste d-attente**, sujet de `SF-2`.
- **Musee / `PolitiqueDelestage`** — file d-attente sur place, redirection de parcours, alerte seule.
  C-est de la **gestion d-affluence**, quatrieme sujet de `SF-0`.

Je ne touche ni l-un ni l-autre : ce n-est pas mon perimetre (regle 2), et ce n-est pas non plus a
`claude-E` de venir les chercher. **La question est pour toi**, et elle vaut mieux posee maintenant
qu-a la collision : soit Smart Flow les absorbe et deux de mes paquets perdent un module de code, soit
ils restent locaux et `claude-E` doit savoir qu-il a deux implementations concurrentes en face de lui.

Rappel des trois arbitrages du battement de 18:26, toujours ouverts : ACT-0 (j-ai redige le format,
adopte-le ou remplace-le), le resolveur i18n (noyau, donc chez toi), et l-`xid` du socle
(`core.space.main` n-existe pas — sans lui aucun de mes cinq paquets n-est installable).

---

## 2026-08-24 21:35 · Deux ecarts entre le noyau `Offre` et la verticale fitness

Trouves en verifiant mes paquets contre le code, pas en les redigeant. `Offre` est le perimetre de
`claude-G` : je ne corrige pas, je signale.

- **Periodicite hebdomadaire.** `PeriodiciteAbonnementFitness` propose `mensuel` et `hebdomadaire`.
  `Offre\PeriodiciteFormule` ne propose que `mensuel`, `annuel`, `personnalise`. Un abonnement
  hebdomadaire n-est donc exprimable cote offre que par `personnalise` — invisible a tout ce qui
  raisonne sur la periodicite : facturation, relance, prorata.
- **Preavis de resiliation.** `preavisResiliationJours` est porte par `AbonnementFitness`, donc par
  chaque abonnement souscrit, jamais par la formule. Un paquet ne peut pas semer « 30 jours de
  preavis » comme defaut de l-offre : il n-y a aucun endroit ou l-ecrire.

Les deux preexistent a ma conversion. Mon paquet `fitness` reste redige avec `mensuel` seul tant que
ce n-est pas tranche — je prefere un paquet incomplet a un paquet qui ment.

**Sur ma propre rigueur** : la revue a trouve six erreurs dans cinq fichiers que je venais d-ecrire
(champ `code`/`label` inexistant sur `TypeTarif`, valeur d-enum inventee sur le padel et le musee,
`Formule` semee comme si elle avait un nom, gratuites semees alors qu-elles sont transactionnelles).
Aucune n-aurait ete vue a la relecture du texte seul. Je continue a verifier chaque champ contre le
code avant de considerer un paquet comme redige.

---

## 2026-08-24 21:45 · `frontend/` n-appartient a personne, et c-est la que se joue le vocabulaire

Deux resultats de la verification, dans l-ordre d-importance.

**1. Le vocabulaire de metier ne se joue pas dans les ecrans de verticale — il se joue dans les ecrans
generiques.** Les pages par verticale sont des souches (`Musee.jsx` 46 lignes, `Padel.jsx` 47,
`Patinoire.jsx` et `Piscine.jsx` 69). Les ecrans reels du produit sont generiques : `Caisse` 618
lignes, `Parametres` 768, `Catalogue` 478, `Reservation` 266. C-est coherent avec D13, et cela veut
dire qu-une verticale ne doit pas plus devenir un jeu d-ecrans paralleles qu-elle ne devait rester un
module de code.

**2. Quatre libelles en dur dans `frontend/src/pages/Reservation.jsx`** — Ressource, Reservation,
Capacite, Acces — sont le meilleur rapport travail/effet de tout mon perimetre. Un padeliste y lit
« Ressource » la ou il devrait lire « Terrain ».

**Et personne n-a le droit d-y toucher.** `frontend/` n-est attribue ni dans FLOTTE.md ni dans
OWNERS.md : les neuf perimetres sont tous en `app/src/**`, `specs/**`, `bin/`, `hooks/`, `vitrine/`.
Le correctif tient en quatre lignes et je ne le fais pas — c-est exactement le cas ou `claude-C` avait
raison de refuser, et seul Maxime deplace un perimetre.

Trois sorties possibles, a toi de la porter a Maxime : (a) `frontend/` m-est attribue pour les cles de
vocabulaire seulement ; (b) il revient a une session dediee ; (c) il reste hors flotte et le catalogue
attend. Je continue sur mes paquets dans les trois cas.

---

## 2026-08-24 21:45 · Presentation horaire n°1 (D34) — et une limite de Remote Control a connaitre

**`claude-I` en ligne.** Tache en cours : relecture des cinq paquets verticales pour en extraire ce qui
est deja portable sans installateur. Rien ne m-arrete.

**Ce que D34 suppose et qui n-est pas vrai depuis ce poste.** Tu ecris « liste tes pairs, trouve la
session `claude-A`, ecris-lui ». Je l-ai fait, deux fois, a 21:20 et a 21:44. Le poste sur lequel je
tourne ne voit que **deux sessions : `B` et `E`** (les deux actives a l-instant). **Ta session n-y est
pas** — tu travailles depuis un autre poste, et la messagerie inter-sessions ne franchit pas cette
frontiere. Elle est locale a une machine, pas a la flotte.

Ce n-est pas un manquement de ma part et ce n-est pas une objection a D34 : c-est une limite qu-il faut
connaitre, sinon une session de bureau conclura qu-elle t-a perdu alors qu-elle ne t-a jamais eu a
portee. **Le seul canal qui te joint depuis ici est le depot**, et il marche : tu as repondu a 21:31
puis a 21:39 pendant que je travaillais. Je m-y presenterai donc toutes les heures, ici meme.

**Deux consequences que tu peux exploiter :**
1. Si tu veux pouvoir *rappeler* les sessions de ce poste, c-est `B` ou `E` qu-il faut joindre — elles
   se voient entre elles. Moi je vois les deux. Dis-moi si tu veux que je serve de relais, et lequel :
   je ne le fais pas de ma propre initiative, ce n-est pas mon perimetre.
2. `B` et `E` **tournent en ce moment**. Si ton compte de sept sessions arretees date de 20:50, il est
   perime d-au moins deux unites.

**Rappel de ce qui t-attend chez moi**, par ordre de cout pour les autres : l-attribution de
`frontend/` (quatre libelles, un fichier, personne n-a le droit d-y toucher), les deux chevauchements
Smart Flow avant que `claude-E` n-implemente, puis mes trois arbitrages de 18:26.

---

## 2026-08-24 22:20 · Les manifestes sont le point de chute de `PresetVerticale` (D15)

Trois constats, dans l-ordre d-importance pour toi.

**1. Ce que le preset accorde, le manifeste peut le porter — et c-est ce qui debloque D15.**
`PresetVerticale::CAPACITES` est une carte metier -> capacites, figee dans `App\Fonctionnalite`.
D15 la supprime, mais rien ne disait ou son contenu devait atterrir. Reponse : dans les `features()`
des manifestes, la ou `ModuleAccess::hasFeature()` sait deja les lire. Je viens de le faire pour les
codes qui le permettent. **Tu peux donc retirer `PresetVerticale` sans perdre son information** — mais
lis les deux points suivants avant, ils limitent la portee.

**2. Deux codes sont partages entre deux verticales, et le modele ne sait pas l-exprimer.**
`casiers` et `encadrants` sont accordes **a la fois** a la piscine et a la patinoire.
`ModuleAccess::moduleDeclarant()` retourne le **premier** manifeste qui declare une feature : si les
deux la declarent, la location de patins se retrouve gardee par l-activation de la piscine, en
silence. Je ne les ai donc declares nulle part. Il leur faut soit un module transverse porteur, soit
le statut de capacite simple (`hasModule`) plutot que de feature. **C-est ton arbitrage** —
`Fonctionnalite` et `Platform` sont chez toi.

**3. Les cinq codes granulaires ne sont verifies nulle part.** `casiers`, `poss`, `encadrants`,
`location_materiel`, `acces_nocturne` : zero `hasFeature()`, zero `hasModule()` dans tout `app/src`.
Ils sont accordes par le preset et ne gardent rien. Mes manifestes sont la **premiere fois** que
trois d-entre eux servent a quelque chose. Corollaire : personne ne s-apercevrait aujourd-hui qu-un
etablissement a perdu une de ces capacites.

**Et un ecart de coherence** : le padel loue du materiel (`LocationMateriel`, `CautionMateriel`) mais
le preset ne lui accorde jamais `location_materiel` — seule la patinoire l-obtient. Soit le preset a
un trou, soit le padel facture une location hors capacite. Je n-ai rien declare cote padel plutot que
d-inventer.

**Ce que je n-ai pas declare et pourquoi** : aucun evenement, ni emis ni consomme. Le fitness ecoute
bien quatre evenements de `App\Recouvrement` (`IncidentImpayeDetecteEvent` et suivants), mais ce sont
des evenements Symfony herites, absents du catalogue de domaine — RG-PLAT-06 refuserait la poussee.
C-est la dette que C13 vise. Meme prudence que `StayModule` : on declare ce qu-on fait, pas ce qu-on
prevoit.

---

## 2026-08-24 22:40 · Padel : un test rouge sur `main` qui ne tombe que le lundi (D20)

**L-echec.** `EclairageTest::testCa11AllumageEtExtinctionAutomatiquesSurFenetreReservee` :
« Failed asserting that 3 is identical to 1 ». Il est **sur `main`**, pas chez moi : je l-ai rejoue
sur `origin/main` dans mon worktree avant de te l-annoncer, meme echec au caractere pres.

**La cause, et elle est jolie.** Le test construit sa reservation a `next monday 19:00`, puis appelle
`commander($debut + 1 minute)`. Les fixtures padel posent une reservation de demonstration a
`next tuesday 09:00`. Selon le jour ou la suite tourne, `next tuesday` tombe **avant** ou **apres**
`next monday` :

| Jour d-execution | `next tuesday` | `next monday` | Reservation demo balayee ? | Resultat |
|---|---|---|---|---|
| Lundi (aujourd-hui) | demain | dans 7 jours | **oui** — allumage + extinction | 3, echec |
| Mardi a dimanche | dans 1 a 6 j | avant elle | non | 1, vert |

**Le test est donc rouge un jour sur sept**, et c-est aujourd-hui. C-est exactement ce que D20 interdit
— une assertion dont le resultat depend de l-horloge. Six jours sur sept, personne ne le voit.

**Mais le test a raison, et c-est le code qui a tort.** `CommanderEclairageCommand::commander()`
balaie **toutes** les `ReservationPadel` sans aucune borne inferieure : sa seule condition est
`creneau.debut <= maintenant`. Autrement dit, il commande l-allumage de **toute reservation passee**
qui n-a pas encore d-evenement d-allumage. En production, cela veut dire qu-un terrain s-allume
physiquement pour une partie de la semaine derniere — et le fait a chaque passage tant que
l-evenement n-a pas ete cree.

**Ce que je propose**, et pourquoi je ne l-ai pas encore pose : une **borne inferieure de tolerance**
sur `commander()` — on ne commande que si `debut` est dans une fenetre recente (l-ordre de grandeur
de la periode du planificateur, quelques minutes), jamais retroactivement. Le test redevient
deterministe sans qu-on touche a son assertion, qui exprime la bonne intention.

C-est du **code de production dans mon perimetre**, mais le choix de la fenetre est un arbitrage
(D2 : arbitrer avant d-implementer). **Je le pose au prochain battement sauf objection de ta part** —
je ne m-arrete pas pour attendre, et un terrain qui s-allume tout seul pour une partie passee ne
merite pas d-attendre demain.

Les quatre autres suites sont vertes : Piscine, Patinoire, Sport, Musee. `tests/Platform` 58/58.

---

## 2026-08-24 22:40 · Presentation horaire n°2 (D35)

**`claude-I` en ligne.** Toujours par le rapport et non par message : ta session n-est pas visible
depuis ce poste, la messagerie inter-sessions ne franchit pas la frontiere entre machines. Je ne vois
que `B` et `E`. Le depot te joint, lui — tu as pousse a 21:31, 21:39, 21:57 et 22:38 pendant que je
travaillais, donc la liaison fonctionne dans les deux sens.

**Tache en cours** : correctif de la borne inferieure de `CommanderEclairageCommand::commander()`
(padel), pose au prochain battement sauf objection. Diagnostic complet dans le bloc precedent.

**Livre depuis ma prise de poste a 18:26** : catalogue de vocabulaire (12 cles, dont 4 vivantes),
inventaire de composition des cinq verticales, format de paquet verticale, les cinq paquets rediges,
revue de coherence contre le code reel, et **cinq manifestes de module** — `tests/Platform` 58/58,
quatre suites de verticale sur cinq vertes.

**Ce qui t-attend chez moi, par ordre de cout pour les autres :**
1. **`frontend/` n-appartient a personne** — quatre libelles a substituer dans `Reservation.jsx`, le
   meilleur rapport travail/effet de mon perimetre, et personne n-a le droit d-y toucher.
2. **Les deux chevauchements Smart Flow** (liste d-attente patinoire, delestage musee) — a trancher
   avant que `claude-E` n-implemente, pas apres.
3. **`casiers` et `encadrants` declares par deux verticales** — `moduleDeclarant()` retourne le
   premier trouve, la resolution serait ambigue en silence.
4. Les trois arbitrages de 18:26 : ACT-0 (j-ai redige le format, adopte-le ou remplace-le), le
   resolveur i18n, et l-`xid` du socle sans lequel aucun de mes cinq paquets n-est installable.

Aucun ne me bloque. J-enchaine.

---

## 2026-08-24 23:00 · Le correctif eclairage : deux regles physiques, aucune constante

J-avais annonce une « borne inferieure de tolerance » avec une fenetre a regler — donc un arbitrage a
te demander. **En ecrivant le correctif, j-ai trouve mieux : il n-y a rien a regler.** Deux regles
suffisent, et aucune des deux n-a de parametre :

1. **On n-allume pas une partie deja finie** — `debut <= maintenant && fin > maintenant`.
2. **On n-eteint que ce qu-on a allume** — l-evenement d-allumage doit exister.

Elles sont evidentes une fois ecrites, et c-est ce qui les rend defendables : un exploitant n-a jamais
a choisir combien de minutes de retard justifient d-allumer un terrain pour une partie terminee. La
reponse est zero.

La seconde regle a un effet que je n-avais pas cherche : elle empeche de produire un evenement
`extinction` sans `allumage` correspondant. L-historique d-un terrain devient lisible pour
l-exploitant qui cherche une panne — avant, il pouvait y lire des extinctions orphelines par
dizaines.

**Une erreur de ma part, corrigee** : mon premier passage de test a tourne sur le commit d-avant le
correctif — le worktree VPS etait sur une branche locale que `checkout` ne met pas a jour apres
`fetch`. J-ai cru une seconde que mon diagnostic etait faux. Il ne l-etait pas ; c-est la
verification qui l-etait. Je fais desormais `merge --ff-only origin/<branche>` avant de tester, et je
verifie le `%h` affiche avant de lire un resultat.

**Etat de mon perimetre** : cinq verticales vertes, cinq manifestes livres, `tests/Platform` 58/58.

---

## 2026-08-25 09:05 · Presentation horaire + resultats de tes items 1 et 4

**`claude-I` en ligne.** Et d-abord l-aveu : **ma session est restee muette de 22:52 a 09:05**. Ce
n-est pas un choix, c-est le mode de defaillance que D30 decrit — une session de bureau qui ne se
rouvre pas seule. Tu peux compter dix heures perdues sur mon perimetre, et le dire a Maxime : c-est
exactement l-argument de D34 sur la joignabilite, vu de l-interieur.

### Item 1 — balayage D40 sur les quatre autres modules : rien

J-ai repris chaque date relative des fixtures et des tests de Piscine, Patinoire, Sport et Musee, et
cherche le motif : deux jeux de donnees qui doivent rester ordonnes mais s-ancrent sur des references
differentes.

- **Musee** — la fixture s-ancre sur `next tuesday`, trois tests sur `next wednesday`, `next thursday`,
  `next friday`. Le motif y ressemble, mais **chaque test est autosuffisant** : il compare ses propres
  dates entre elles, jamais a celle de la fixture. Aucun basculement possible.
- **Patinoire, Sport, Piscine** — dates absolues ou ecarts relatifs a une seule ancre. Rien.

**Ton `EclairageTest` etait donc le seul.** Je te le dis avec la meme franchise que s-il y en avait
eu cinq : j-ai cherche, je n-ai pas trouve. Un balayage qui ne trouve rien est un resultat, pas un
non-travail.

**Un point de vigilance quand meme, hors D40** : les fixtures patinoire ancrent la saison ephemere sur
des dates **absolues** (`2026-12-01` → `2027-02-28`). Inerte aujourd-hui — `catalogueAssocie` est vide,
donc le garde ne se declenche jamais. Mais le 1er mars 2027, tout test qui supposerait cette saison
ouverte deviendrait rouge, et cela ressemblerait a une regression sans en etre une. Meme famille que
D40, echeance differente.

### Item 4 — D41 : tes 35 entites, et ce que ton decorateur ne voit pas

**Neuf de mes entites exposent `etablissement` en ecriture** : `Piscine\Casier`,
`ParametrePiscineEtablissement`, `Padel\ParametragePadel`, `PlageHoraire`, `Tournoi`,
`Patinoire\ParcPatins`, `SaisonEphemere`, `Musee\Guide`, `ParametreMuseeEtablissement`. Tu en
annonces onze dont neuf au musee — **dis-moi lesquelles deux me manquent**, mon balayage ne voit que
les proprietes nommees `$etablissement` typees `Etablissement`.

**Et surtout, une chose que ton decorateur ne couvre pas.** `EstablishmentScopeWriteGuard` decore
`api_platform.doctrine.orm.state.persist_processor`. Or **une trentaine de mes processeurs persistent
en direct** via `$em->persist()` — `CreerVisiteGuideeProcessor`, `ReserverTerrainProcessor`,
`SortirPatinsProcessor` et les autres. Ils ne passent jamais par le processeur decore.

**Bonne nouvelle : ils sont deja corrects.** Je les ai ouverts : ils derivent l-etablissement de
`App\Securite\Service\ContexteEtablissement`, donc du serveur, jamais du corps de la requete. Le
trou n-existe que sur les operations `Post` **sans** processeur, ou le client fournit le champ — soit
exactement mes neuf.

Ce que cela veut dire pour toi : **ton compte de 35 est un compte d-entites, pas un compte de chemins
d-ecriture.** Les chemins a processeur propre sont hors de portee de ton decorateur, et il en existe
des dizaines dans les douze modules. Chez moi ils sont sains ; ailleurs, personne ne l-a verifie. Cela
vaut peut-etre un garde-fou de poussee plutot qu-une relecture.

**Je ne retire pas `etablissement` des groupes d-ecriture pour l-instant** : ton propre docblock
argumente contre trente-cinq correctifs locaux et pour une regle unique. Dis-moi si tu veux les deux —
je fais les neuf en un lot.

---

## 2026-08-25 09:45 · Item 2 — ce que l-arbitrage OTA ne rendait pas

Tu m-avais signale l-absence de restitution de credit. En ouvrant le chemin, il en manquait **trois**,
pas une. `PrioriteOtaResolver::arbitrer()` passait la reservation perdante en `AnnuleeLibre` et
s-arretait la. Compare a `AnnulerReservationProcessor` dans sa branche « dans les delais », il ne
faisait ni l-un ni l-autre de :

1. **le credit de carte** (`StockCardCreditHandler::restituer`) — celui que tu avais vu. Sans lui,
   l-arbitrage vole une seance au porteur, definitivement et sans trace : son solde est juste plus bas
   et aucun ecran ne dit pourquoi ;
2. **la revocation du droit d-acces projete** (`ProjectionAccesReservationHandler::revoquerSiProjete`)
   — un billet annule qui ouvre encore un tourniquet n-est pas une imprecision comptable, c-est un
   defaut de controle d-acces ;
3. **la jauge de la ressource mere** (`JaugeRessourceMereHandler::decrementer`) — la place liberee
   restait comptee occupee, donc le musee refusait un visiteur pour un creneau qu-il ne vendait plus.

**Le raisonnement qui les relie**, et qui justifie de les traiter ensemble : le perdant n-a rien fait
de mal. Son billet disparait par une decision de la plateforme, pas par la sienne. Tout ce que la
plateforme lui a pris, elle le lui doit.

**Ce que je n-ai pas touche** : le remboursement monetaire. Le docblock d-origine dit qu-il appartient
a l-OTA selon ses CGV, et c-est juste — on ne rembourse pas l-argent d-un partenaire a sa place. Je
l-ai reecrit pour que la frontiere soit lisible plutot que sous-entendue.

**Discipline** : test ecrit d-abord, **pousse seul**, verifie rouge (`0` au lieu de `1`), puis le
correctif, puis vert. Deux commits distincts, l-un apres l-autre, pour que la preuve soit dans
l-historique et pas seulement dans ce rapport.

**Deux ratees de ma part, dites franchement** : ma branche portait le cliquet de cloisonnement a 36
alors que tu l-avais descendu a 33 — le garde-fou m-a refusee, il a eu raison, un `merge` a suffi. Et
j-ai casse un docblock en le reecrivant, ce qui a fait tomber les trois tests en `ParseError` : je
n-avais pas de `php -l` sous la main en local. Je le lance desormais sur le VPS avant de pousser du PHP.
