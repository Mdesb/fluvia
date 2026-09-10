# Suivi d'implémentation — chaine-encaissement

**État :** review <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/chaine-encaissement
**Spec :** features/chaine-encaissement/specs/spec-chaine-encaissement.md
**Plan :** features/chaine-encaissement/plans/plan-chaine-encaissement.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime, 2026-09-08
- [x] **CP-2** — plan validé par Maxime, 2026-09-08
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

<!-- Coche au fur et à mesure. Ne modifie jamais une étape déjà « fait » : crée une nouvelle étape. -->

- [ ] ~~Étape 1 — `MoyenPaiement.compteTresorerie`~~ **À REFAIRE** (étape 1bis) — défaut de
      cloisonnement, voir le journal du 08/09. Livrée puis invalidée, non cochée.
- [x] Étape 1bis — `PaymentMethodTreasuryAccount` : le compte par `(profil, moyen)` (couvre G-3)
      — entité + migration `Version20260908091500` (table **et** défauts) + liste blanche
      `AccountingScopeExtension::VIA_PROFIL`. Dérive de schéma nulle sur mes deux tables, avec un
      motif de recherche dont j'ai prouvé qu'il attrape ET qu'il épargne. Test de cloisonnement vu
      tomber sur une simulation de l'ancien modèle.
      ⚠ **Reste avant que l'étape 3 puisse être verte** : semer les rattachements dans
      `ComptaFixtures` — les tests ne jouent pas les migrations, donc les défauts posés par la
      migration n'existent pas pour eux.
- [x] Étape 2 — `PaymentLedgerPoster` : l'écriture au journal `ENC` (couvre G-2, G-3)
      — 6 tests, 21 assertions, vus tomber sous sabotage. La lecture du compte reste à réorienter
      vers l'étape 1bis.
- [x] Étape 3 — le règlement de facture écrit son encaissement et lettre en groupe (couvre G-2)
      — Facturation 103 tests / 1 échec **préexistant** (voir plus bas), Compta 128 tests / 0 échec.
      Le semis des rattachements est posé dans `ComptaFixtures` **et** dans la migration : les deux
      chemins sont indépendants, le harnais ne jouant jamais les migrations.
- [x] Étape 4 — l'échéance transporte son taux de TVA (couvre G-1bis)
      — `EcheanceSepaDue.tauxTvaValeur` + `SportEcheanceSepaSource`, et le **paramètre de taux par
      défaut** demandé par Maxime (entité, migration, écran). Sport 82 tests / 0 échec, les deux
      témoins vus tomber sur la forme DQL d'origine.
- [x] Étape 5 — registre d'unicité `InstallmentInvoice` (couvre G-1)
      — table `billing_installment_invoice` (préfixe anglais, même règle que `accounting_`),
      unique sur `origin_reference`, inscrite dans `PerimetreFacturationExtension`.
- [x] Étape 6 — `InstallmentInvoicer` : composer, réserver, émettre (couvre G-1, G-1bis)
      — 5 tests / 44 assertions : émission nominale (numérotée, scellée, écriture `FAC`),
      idempotence, retrait de réservation sur échec, priorité du taux de l'échéance, refus sur taux
      ambigu.
- [x] Étape 7 — la commande planifiée (couvre G-1) — **codée, inscrite au catalogue, ÉPROUVÉE.**
      ⚠ **DÉLIBÉRÉMENT ABSENTE de `TACHES_AUTORISEES`** : `grep echeances:facturer infra/ordonnanceur.sh`
      rend **0**. Elle ne tournera donc pas toute seule tant que Maxime n'aura pas décidé.
- [x] Étape 8 — « Réglé » accepte un corps : canal, moyen, date, référence (couvre G-4)
- [x] Étape 9 — le bouchon d'encaissement CB **refuse** au lieu de confirmer (couvre G-6)
- [x] Étape 10 — l'impayé référence sa pièce, et la régler la solde (couvre G-5)
      — Recouvrement 32 tests / 0 échec, Sport 87 / 0, Compta 128 / 0.
- [x] Étape 11 — la fenêtre « Réglé » : canal, moyen, date, référence (couvre G-4)
- [x] Étape 12 — les règlements visibles sur la facture, mention d'acquittement datée (couvre G-7)
- [x] Étape 13 — les factures sur la fiche client (couvre G-8)

## Journal de Session

**2026-09-08 — allaccess-76 — origine du lot.** Maxime a demandé si marquer un impayé « Réglé »
enregistrait bien ce qu'il faut pour la compta, et si l'on retrouvait les modes de règlement sur la
fiche de la facture. Mesure : non aux deux, et le défaut déborde largement du recouvrement — voir
F-1 à F-10 dans la spec.

**Aiguillage : SDD complet.** Quatre zones sensibles touchées simultanément (paiements, écritures
NF525/argent, schéma de base, cloisonnement). La voie légère n'était pas discutable.

**Ligne de base mesurée avant d'écrire.** `./bin/garde-fous.sh` sur `origin/main` (0c298ce6) :
48 garde-fous OK, exit 0, **1 non exécuté** (« Manifeste vs catalogue », outil absent de la machine).
Ce non-exécuté est consigné parce qu'il n'est pas un vert : il ne devra pas être compté comme tel au
CP-3.

**Base de branchement — piège écarté.** Le VPS porte deux dépôts dont les `main` ont divergé :
`bare` (`/home/debian/billetterie.git`, privé) et `origin` (`github.com/Mdesb/fluvia`, public,
scrubbé). Mesuré : 2390 commits d'un côté, 2398 de l'autre, 10 fichiers d'écart réel. GitHub est en
avance de **9 PR fusionnées** (#2 → #13) que le dépôt privé n'a pas ; le privé ne porte en propre que
l'infra non publiable (retrait d'`ACCES-VPS.md`, script de propagation). CLAUDE.md tranche : GitHub
est la référence, la préprod déploie de là. Branché sur `origin/main`.

**Arbitrages de Maxime (08/09), avant la spec :**
- portée **C** — traçabilité + écriture d'encaissement + rattachement de l'impayé à sa pièce ;
- la pièce = **option 1**, une facture par échéance prélevée.

**2026-09-08 — Écart n°1 : le taux d'une ligne d'encaissement.** Le plan (D-d) prévoyait d'EMPRUNTER
le taux de la pièce soldée, comme le fait `SupplierPaymentHandler` avec la ligne 401. Écarté après
mesure : le dépôt possède `CompteLookupService::tauxHorsChamp()`, semé par profil, et
`TauxTva::LIBELLE_HORS_CHAMP` désigne exactement ce cas. Deux raisons de ne pas suivre le précédent :
(1) emprunter 20 % attribue de la TVA à un mouvement qui n'en porte pas, dans tout état qui groupe par
taux ; (2) un impayé né avant ce lot n'a **aucune** pièce dont emprunter un taux — l'étape 10 en aura
besoin. Verrouillé par `testLaLigneEstHorsChampEtNEmprunteAucunTaux`.

**2026-09-08 — Écart n°2 : `public: true` sur `PaymentLedgerPoster`.** Sans consommateur, le service
est supprimé à la compilation et le test échoue sur un `ServiceNotFoundException` qui ne dit rien de
son objet. Déclaré public selon la convention déjà en place dans `services.yaml` (même patron que
`App\Musee\Service\PrioriteOtaResolver`), pour que l'étape soit vérifiable AVANT ses consommateurs et
non grâce à eux.

**2026-09-08 — Écart n°3 : il manquait un lien règlement → écriture.** `lettrerGroupe()` exige
Σdébit = Σcrédit. Une facture réglée en deux fois produit deux écritures d'encaissement, et solder
exige de rassembler la ligne 411 débitrice de la facture ET les lignes créditrices de **tous** les
règlements. Le plan ne l'avait pas prévu. Ajouté `ReglementFacture.ecritureEncaissement` +
`reconciliationCode` (migration `Version20260908093000`), miroir exact de
`SupplierPayment::$ledgerEntry` — le chemin fournisseur avait rencontré le même défaut (« Défaut 5 »).

**2026-09-08 — ⚠ DÉFAUT DE CLOISONNEMENT DANS L'ÉTAPE 1, TROUVÉ APRÈS L'AVOIR LIVRÉE.**
J'ai posé `compteTresorerie` sur `MoyenPaiement`. Mesuré ensuite sur la base :

    compta_moyen_paiement      0 colonne de rattachement  → référentiel GLOBAL
    compta_compte_comptable    profil_exploitant_id       → PAR EXPLOITANT

Un compte **par exploitant** accroché à un objet **global** : le premier établissement qui configure
« virement » impose son compte à tous les autres, et leurs encaissements s'écrivent dans SON grand
livre. Violation de D3.

⚠ **Et rien ne l'aurait signalé** : l'écriture est équilibrée, le lettrage passe, le solde du client
revient à zéro. Le seul symptôme serait un grand livre étranger qui gonfle, des semaines plus tard.
Mes six tests passaient — ils n'exerçaient qu'un seul profil exploitant, donc le partage était
invisible. Un jeu de tests mono-tenant ne peut pas voir un défaut de cloisonnement.

**Correction (étape 1bis)** : entité de rattachement `PaymentMethodTreasuryAccount`, unique sur
`(profil_exploitant_id, moyen_paiement_id)`, sur le patron de `MappingComptable`
(unique `(profil_exploitant_id, categorie)`). La colonne et la migration `Version20260908090000` sont
retirées. Le test de l'étape 2 gagnera un **second profil exploitant** — sans quoi il ne prouverait
toujours rien du cloisonnement.

**2026-09-08 — Constat annexe, hors de ma voie.** `bin/verifier-derive-schema.sh` ne monte pas
`docker/php/conf.d/zz-memory.ini`, contrairement à `infra/test-stack.sh` : `doctrine:migrations:migrate`
y meurt à 128 Mo dans `ContainerBuilder`, et la mesure de dérive est impossible. Le script le dit
honnêtement et sort en 1 — ce n'est pas un vert menteur. Rejoué à la main avec le montage : migrations
OK, 108 instructions de dérive préexistante, **aucune** sur mes tables. `bin/` est la voie de Jarvis ;
signalé, non corrigé.

**2026-09-08 — Échec préexistant, pas de mon fait.**
`CloisonnementFacturationTest::testUtilisateurHorsEtablissementNAccedePas` attend 403 et reçoit
**404**. C'est le changement déjà consigné dans MESSAGES.md (`EstablishmentHeaderListener` ferme en
404 avant le voter). Ce test ne traverse aucun règlement, et mon diff ne touche aucun fichier de
sécurité — vérifié par `git status`.

**2026-09-08 — La migration de données jouée à blanc AVANT d'être écrite, et elle était fausse.**
Première règle : « le premier compte du profil dont le numéro commence par le préfixe ». Jouée en
lecture seule sur la préproduction, elle donnait aux quatre moyens « chèque » le compte **511000
« Recettes à classer — régie »** au lieu de **511200 « Chèques à encaisser »** : tous les chèques
seraient tombés dans un compte d'attente de régie, sur toutes les installations, sans qu'une seule
ligne ne proteste. Corrigée en `COALESCE(numéro exact, préfixe)`. Rejouée : les 6 exploitants de la
préprod reçoivent 531000 Caisse, 512000 Banque et 511200 Chèques à encaisser.
Le préfixe reste nécessaire en repli — les deux semis de plan comptable du dépôt ne posent pas les
mêmes numéros, et un `INSERT` sur numéro exact seul serait muet sur la moitié des installations.

**2026-09-08 — Décision de Maxime : des défauts posés par migration.** Sans eux la facturation
s'arrêterait net au déploiement (aucun moyen configuré → tout règlement refusé). `avoir`, `differe`
et `pmv` ne reçoivent délibérément aucun compte : ce ne sont pas des mouvements de trésorerie, et
aucun n'est utilisé comme moyen de règlement dans le dépôt (mesuré).

**2026-09-08 — Pas d'`ApiResource` sur l'entité neuve, à dessein.** Le garde-fou d'écart
client/serveur est à **524 opérations inatteignables pour un plafond de 524** : exposer quatre
opérations qu'aucun écran n'appelle le ferait rougir le jour même. La ressource ira **avec** son
écran de paramètres, en phase D. Conséquence assumée : le message de refus a été réécrit pour ne
plus nommer « Paramètres › Caisse & moyens de paiement », un écran qui ne sait pas encore régler ce
compte.

**2026-09-08 — Table renommée `accounting_payment_method_treasury_account` (décision de Maxime).**
Le garde-fou D5 refusait `compta_…` à cause du préfixe français, qui est pourtant celui des ~20 tables
du module. La convention mesurée ailleurs est « préfixe = nom anglais du module » (`finance_`,
`public_api_`, `revenue_recovery_`, `subscription_`). Renommé plutôt que de retirer `compta` du
lexique, ce qui aurait désarmé le contrôle pour tous les `compta*` des neuf sessions, sans que ce
rétrécissement se voie ensuite. ⚠ Les identifiants d'index et de contraintes que Doctrine calcule
DÉPENDENT du nom de la table : `IDX_3ACEDE0B*` → `IDX_51C9CB1D*`. La migration a été reprise sur le
DDL réel, pas seulement renommée.

**2026-09-08 — État vérifié à la fin de cette session.**

    garde-fous          54 OK, exit 0 — les 54 ont TOURNÉ (aucun non exécuté)
    tests de l'étape 2   7 tests, 27 assertions, vus tomber sous deux sabotages distincts
    dérive de schéma     108 instructions préexistantes, ZÉRO sur mes deux tables
                         (motif de recherche contrôlé : attrape 1, épargne 0)
    committé             rien

⚠ La ligne de base d'avant le lot mesurait **moins** : 48 garde-fous exécutés sur 49, puis 49 sur 50.
Les 54 d'aujourd'hui ne sont donc pas comparables à l'identique — c'est un vert plus large, pas un
vert plus fragile, mais il faut le dire pour que personne ne conclue d'une comparaison de nombres.

**2026-09-08 — Un test durci, pas assoupli.** `ReglementFactureApiTest::testCa5ReglementTotalLettreEtPasse`
attendait **1** lettrage ; il y en a désormais **2**. Modifier un test pour qu'il accepte le nouveau
comportement est la manière habituelle de cacher une régression — sauf que c'est l'ancien
comportement qui était faux : le règlement lettrait la ligne 411 de la facture SEULE, contre rien.
Un lettrage à une ligne ne rapproche pas, il affirme. Le test vérifie maintenant trois choses au lieu
d'une : deux lettrages, **le même code de rapprochement** (seule preuve qu'ils se rapprochent l'un de
l'autre et non chacun dans son coin), et surtout **le solde du compte 411 revenu à zéro AU GRAND
LIVRE** — ce qu'aucune assertion ne vérifiait, le solde affiché venant des règlements et non des
comptes.

**2026-09-08 — L'échec restant est daté, et il est antérieur.**
`CloisonnementFacturationTest::testUtilisateurHorsEtablissementNAccedePas` attend 403, reçoit 404.

    le test                        1ccd71d2   2026-08-18
    EstablishmentHeaderListener    e915c94e   2026-09-06   « l en-tete X-Etablissement […] confrontes au perimetre »

Le listener a changé **deux jours avant** que cette branche existe. `main` est donc rouge sur ce test
indépendamment de ce lot. Non corrigé : ce n'est ni ma voie ni mon changement, et une assertion de
sécurité ne se réécrit pas en passant.

**2026-09-08 — Étape 4 faite** : `EcheanceSepaDue.tauxTvaValeur` (champ optionnel en fin de
constructeur, les 3 sources existantes inchangées) et `SportEcheanceSepaSource` qui le remplit.
⚠ La relation se parcourt **à l'envers** : `Produit.formule` est le côté propriétaire d'un `OneToOne`
sans côté inverse, donc on interroge les produits PAR leurs formules — une requête pour toute la
remise, jamais une par échéance. SEPA 80 tests / 0 échec, Sport 80 / 0.

**2026-09-08 — ⚠ LA SOURCE CHOISIE EN U-1 EST VIDE. MESURÉ, PAS SUPPOSÉ.**

    off_produit            20 produits,  2 portent un taux_tva
    dont formule_id NOT NULL   4 abonnements,  0 porte un taux_tva
    fixtures                   aucune ne pose de taux sur un produit d'abonnement

U-1 a tranché « le taux vient du `Produit`, et on refuse d'émettre plutôt que d'inventer ». La
décision reste juste — un taux inventé part dans une facture scellée qui ne se corrige plus. Mais
telle quelle, G-1 refuserait **100 % des abonnements** : le lot serait correct et **inerte**.

Ce n'est pas un défaut de code, c'est une précondition de données que ni la spec ni le plan n'avaient
mesurée. Elle se lève soit en saisissant les taux sur les 4 produits concernés, soit en ajoutant un
défaut d'établissement propre aux abonnements des adhérents (l'option 3 de la question U-1, non
retenue à l'époque faute de connaître ce chiffre). Arbitrage rendu à Maxime le 08/09.

**2026-09-08 — Décision de Maxime : un taux par défaut d'établissement, pour TOUT ce qui est
facturable.** Posé comme `ParametreFacturationEtablissement.tauxTvaDefaut` (migration
`Version20260908094500`) et réglable dans l'écran Paramètres › Facturation, qui faisait déjà du
`PATCH` sur cette ressource — donc **aucune opération sans écran** créée, ce que le cliquet n°15 à
524/524 n'aurait pas pardonné. Les deux moitiés sont prouvées séparément : le champ existe au mapping,
et il est présent dans le chunk **construit** (`Facturation-BGjEt8u6.js`), avec un contrôle négatif.
⚠ Aucune valeur n'est posée par migration, contrairement aux comptes de trésorerie : un compte faux se
corrige, un taux de TVA faux part dans une facture scellée. L'ordre est produit → défaut → refus.

**2026-09-08 — ⚠ UN GARDE-FOU A ATTRAPÉ UN DÉFAUT MUET DANS MON ÉTAPE 4, ET IL AVAIT RAISON.**
J'avais écrit `->where('p.formule IN (:formules)')` avec des `Uuid`. `setParameter` **ne convertit pas
les éléments d'un tableau** : la requête rendait une liste vide *sans lever*. Le taux n'aurait jamais
été résolu, et le symptôme aurait été « aucun produit ne porte de taux » — un défaut déguisé en donnée
manquante, que les 80 tests de Sport traversaient au vert.

Réécrit en SQL direct avec `UNHEX`, le remède que le garde-fou prescrit lui-même. **Vérifié dans les
deux sens** : en remettant la forme DQL, les deux nouveaux témoins tombent ; restaurée, ils passent.
Ce n'était donc pas un faux positif, et je n'ai pas réécrit du code sain.

⚠ Et aucun test existant ne pouvait le voir, parce qu'aucune fixture ne pose de taux sur un produit
d'abonnement : c'est `TauxTvaEcheanceSepaTest` qui en pose un, pour que l'absence cesse d'être
indiscernable du défaut.

**2026-09-08 — ⚠ MA PROPRE RÉCUPÉRATION D'ERREUR DÉTRUISAIT LE DIAGNOSTIC.**
`InstallmentInvoicer` retirait la réservation par `$em->remove()` + `flush()` après un échec
d'émission. Or **une exception pendant le flush FERME l'EntityManager** : la récupération levait alors
« The EntityManager is closed », et c'est cette erreur-là que voyait l'appelant — à la place de celle
qui explique quelque chose. Trois tests ont échoué en n'affichant que la plomberie de mon propre
rattrapage.

Le nettoyage passe désormais par la **connexion DBAL**, qui survit à la fermeture de l'ORM, et il est
muet s'il échoue à son tour : perdre la cause coûte plus cher qu'une réservation orpheline. Deux vrais
motifs sont apparus dès que le masque est tombé — voir ci-dessous. Aucun des deux n'aurait été trouvé
autrement.

**2026-09-08 — Deux préconditions d'émission que ni la spec ni le plan n'avaient vues.**
1. **Un compte de produit est obligatoire** — par catégorie comptable mappée sur la ligne, ou par
   `compteProduitDefaut` de l'établissement. La préproduction le porte (1/1), les fixtures non.
   ⚠ Et ce réglage **n'est éditable nulle part** : `grep compteProduitDefaut` sur l'écran de
   paramètres rend **0**. Un établissement neuf ne pourrait donc rien facturer, sans recours. Signalé,
   non corrigé — à joindre au même écran que le taux par défaut.
2. **Une période comptable ouverte doit couvrir le jour d'émission.** `periodePour()` REFUSE (il ne
   crée pas, contrairement à `resoudreOuCreer` utilisé côté encaissement).

**2026-09-08 — Fait appris en faisant échouer le test : la facture est datée du JOUR D'ÉMISSION.**
`EmettreFactureDirecteHandler` pose `dateEmission = now()` ; seule `dateEcheance` dérive de l'échéance.
Le modèle « une facture par mois, datée de son mois » de U-2 ne tient donc que **si la tâche tourne
quotidiennement** — auquel cas le jour d'émission EST le jour d'échéance. C'est un argument de plus
pour la date de prise d'effet de l'étape 7 : au premier passage, tout l'arriéré serait daté d'aujourd'hui.

**2026-09-08 — Étape 7 vue MORDRE et ÉPARGNER, comme l'exige `infra/ordonnanceur.sh:46`.**
Sur une base jetable chargée des fixtures complètes :

    a blanc, plancher par defaut   a facturer : 1 · anterieure(s) au plancher : 1
    a blanc, --depuis=2020-01-01   a facturer : 2 · anterieure(s) : 0
    apres les deux passages a blanc  0 facture, 0 reservation   ← le dry-run n'ecrit rien
    reel, 1er passage              1 emise · 1 epargnee · 0 refus
    reel, 2e  passage              0 emise · 1 DEJA facturee · rien de cree
    en base                        FA-2026-00001, 33,25 HT / 39,90 TTC
    ecriture FAC                   411000 D 3990 · 706100 C 3325 · 4457100 C 665  (equilibree)

**Trois gardes, et elles ne se remplacent pas** : plancher de date à aujourd'hui (l'arriéré ne se
rattrape que par `--depuis`, une décision explicite) ; `--dry-run` qui sort APRÈS les mêmes gardes que
le mode réel ; `safeOnFirstRun: false` au catalogue, que l'ordonnanceur respecte seul. Le planificateur
la voit : `sepa:echeances:facturer  nuit 02:00  JAMAIS`.

**2026-09-08 — La preuve la plus utile fut un refus.** Avant de poser le taux par défaut, le passage
réel a refusé **la totalité** des échéances, en nommant chacune et en expliquant pourquoi — et la
réservation a bien été retirée (0 en base), donc l'échéance reste rejouable. C'est la mesure « 0 produit
d'abonnement sur 4 porte un taux » rendue concrète : le lot est correct et **inerte** tant que le
paramètre n'est pas réglé. C'est exactement ce que le paramètre demandé par Maxime vient débloquer.

**2026-09-08 — Un compteur qui mentait, corrigé avant d'être cru.** Ma première version tranchait
« déjà facturée » sur `issuedAt < aujourd'hui`, ce qui recomptait comme *émise* une échéance déjà
facturée LE JOUR MÊME. Sur une tâche d'argent, un compteur faux est pire que pas de compteur : il
rassure. L'existence est désormais demandée AVANT l'appel, et le second passage rend bien
« déjà facturée(s) : 1 ».

**2026-09-08 — Phase C : D2 respecté, au prix d'un aller-retour sur mon propre travail.**
J'avais d'abord posé sur `IncidentImpaye` un `ManyToOne` vers `Facture` et un autre vers
`MoyenPaiement` — deux frontières de module franchies en dur, ce que D2 interdit (communication par
événements, jamais d'appel direct). Remplacés par une **référence `uuid` nue** et un **code en clair**,
qui sont les conventions du dépôt pour ça — celle du moyen étant même énoncée par
`ReferentielReglementInterface` : « le code du moyen reste stocké en clair ». C'est un abonné de
`Facturation` qui résout la pièce, sur l'événement. Mesuré après coup :
`grep 'App..Facturation|App..Compta' IncidentImpaye.php` → **0**.

**2026-09-08 — Une trace qui manquait complètement : QUI a déclaré le règlement.**
Seule la réouverture *forcée* gardait un acteur, parce qu'elle exige un motif. Constater un règlement
rouvre pourtant un accès et éteint une dette — et `ReglementFacture` exige un auteur, qu'il n'y aurait
eu personne pour fournir. Ajouté `resolu_par_id`.

**2026-09-08 — ⚠ QUATRE TESTS AFFIRMAIENT `app_1_clic` SANS RIEN MESURER.**
`MoteurRecouvrementTest`, `TableauBordTest`, `ResolutionImpayeTest`, `AccesHorsLigneTest` vérifiaient
tous que le canal valait `app_1_clic`. Ce n'était pas une vérification : l'opération refusait tout
corps et le handler posait la valeur en dur — c'était la SEULE que le champ pût porter. Les assertions
recopiaient une constante.

**Et l'une d'elles cachait une mesure fausse.** `TableauBordTest` attendait un
`tauxResolutionSelfService` de **1,0**. Or ce taux ne compte QUE les résolutions `app_1_clic` —
« réglés par le client seul ». Comme aucun autre canal n'était atteignable, il mesurait en réalité
« tous les incidents résolus », sous un nom qui annonçait autre chose. Il vaut 0 pour un virement
constaté par un agent, et c'est juste.

⚠ **Conséquence à traiter en phase D** : `app_1_clic` étant désormais refusé faute de PSP, ce taux
vaudra **0 en permanence** en production. Un écran qui affiche « Réglés par le client seul : 0 % »
sans dire pourquoi se lira comme un échec produit, alors que c'est une fonctionnalité non branchée.

**2026-09-08 — Phase D : trois écrans, et un filtre qui ne filtrait rien.**

Le point dur fut G-8. J'ai d'abord déclaré `ApiFilter(SearchFilter, ['destinataire.clientRef'])`.
Mesuré : **zéro résultat, toujours**. `clientRef` est un `BINARY(16)` (type Doctrine `uuid`) et le
`SearchFilter` le compare à une chaîne de 36 caractères — ça ne trouve rien et ça ne lève rien. Le
dépôt a un décorateur pour ce piège (`UuidAwareSearchFilter`, 145 propriétés recensées), mais il
traite une propriété **directe**, pas un chemin imbriqué.

Deux impasses ensuite, chacune écartée pour une raison nommée :
- une route imbriquée `/crm/clients/{clientId}/factures` exigerait un `Link(fromClass: Client::class)`,
  donc une référence de `Facturation` vers `Crm` dans les métadonnées — D2 l'interdit ;
- étendre le décorateur toucherait un mécanisme partagé par 28 modules pour un seul besoin.

Retenu : chemin littéral `/factures/du-client` + paramètre de requête, lu par `FacturesDuClientProvider`
qui compare avec `setParameter(..., 'uuid')` — le patron de `MesFacturesProvider`.
⚠ Déclaré **avant** `Get /factures/{id}`, sans quoi il serait capté par le chemin paramétré : le
fichier porte déjà cet avertissement pour `verifier-chaine`, qui n'a jamais été atteignable pour cette
raison exacte.
⚠ Et le cloisonnement est reposé **à la main** dans le provider : une requête écrite à la main échappe
entièrement à `PerimetreFacturationExtension`. Deux fuites de ce dépôt sont nées comme ça.

**Le test le prouve dans les deux sens** : deux clients, deux factures, plus un témoin d'assiette qui
vérifie que les deux existent — sans lui, un filtre qui rendrait zéro passerait aussi.

**2026-09-08 — Le garde-fou d'espacement en ligne m'a repris.** Mes quatre `style={{…}}` faisaient
passer le compte de 678 à 682. Remplacés par les classes existantes (`row actions`, `sub`) plutôt que
de régénérer la ligne de base — régénérer aurait gelé la dérive et rendu un vert qui ne mesure plus
rien. Résultat : **677**, sous le plafond.

**2026-09-08 — Le « 0 % » du taux self-service est désormais expliqué à l'écran.** Il vaudra zéro en
permanence tant qu'aucun PSP n'est raccordé ; sans phrase, il se lirait comme « personne ne règle en
ligne » au lieu de « ce chemin n'est pas branché ».

**2026-09-08 — ÉTAT FINAL DES 13 ÉTAPES, MESURÉ.**

    garde-fous       54 OK, exit 0 (espacement en ligne : 677, plafond 678)
    Facturation     105 tests · 1 échec PRÉEXISTANT (403→404, listener du 06/09)
    Compta          128 · 0
    Recouvrement     32 · 0
    Sport            87 · 0
    Sepa             80 · 0
    dérive schéma   108 préexistantes, ZÉRO sur mes tables et colonnes
    frontal          3 écrans présents dans les chunks CONSTRUITS, contrôles négatifs faits
    committé         rien — 45 fichiers en attente

Deux décisions restent à Maxime : (1) committer / ouvrir la PR ; (2) autoriser la commande
`sepa:echeances:facturer` dans `TACHES_AUTORISEES` — elle en est délibérément absente, et
l'ordonnanceur ne la lancera pas de lui-même (`safeOnFirstRun: false`).

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->
