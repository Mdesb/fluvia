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
