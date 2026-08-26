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
| 25/08 10:10 | **Chantier ferme.** Le vocabulaire des cinq verticales passe du document au **manifeste** (`settingsSchema()`, patron `SmartFlowModule`) : 51 cles declarees en code, testees. `VocabulaireManifesteTest` verifie rouge sur les cinq (« aucun vocabulaire declare ») avant d-etre vert. Verticales 5/5, Platform 62/62. Pile demontee, worktree rendu. | Rien d-assigne — **je te demande une tache** (D35). En attendant je reprends le chantier `frontend/` si tu me l-attribues, sinon la revue de mes 81 entites d-API. | Rien. |
| 26/08 09:55 | Nouveau silence 23:56 -> 09:41, meme cause. Reprise : **fixtures rendues idempotentes** dans les cinq verticales — 9 roles (Padel 2, Patinoire 3, Musee 4, ton compte exact) et **8 creations de permissions** que tu n-avais pas comptees. Syntaxe verifiee en conteneur avant push. | Les cinq suites tournent. | Rien. |
| 26/08 10:25 | **Deux lots verts.** (1) Fixtures idempotentes : 9 roles + **8 permissions** sur mes cinq verticales. (2) Dette de couverture : `EvenementEclairage` cloisonne par `terrain` — **la seule des 34 entites gelees qui soit chez moi**. Suites : Piscine 33, Padel 23, Patinoire 29, Sport 31, Musee 23, Platform 62 — **toutes vertes**. Pile demontee. | Plus rien d-assigne. Je reste sur ma demande de tache. | Rien. |
| 26/08 11:30 | **D41 : six entites traitees, dette 49 -> 43.** Ton garde-fou n12 confirme « 6 resorbees ». Quatre `EstablishmentStampProcessor` sur le patron de `Reservation`. Trois tests de non-regression ecrits la ou il n-y en avait aucun, **verifies rouges sans le correctif**. | Les cinq entites de la **voie B** (aucun `denormalizationContext`) — lot separe. | Rien. |
| 26/08 12:05 | Voie B analysee : sur les cinq, **trois seulement sont reellement denormalisables**, deux sont fermees par `input: false`. J-ai cru trouver une ecriture transfrontiere dans `DemarrerAffutageProcessor` — **l-experience m-a contredite**, detail ci-dessous. Correctif conserve pour une autre raison, teste, vert. | Voie B proprement dite. | Rien. |

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

---

## 2026-08-25 10:10 · Presentation horaire n°3 (D35) — chantier ferme, je demande une tache

**`claude-I` en ligne.** Mes cinq items sont traites. Etat, court :

| Item | Etat |
|---|---|
| 1 · balayage D40 sur les 4 autres modules | fait — **aucun autre basculement** |
| 2 · `PrioriteOtaResolver` sans restitution | **livre** — 3 restitutions, pas 1 |
| 3 · `CommanderEclairageCommand` sans borne | **livre** hier soir, sans constante de reglage |
| 4 · D41, mes 9 entites | verifie ; ton decorateur les couvre, **mais pas mes ~30 processeurs a persistance directe** — eux sont deja sains via `ContexteEtablissement` |
| 5 · ACT-0 sur les verticales | **livre** — 5 paquets rediges, composition inventoriee, vocabulaire en code |

**Ce que j-ai fait de plus, et pourquoi.** Le vocabulaire vivait dans un tableau markdown. Un tableau
ne se teste pas : rien n-empechait une verticale d-etre livree sans vocabulaire, ni un renommage
d-inventer une cle que personne ne resoudrait. Je l-ai deplace dans `settingsSchema()` des cinq
manifestes — la place que `SmartFlowModule` utilise deja pour la configuration par tenant, donc aucun
mecanisme invente. **51 cles, verrouillees par un test** qui impose la ressource, le creneau et le
participant (les trois que tout ecran generique affiche) et refuse toute cle hors catalogue.

Corollaire pour toi : quand tu poseras le resolveur i18n, **ta source de verite existe deja et est
testee**. Et le retrait de `PresetVerticale` a maintenant deux points de chute en code : les
`features()` pour les capacites, `settingsSchema()['vocabulary']` pour les mots.

### Ce qui reste, et qui ne depend plus de moi

1. **`frontend/` n-appartient toujours a personne.** Quatre libelles en dur dans `Reservation.jsx` —
   Ressource, Reservation, Capacite, Acces — sont les **seuls points d-affichage vivants** de mes 51
   cles. Le correctif tient en quatre lignes et je n-ai pas le droit de le faire.
2. **Les deux chevauchements Smart Flow** (liste d-attente patinoire, delestage musee), avant que
   `claude-E` n-implemente.
3. **Le resolveur i18n et l-`xid` du socle** — sans eux mes cinq paquets restent rediges et non
   installables.

**Je n-ai plus de tache assignee : donne-m-en une.** En attendant ta reponse je ne m-arrete pas — je
prends la revue de cloisonnement de mes 81 entites d-API, qui est dans mon perimetre et que personne
n-a jamais faite.

**Une friction a te signaler** : deux poussees refusees ce matin parce que les cliquets de ma branche
etaient plus hauts que ceux de `main` — que tu venais de faire descendre. Le garde-fou a eu raison a
chaque fois et un `merge` a suffi. Mais a neuf sessions, chaque descente de cliquet refuse la
prochaine poussee de tous ceux qui n-ont pas remerge dans l-intervalle. Ce n-est pas un bug, c-est un
cout a connaitre — peut-etre a dire dans le message de refus : « remerge `main`, un cliquet a baisse ».

---

## 2026-08-26 09:55 · Presentation horaire (D35) — fixtures idempotentes, et un ecart a ton compte

**`claude-I` en ligne.** Tache en cours : verification des cinq suites apres le lot fixtures.

**Ton lot est fait.** Les neuf creations de roles sont passees par `roleNomme()` — ton patron, repris
tel quel : Padel 2, Patinoire 3, Musee 4. Ton compte etait exact.

**Mais il manquait les permissions, et elles sont plus nombreuses.** Tu ecrivais « meme chose pour les
`Permission` : le couple (module, action) porte aussi une unicite ». J-en ai trouve **huit creations
non gardees** dans mes cinq fixtures — dont deux dans `Piscine` et `Sport`, que ton ordre ne citait
pas puisqu-il ne visait que les trois modules a roles. Elles echouent exactement de la meme facon sur
un chargement complet, et elles auraient fait tomber le chargement **avant** meme d-arriver aux roles :
`PiscineFixtures` cree ses permissions ligne 58, bien avant tout role.

Autrement dit : rendre les neuf roles idempotents n-aurait pas suffi a faire passer un chargement
complet. Les cinq fixtures sont traitees, roles **et** permissions.

**Un detail de mise en oeuvre qui evite du risque** : j-ai laisse en place tous les `persist()`
existants plutot que de les demeler. Doctrine ignore un `persist()` sur un objet deja gere, donc le
comportement est identique et le diff se limite aux expressions de construction. Moins de surface,
moins de chances de casser un chemin que je ne connais pas.

**Sur mon silence.** Deuxieme nuit d-arret, 23:56 -> 09:41. Je le redis parce que ca reste, de loin,
mon plus gros cout : deux fois dix heures perdues en deux jours, sur un perimetre que personne d-autre
ne couvre. Ce n-est pas quelque chose que je peux corriger de l-interieur.

**Et ma demande de tache d-hier soir tient toujours** — mes cinq items sont fermes, celui-ci etait un
lot distribue a toute la flotte. Si tu n-as rien, je prends la revue de cloisonnement de mes 81
entites d-API, que personne n-a jamais faite.

---

## 2026-08-26 10:25 · Deux lots livres, et une erreur de methode que je me suis faite

### Fixtures — ton lot, plus ce qu-il ne couvrait pas

Les neuf roles sont passes par `roleNomme()`, ton patron repris tel quel. **Et huit creations de
permissions non gardees**, que ton ordre ne pouvait pas citer puisqu-il visait les trois modules a
roles : il y en a aussi dans `Piscine` et `Sport`.

Le detail qui compte : `PiscineFixtures` cree ses permissions **ligne 58**, bien avant tout role. Sur
un chargement complet, elle serait donc tombee **avant** d-atteindre le moindre role. Rendre les neuf
roles idempotents n-aurait pas suffi a faire passer un chargement — c-est peut-etre vrai chez d-autres
que moi, ca vaut d-etre verifie ailleurs avant de rejouer un chargement sur la preprod.

J-ai laisse en place tous les `persist()` existants : Doctrine ignore un `persist()` sur un objet deja
gere, donc le comportement est identique et le diff se limite aux expressions de construction. Moins
de surface touchee dans des modules que je decouvrais.

### Dette de couverture — une seule entite chez moi

Sur les 34 entites gelees, **une seule est dans mon perimetre** : `Padel\EvenementEclairage`. Corrigee
par `terrain`, pas par `reservation`. Les deux menent a un etablissement, mais le chemin par le
terrain est plus court d-une jointure **et ne depend pas d-une reservation qui peut etre nulle** sur un
forcage manuel d-eclairage — par l-autre chemin, un evenement sans reservation serait reste invisible
a son propre exploitant. La ligne de base est dans `bin/`, perimetre de `claude-C` : je ne la nettoie
pas, le garde-fou annonce desormais 5 entites resorbees.

### Mon erreur, parce qu-elle peut servir a d-autres

J-ai vu Padel passer a 18 erreurs apres mon changement de cloisonnement et j-ai cru l-avoir casse.
**C-etait moi** : une tache de fond tournait encore sur le jeton `claudeI` pendant que je lancais des
essais au premier plan. Deux processus, une seule base. Le symptome — « Base table or view already
exists » — est exactement celui que le playbook decrit, et je l-ai quand meme pris pour une
regression pendant plusieurs minutes.

La regle 7 dit « un jeton par session ». Elle merite peut-etre « **et un seul processus a la fois
dessus** » : a neuf sessions, chacune peut se corrompre toute seule sans qu-aucune autre soit en
cause. Apres arret de la tache et reconstruction de la pile, Padel est vert : 23 tests, 228
assertions.

### Etat de mon perimetre

| Suite | Resultat |
|---|---|
| Piscine | 33 tests, 200 assertions |
| Padel | 23 tests, 228 assertions |
| Patinoire | 29 tests, 264 assertions |
| Sport | 31 tests, 246 assertions |
| Musee | 23 tests, 179 assertions |
| Platform | 62 tests, 305 assertions |

Tout vert, pile demontee, worktree VPS rendu.

---

## 2026-08-26 11:30 · D41 chez moi : ton compte etait juste, le mien ne l-etait pas

**Onze, pas neuf.** Hier je t-annoncais neuf entites ; tu m-en annoncais onze. **Tu avais raison**, et
ton garde-fou n12 dit pourquoi : il detecte **deux voies**, et mon grep n-en voyait qu-une.

- **Voie A — groupe d-ecriture** : `Casier`, `PlageHoraire`, `Tournoi`, `ParcPatins`, `SaisonEphemere`,
  `Guide`. Six.
- **Voie B — aucun `denormalizationContext`**, donc API Platform rend ecrivable toute propriete munie
  d-un mutateur : `NiveauJoueur`, `Affutage`, `ListeAttentePointure`, `LocationPatins`,
  `AbonnementFitness`. Cinq.

La voie B est celle que je ratais, et c-est la plus sournoise : **rien dans le fichier ne signale
l-exposition, c-est l-absence de declaration qui expose**. Un relecteur humain ne la voit pas non plus.

**Les six de la voie A sont traitees.** J-ai repris `Reservation\State\EstablishmentStampProcessor`
plutot que d-inventer, y compris ses deux pieges — que je n-aurais pas trouves seule : la validation
s-execute entre la deserialisation et l-ecriture, donc un `Assert\NotNull` reste sur le champ ferait
echouer la creation en 422 **avant** que le processeur ne soit atteint ; et un `provider` ne serait
jamais appele, une operation `Post` etant en `read: false`. Quatre processeurs, un par module, et
l-assertion retiree des cinq champs qui la portaient. Ton garde-fou annonce **6 resorbees, 49 -> 43**.

### Ce que j-ai trouve en verifiant, et qui vaut plus que le correctif

**Sur les six, deux seulement avaient un test de creation** : `ParcPatins` et `SaisonEphemere`. Les
quatre autres n-en avaient aucun — j-aurais livre quatre processeurs sans une ligne de preuve qu-ils
s-executent.

J-ai donc ecrit trois tests, un par module non couvert. Ils envoient **deliberement l-IRI de
l-etablissement B** et verifient que l-entite atterrit chez A. Ce qui justifie le test : **ce n-est
pas un refus, c-est une absence d-effet**. Le client recoit un 201 et rien dans la reponse ne lui dit
qu-il n-a pas ete ecoute — donc rien, sans test, ne signalerait qu-on a recommence a l-ecouter le jour
ou quelqu-un remettrait le champ au groupe d-ecriture.

**Verifies rouges** : j-ai remis `Casier` dans son etat d-avant, le test tombe (« two strings are
identical » — l-entite atterrissait bien chez B). Puis vert.

### Ce que je ne fais pas dans ce lot

Les cinq de la voie B. Leur correctif est d-une autre nature — poser un `denormalizationContext` la ou
il n-y en a pas — et aucune n-a d-operation `Post` nue. Melanger les deux natures dans un meme diff
rendrait la relecture plus difficile pour rien. Je les prends au lot suivant.

---

## 2026-08-26 12:05 · J-ai cru trouver une faille, l-experience dit non

A consigner tel quel, parce que la conclusion est plus utile que ce que je croyais annoncer.

**Ce que j-ai trouve.** `DemarrerAffutageProcessor`, branche `prestation_client`, resolvait
`etablissement` **depuis le corps de la requete** par un `find()` sans confrontation au perimetre :

    $etablissement = $this->resoudre(Etablissement::class, $corps['etablissement'] ?? null, ...);

C-est la forme exacte d-une ecriture transfrontiere, et le fichier **n-est pas dans ta ligne de base
de cloisonnement** — donc le garde-fou ne l-a jamais compte. J-allais te l-annoncer comme une faille.

**Ce que l-experience a montre.** J-ai ecrit le test qui la demontre. Il echoue — mais pas comme
prevu : la requete est refusee **avant** d-atteindre le processeur, par la denormalisation d-API
Platform, dans les deux formes que `resoudre()` accepte :

| Ce que l-appelant envoie | Reponse | Pourquoi |
|---|---|---|
| IRI d-un etablissement etranger | 400 « Item not found » | la lecture d-`Etablissement` est cloisonnee |
| UUID nu | 400 « Invalid IRI » | le denormaliseur n-accepte que des IRI |

Le `find()` nu n-est donc **jamais atteint avec une reference etrangere**. Il n-y a pas de faille.

**Le correctif reste, pour une raison plus modeste.** Il retire une lecture brute du corps portant sur
un champ de perimetre — une forme qui ne doit pas s-installer comme exemple, puisque rien dans le
processeur ne dit qu-un autre garde la protege — et il **supprime un champ obligatoire** que
l-appelant ne pouvait de toute facon renseigner qu-avec son propre etablissement. Simplification et
defense en profondeur, pas fermeture de faille. Le test l-ecrit noir sur blanc pour que personne ne
relise ce lot en croyant a une correction de securite.

**Ce que j-en retire, et qui vaut peut-etre pour la flotte.** Trois d-entre nous chassent la meme
classe de defaut depuis deux jours. La forme « `find()` sur une reference du corps » est un bon
signal, mais elle **ne suffit pas a conclure** : le denormaliseur garde deja les champs de type
relation. Un signalement sans test d-exploitation risque de faire corriger du vide — et, pire, de
faire croire le perimetre plus troue qu-il ne l-est. J-ai failli le faire.

### Voie B : la mesure precise

| Entite | Post de creation | Denormalise ? |
|---|---|---|
| `NiveauJoueur` | `input: false` | non |
| `AbonnementFitness` | `input: false` | non |
| `Affutage` | processeur, sans `input: false` | **oui** |
| `ListeAttentePointure` | processeur, sans `input: false` | **oui** |
| `LocationPatins` | processeur, sans `input: false` | **oui** |

Deux des cinq sont donc deja fermees par `input: false` — mais rien ne le dit sur l-entite, et retirer
ce `input: false` rouvrirait l-exposition sans qu-aucun garde-fou ne bronche. C-est un point a
arbitrer : faut-il quand meme leur poser un `denormalizationContext`, pour que la fermeture soit
declaree plutot que dependante d-une option d-operation ? **Mon avis : oui**, mais c-est ton
arbitrage, et je ne le fais pas sans reponse.
