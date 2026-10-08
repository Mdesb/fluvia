# Spec — ticket-opposable

**Statut :** **validée (CP-1) — Maxime, 07/10/2026** (§CP-1), Q-C4 comprise <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** session de maintenance du 07/10 (Claude) ; contradiction par un agent séparé, qui n'a pas écrit la spec
**Date :** 2026-10-07
**Origine :** reprise propre de #50 (`feat/caisse-materiel`) et #59 (`feat/caisse-ticket-route`), décision de Maxime du 07/10 (QCM) : fermer les deux PR, repartir de `main`, reprendre leurs commits avec des migrations renumérotées, en cycle SDD complet. Inventaire morceau par morceau : `features/ticket-opposable/refs/inventaire-pr50-pr59.md`.
**Zone sensible :** argent (règlement), NF525 (chaîne scellée, duplicata), fiscal (TVA).

## Contexte & problème

Le besoin : un client qui paie au guichet repart avec un **ticket qu'on peut lui opposer** — juste sur la TVA, imprimable sur un rouleau de 80 mm, réimprimable à l'identique — et **ne paie jamais deux fois** parce qu'une réponse s'est perdue.

Faits **VERIFIED**, mesurés le 07/10 sur `origin/main` (`4462a2d8`) et sur la base `billetterie_preprod` (lecture seule, par `bin/console dbal:run-sql` dans `billetterie-preprod-php-1`).

### F-1 — Un règlement rejoué encaisse deux fois

- `PaiementHandler::encaisser()` (`app/src/Vente/Service/PaiementHandler.php:48`) ne cherche jamais un règlement déjà enregistré. Le débit du porte-monnaie virtuel (l.112) et l'ordre au TPE (l.126) partent avant toute écriture ; l'`id` fourni (l.153) n'est protégé que par la clé primaire — **globale à toute la table** (`Paiement.php:23-24`) — donc au `flush()`, après le débit.
- `PaiementProcessor` (`app/src/Vente/State/PaiementProcessor.php:35-36`) appelle `encaisser()` puis `flush()`, **sans transaction ni verrou** sur la vente : deux appels simultanés lisent le même reste dû.
- Le débit PMV est un `UPDATE` exécuté sur-le-champ (`app/src/Crm/Adapter/PorteMonnaieVirtuelAdapter.php:64-67`), hors de l'écriture du `Paiement` : si celle-ci échoue ensuite, le porte-monnaie reste débité sans règlement.
- **Deux écrans invitent au double encaissement.** Ni la caisse (`frontend/src/pages/Caisse.jsx:764-768`) ni la souscription d'abonnement (`frontend/src/components/SouscriptionAbonnement.jsx:319`) n'envoient de clé ; l'appel abandonne au bout de 45 s (`frontend/src/api/client.js:657`) alors que nginx attend 60 s (`infra/nginx/billetterie-preprod.conf:142`), et affiche « Le serveur n'a pas répondu à temps (délai dépassé). **Réessayez** dans un instant. » (`client.js:251`) ; la souscription ajoute « reprenez-la depuis la caisse ». Un TPE qui accepte à la 50ᵉ seconde, un caissier qui réessaie : deux débits.
- Trois appelants serveur passent par `encaisser()` : `PaiementProcessor:35`, `SynchroOperationsProcessor:154` (hors-ligne), `Reservation/Facturation/DebitPmvStrategie:76`. Le dernier **ouvre une vente neuve à chaque tentative** (l.65) et valide *après* le débit PMV, hors de son `try` (l.85) : si la validation échoue, la facturation de no-show reste « à facturer » et une nouvelle tentative débite de nouveau, sur une autre vente.
- `CardRejectionRecorder` (PAY-3) fait un `flush()` puis publie de façon synchrone au milieu de l'encaissement (l.62-95).
- Aucun TPE réel n'est branché : `services.yaml:138` câble `TpeMock`, pour tous les environnements. Un refus ou un timeout ne crée aucun `Paiement` (CA-10).

### F-2 — La vente ne connaît pas la TVA, et trois calculs se contredisent

- `LigneVente` ne porte aucun taux ; aucune occurrence réelle de « tva » dans `app/src/Vente`.
- La TVA d'une vente n'existe qu'**après coup**, lue le jour du calcul depuis la **correspondance comptable de la catégorie** du produit :
  - les **écritures** (`app/src/Compta/Regime/RegimeBase.php:31-71`) exigent une correspondance valide (`MappingComptable::estValide()` : compte et taux actifs, l.131-134) et extraient la TVA du TTC **ligne par ligne** (l.50) ;
  - la **facture justificative** (`app/src/Facturation/Service/EmissionFactureJustificativeHandler.php:196-236`) n'exige aucune validité, prend **« hors champ 0 % » en silence** sans correspondance (l.235), extrait la TVA du TTC puis **recalcule à l'endroit** : HT = quantité × prix unitaire HT arrondi, TVA = HT × taux (`LigneFacture::recalculer()`, `LigneFacture.php:220-227`). Exemple : une ligne de 1,15 € à 10 % donne 1,05 + 0,10 dans les écritures et **1,05 + 0,11 = 1,16 €** sur la facture.
- `Produit::$tauxTva` (`app/src/Offre/Entity/Produit.php:232`) sert aux **échéances d'abonnement** (U-1 tranché par Maxime le 08/09, `features/chaine-encaissement/specs/spec-chaine-encaissement.md` ; `InstallmentInvoicer::tauxApplicable()`), pas aux ventes. La fiche produit le dit depuis #270 : « Ce taux sert aux factures des échéances d'abonnement. Pour les ventes, la comptabilité prend le taux de la catégorie » (`frontend/src/components/ProduitFiche.jsx:1472`). Le cahier aussi : « TVA appliquée → M6 » (`specs/L2-vente/spec-vente.md:26`).
- **#50 gravait `Produit::$tauxTva` sur la ligne.** Mesure préprod : 2 produits sur 21 en portent un (10 %), et **pour ces deux-là la correspondance comptable dit 20 %**. Le ticket de #50 aurait affiché 10 % quand les comptes enregistrent 20 %. À l'inverse, 13 produits sur 21 ont une catégorie comptable correspondue.
- Un produit publié a forcément une catégorie comptable (`PublicationGuard.php:35`) et la caisse ne vend que du publié (#274). Restent deux trous : une catégorie **sans correspondance valide** (2 produits sur 15 en préprod), et une ligne qui ne désigne **aucun produit** (réservation sans produit, `LineLabelStamper.php:30-31`) — les écritures la mettent alors en anomalie (`MappingComptableGuard.php:30-32`).
- Quatre services créent une `LigneVente` (`AjoutLigneHandler:119`, `VenteReservationHandler:61`, `ConfirmerCommandeHandler:175`, `SouscriptionAbonnementEnLigneHandler:169`), plus la synchronisation et le jeu de données ; `LineLabelStamper` est un écouteur `prePersist` : il peut graver, pas refuser.
- `TauxTva::$taux` se modifie par `PATCH` (droit `compta.gerer`) ; la chaîne M6 a dû figer le taux dans son instantané pour cette raison (`ScellementEcritureHandler.php:48-51`). Une clé vers `TauxTva` ne suffit donc pas à figer un taux.

### F-3 — Le ticket n'est ni complet, ni compté, ni daté juste

- `TicketProcessor` construit le document en place (`app/src/Vente/State/TicketProcessor.php:125`) : lignes gravées (D61), mais ni TVA, ni vendeur, ni moyens de paiement. Il n'exige pas une vente validée.
- `Vente::$imprime` est un booléen : aucun compteur, aucune trace de réédition, alors que le cahier exige « renvoi et duplicata **tracés** » (`specs/L2-vente/spec-vente.md:84`).
- Deux choses posent `imprime` sans qu'aucune imprimante ne soit pilotée : la validation au-dessus du seuil (`ValiderVenteService.php:201` ; seuil par défaut 0,00 €, `TicketPrintingPolicy::impressionAutomatique()`), et **l'écran, qui appelle `POST /ticket` en mode `imprimer` après chaque validation** pour afficher le ticket (`Caisse.jsx:943`). Préprod : 44 des 50 ventes validées sont « imprimées ». L'historique appelle déjà `POST /ticket` en mode `duplicata` (`Caisse.jsx:1144`). Le bouton « Imprimer le ticket » est un `window.print()` de l'écran (`Caisse.jsx:2056`) : réimpressions illimitées, aucune trace. Le ticket affiché porte le code d'accès du billet (`Caisse.jsx:2040-2045`).
- Le payload scellé d'une vente (`ValiderVenteService::payload()`, l.394-418) ne porte ni taux ni TVA. La vérification recalcule chaque empreinte sur le payload **stocké** (`HashChainSignataire.php:85`) : ajouter des champs aux opérations futures ne casse pas les anciennes. Le document, lui, est relu des colonnes de l'entité, protégées seulement au niveau ORM.
- `Vente::$date` est l'ouverture de la vente (`Vente.php:267`) ; c'est elle qui est scellée (`ValiderVenteService.php:412`). PHP tourne en UTC et les dates sont stockées en UTC : la dernière vente validée en préprod est du 20/09 à 23:02 UTC, soit **le 21/09 à 01:02 à Paris**. `Etablissement::$fuseauHoraire` existe (déjà lu par la clôture journalière).
- L'identité du vendeur existe : `ProfilExploitant` porte raison sociale, SIREN, SIRET, n° de TVA intracommunautaire, adresse (`app/src/Compta/Entity/ProfilExploitant.php:78-148`). `Etablissement` ne porte pas de langue.
- Outillage présent : `dompdf/dompdf` ^3.1 (`app/composer.json:16`), `App\Platform\Pdf\PoliceDeclaree`. nginx route `/api` vers le serveur (`billetterie-preprod.conf:135`). L'authentification passe par les en-têtes `Authorization: Bearer` et `X-Etablissement` (`client.js:222-227`). `PerimetreVenteExtension` ne filtre que les fournisseurs API Platform et laisse passer un utilisateur qui n'est pas un `Utilisateur` (l.115-118) — `PartnerUser` et `TerminalUtilisateur` existent.
- Un avoir ne porte qu'un montant (`app/src/Vente/Entity/Avoir.php`) ; l'annulation passe la vente en `annulee`, le remboursement (partiel possible) en `avoir_emis` (`ContrePassationHandler.php:39-73`). L'annulation n'invalide les billets émis que si `imprime` est vrai (l.44-50) ; le remboursement ne les invalide jamais. `rembourser()` (l.58-67) crée un `Avoir` à **montant libre**, sans lignes ni taux, et `RegimeBase::genererEcritureExtourne()` (l.198-216) **contre-passe l'écriture entière**, quel que soit le montant remboursé.

### F-4 — Les migrations de #50 ne peuvent pas partir telles quelles

- `app/migrations/Version20260908094500.php` existe déjà sur `main` (#47, `facturation_parametre.taux_tva_defaut_id`) et figure en préprod dans `doctrine_migration_versions`. La migration homonyme de #50 ne tournerait **jamais** — l'incident exact du garde-fou n°50.
- Ni `vente_paiement.cle_idempotence` ni `vente_ligne.taux_tva` n'existent en préprod.
- Fusion à blanc de #59 sur `main` (`git merge-tree`) : **un seul conflit**, cette migration. Les fichiers Vente touchés n'ont pas bougé sur `main` depuis la base de #50.

## Décisions déjà rendues, qui s'imposent

- **07/10, Maxime** : repartir proprement (ce lot), SDD complet.
- **D7-bis** : un événement se publie après le commit.
- **D45** : on ne modifie jamais une opération scellée ; on ajoute, on scelle, daté du geste.
- **D61** : ce qui va sur le ticket est gravé sur la ligne au moment de la vente, jamais relu du catalogue.
- **D63-bis** : un seul calcul, plusieurs appelants — jamais une copie de règle.
- **D66-ter** : une migration ne fabrique pas de donnée fiscale (aucun taux rétroactif).
- **D107** : sans destinataire, c'est un ticket, pas une facture.
- **D44-bis** : une vente directe n'a ni session, ni espèces, ni impression automatique.
- **U-1 (08/09)** : le taux des **échéances d'abonnement** est celui du produit, et on refuse plutôt que d'inventer.

## CP-1 — validé par Maxime le 07/10/2026

Réponses de Maxime au QCM, telles quelles. Les variantes écartées sont retirées de la spec ; elles restent lisibles dans l'historique de la PR #275. Ces décisions seront consignées dans `COORDINATION/DECISIONS.md` après ce lot de numéros (D111 à D121 en cours d'écriture par d'autres).

| Question | Décision | Effet sur la spec |
|---|---|---|
| **Q-A1** — timeout du terminal | **Bloquer et faire constater** : le règlement ne repart pas au terminal tant que le caissier n'a pas déclaré « accepté » (avec la référence du ticket CB, tracé) ou « non passé » | G-6 |
| **Q-A2** — synchronisation hors-ligne | **Lot dédié**, avant le premier poste hors-ligne ; ce lot ne change pas son comportement | Hors périmètre |
| **Q-B1** — source du taux | **La catégorie comptable**, correspondance valide, **gravée sur la ligne** ; la contradiction échéances ↔ ventes est tranchée à part | G-7 |
| **Q-B2** — concordance | **Ticket = écritures** : TVA extraite du TTC ligne par ligne, puis sommée par taux ; la facture justificative est corrigée dans un **lot Facturation** à part | G-8 |
| **Q-B3** — ligne sans taux | **Refus à la création de la ligne, avant paiement**, partout où l'on peut refuser ; « ventilation incomplète » seulement pour l'historique et le hors-ligne déjà vendu | G-9 |
| **Q-B4** — empreinte NF525 | **La ventilation TVA entre dans l'empreinte** de la vente dès ce lot | G-10 |
| **Q-C1** — trace des éditions | **Journal des éditions scellé à part**, avec son propre stockage et sa propre chaîne | G-14 |
| **Q-C2** — identité du vendeur | **Figée à la validation**, dans le contenu scellé | G-12 |
| **Q-C3** — impression à la validation | **Une impression ne compte que si une imprimante la confirme** ; d'ici le lot ESC/POS, à la demande seulement | G-14 |
| Règle du duplicata (**révisée**) | Le duplicata reproduit **exactement l'original scellé**, avec « DUPLICATA n° k — édité le … » (exigence 9 du référentiel LNE), et **aucune** mention d'avoir ni de correction : l'avoir vit dans ses propres données et son propre justificatif. Remplace la règle « mentions sous le document d'origine » | G-17 |
| Règle — code d'accès | **Gardée** : le ticket PDF n'en porte aucun | G-12 |
| **Q-C4** — avoir | **Justificatif d'avoir complet, dans ce lot**, tranché après recherche aux sources officielles : (1) **enregistrement juste** — un remboursement devient des lignes négatives avec leur taux, réparties puis **figées à la saisie** ; (2) **vente facturée** — la caisse refuse et renvoie vers l'avoir `AVF` ; (3) **justificatif d'avoir** — série distincte, référence de la vente d'origine, lignes, HT/TVA/TTC par taux, remis à la demande ou par e-mail, réimpression en DUPLICATA au journal des éditions | G-19, G-20, G-21 |

**Sources de Q-C4 et de la règle révisée** (citées par Maxime) : BOFiP BOI-TVA-DECLA-30-10-30 §50 et §90 ; référentiel LNE rév. 1.8, exigences 3, 4 et 9 ; CGI art. 272, 289 et annexe II art. 242 nonies A ; BOI-TVA-DECLA-30-20-20-20 §220-260 ; code de l'environnement D541-370 à D541-372 (impression non systématique). Décisions du dépôt qui s'appliquent : D45, RG-M2-07, RG-FACT-05, RG-M6-05, RG-M6-07/08.

## Objectifs (Goals)

### A — Le règlement ne s'encaisse qu'une fois

- **G-1** — Un règlement porte une **clé d'idempotence**, **unique sur toute la table** (comme l'`id`, qui vaut clé). Rejouer une clé déjà enregistrée sur la même vente rend le règlement existant **sans solliciter ni le TPE ni le PMV**, y compris quand la vente a été validée entre-temps (réponse « déjà enregistré », pas un 409 « encaissement clos »). Une clé déjà enregistrée sur **une autre vente** est refusée **avant tout débit**. Chaque appelant fournit sa clé :
  - les deux écrans (`Caisse.jsx`, `SouscriptionAbonnement.jsx`) : G-2 ;
  - `DebitPmvStrategie` : une clé tirée de la **facturation de no-show**, jamais de la vente qu'il ouvre ;
  - la synchronisation hors-ligne : **inchangée** dans ce lot (Q-A2 → lot dédié) ; elle continue d'appeler `encaisser()` sans clé, et les nouvelles gardes ne doivent pas casser ce chemin.
- **G-2** — Un écran génère une clé **par intention de règlement** (un moyen, un montant) et la garde **jusqu'à une issue définitive** : tout réessai, tout nouveau clic sur « Régler » pendant l'attente, tout rechargement de la page réemploie la clé en attente (ou l'écran relit les tentatives de la vente à son retour). Le coupe-circuit ne dit plus « réessayez » : il dit que le résultat est inconnu et rejoue avec la **même** clé.
- **G-3** — Deux demandes simultanées sur une même vente (double clic, deux onglets, réessai pendant l'attente du TPE) sont **sérialisées par une tentative unique « en cours » par vente** (contrainte d'unicité et statut), pas par un verrou de base tenu pendant l'appel au terminal : la seconde reçoit « en cours » et rejoue plus tard. Jamais deux sollicitations du TPE ou du PMV pour une même clé ; la somme encaissée ne dépasse jamais le dû, hors rendu espèces.
- **G-4** — Même clé, contenu différent (moyen ou montant) : **refus explicite**, aucun effet.
- **G-5** — Ce qui déplace l'argent et ce qui l'écrit forment **une seule unité** : débit PMV et `Paiement` ; pour `DebitPmvStrategie`, débit, `Paiement`, validation de la vente et statut de la facturation de no-show, sérialisés sur cette facturation. Aucun `flush()` intermédiaire dans l'unité de l'appelant ; les événements (dont le refus de carte de PAY-3) partent **après le commit** (D7-bis).
- **G-6** — La **tentative** vers le terminal est écrite (et validée en base) **avant** l'appel, avec sa clé : si le processus meurt pendant l'attente, un rejeu sait qu'une demande est partie sans issue connue. Après un **timeout** — ou une tentative sans issue — le même règlement **ne repart pas au terminal** (Q-A1) tant que le caissier n'a pas déclaré ce qu'affiche le terminal :
  - « **accepté** » : il saisit la référence du ticket CB ; le règlement est créé avec cette référence ; la déclaration est tracée (qui, quand, quelle tentative) ;
  - « **non passé** » : la tentative est close, un nouvel envoi est permis.

### B — La TVA est juste, une fois pour toutes

- **G-7** — Chaque ligne **grave à sa création** le taux appliqué — sa **valeur**, sa catégorie EN 16931 et son libellé, pas seulement une clé vers `TauxTva` (F-2) — depuis la **correspondance comptable de la catégorie du produit** (Q-B1), exigée valide (`MappingComptable::estValide()`) au moment de la création. `Produit::$tauxTva` n'est pas lu. Gravé par `LineLabelStamper` (le même mécanisme que le libellé) ; jamais relu ensuite. Seul le **taux** est gravé : le compte de produit reste lu à la génération des écritures.
- **G-8** — La ventilation (par taux : base HT, TVA, TTC) sort d'**un seul calcul** (D63-bis), partagé par le ticket et les écritures de vente : TVA extraite du TTC **ligne par ligne**, puis **sommée par taux** (Q-B2). Sur une même vente, **ticket = écritures, au centime**. Les écritures lisent le taux gravé ; sans taux gravé (ventes antérieures), elles gardent leur lecture actuelle (D66-ter). La facture justificative n'est pas touchée : elle sera corrigée dans un lot Facturation à part.
- **G-9** — Une ligne sans taux résolu n'est **jamais** rangée à 0 % en silence. Elle est **refusée à sa création, avant tout paiement**, partout où l'on peut refuser (Q-B3) ; « ventilation incomplète » n'apparaît que pour l'historique et pour une vente hors-ligne déjà faite. Le refus vient du **créateur de la ligne** — pas de l'écouteur, qui ne fait que graver :
  - caisse (`AjoutLigneHandler`) : à l'ajout au panier ;
  - réservation et no-show (`VenteReservationHandler`, `DebitPmvStrategie`) : avant le débit, comme le no-show refuse déjà un créneau sans produit (`DebitPmvStrategie.php:56`) ;
  - boutique (`ConfirmerCommandeHandler`) : à la création de la ligne (l.175), avant la confirmation du paiement (l.213) ;
  - abonnement en ligne (`SouscriptionAbonnementEnLigneHandler::souscrire()`, l.83) : en tête, avant le mandat (l.152) et le règlement (l.177) ;
  - synchronisation hors-ligne : la vente a déjà eu lieu, la ligne est gravée « sans taux » et le ticket le dit.
- **G-10** — La ventilation (par ligne, le taux ; par taux, base HT, TVA, TTC) et l'identité du vendeur (G-12) **entrent dans le payload scellé** de la vente dès ce lot (Q-B4, Q-C2). `DocumentTicket` **confronte** ce qu'il imprime au payload scellé et signale tout écart (une ligne altérée en SQL donne un duplicata en anomalie).

### C — Le ticket est complet, compté et fidèle

- **G-11** — **Un seul document** (`DocumentTicket`) lu par la réponse JSON et par le PDF ; son extraction de `TicketProcessor` est prouvée par l'égalité de la sortie (filet de #50).
- **G-12** — Rendu **PDF 80 mm**, sans troncature quel que soit le contenu (libellés longs, nombreuses lignes), portant : identité du vendeur — l'exploitant (raison sociale, adresse, SIRET, n° de TVA intracommunautaire) et le nom du site, **figés à la validation** dans le contenu scellé (Q-C2) —, n° de ticket, **`Vente::$date`** dans le **fuseau de l'établissement** (jamais l'horodatage du scellement), lignes (libellé gravé, quantité, prix unitaire, remise, montant), total TTC, ventilation TVA, moyens de paiement et rendu, mention d'édition (G-14). **Jamais** « logiciel certifié NF525 » — nous ne le sommes pas, un test l'interdit. **Jamais de code d'accès** : le billet reste un document distinct, et un duplicata ne doit pas devenir un second billet. Libellé en français, à défaut la seule traduction disponible.
- **G-13** — **Pas de ticket pour une vente non validée** : refus, quel que soit le canal (JSON, PDF, impression).
- **G-14** — **Toute émission est comptée et tracée** dans un **journal des éditions scellé à part** (Q-C1) : son propre stockage (pas `nf525_operation_scellee`), sa propre chaîne d'empreintes par point de vente, même algorithme que la chaîne des ventes, un numéro d'édition unique par vente. La première émission est l'original, les suivantes portent « DUPLICATA n° k » et la date de la réédition. La mention se déduit **du nombre d'éditions comptées**, plus de `imprime`.
  - **Émettre, c'est produire le papier** : aujourd'hui le PDF demandé. La validation **ne compte aucune édition** tant qu'aucune imprimante ne confirme l'impression (Q-C3) ; d'ici le lot ESC/POS, le ticket est **à la demande seulement**. L'affichage du ticket à l'écran après la validation (`POST /ticket`, `Caisse.jsx:943`) est une consultation : il ne compte pas.
  - La route du PDF est une **écriture** (`POST`), jamais un `GET` qu'un rafraîchissement ou un préchargement rejouerait.
  - `imprime` garde son seul rôle et ses points de pose actuels — dire à l'annulation qu'il faut invalider les billets (F-3) — ; toute émission le pose aussi, rien ne le retire, et la règle Q-C3 ne change pas ce que l'annulation lit.
  - Le seuil d'impression (CA-11) ne déclenche plus une impression réputée faite : au-dessus du seuil, l'écran **propose** d'imprimer.
- **G-15** — Route sous `/api` (nginx), droit `vente.lire` comme `POST /ventes/{id}/ticket`. Cloisonnement par le même fournisseur que `/ventes/{id}/ticket`, ou par un contrôle explicite vente ↔ établissement actif ; un utilisateur qui n'est pas un `Utilisateur` (jeton partenaire, terminal d'accès) n'obtient rien. Hors périmètre : **404**, jamais 403. L'écran récupère le PDF par un appel authentifié par en-têtes, **jamais un jeton dans l'URL**.
- **G-16** — Écran : « Imprimer le ticket » (aujourd'hui `window.print()`, `Caisse.jsx:2056`) et « Réimprimer » de l'historique (aujourd'hui `POST /ticket` en mode `duplicata`, `Caisse.jsx:1144`) passent par le **PDF serveur compté**.
- **G-17 (révisé)** — Le duplicata reproduit **exactement l'original scellé** — mêmes lignes, mêmes totaux, même ventilation, même vendeur — avec la seule mention « DUPLICATA n° k — édité le … ». **Aucune** mention d'avoir ni de correction de règlement ne s'y ajoute. Une vente annulée ou remboursée reste réimprimable : son duplicata est l'original.

### C-bis — L'avoir est juste, et il a son justificatif

- **G-19 — Enregistrement juste.** Un avoir (annulation ou remboursement) porte des **lignes négatives**, chacune avec son **taux gravé**, repris des lignes de la vente d'origine. Un montant global est **réparti puis figé à la saisie** :
  - quand il vise des lignes, au **taux de chaque ligne** visée ;
  - quand c'est un geste global, **au prorata des bases par taux** de la vente d'origine ;
  - jamais au-delà de ce qui reste remboursable, par ligne et au total ;
  - rien n'est recalculé ensuite (même règle que G-7).
  Les lignes et leur ventilation **entrent dans le payload scellé** de l'avoir (même patron que G-10). L'annulation produit toutes les lignes en négatif. Les écritures en dérivent : **`RegimeBase::genererEcritureExtourne()` contre-passe ce que l'avoir porte**, et non plus l'écriture entière ; même calcul ligne par ligne que G-8.
- **G-20 — Vente facturée.** La caisse **refuse de rembourser** une vente qui a donné lieu à une facture, et dit où aller : l'avoir de facture `AVF` (RG-FACT-05, CGI 272-1 et 289 I-5). Refus avant tout effet (aucun avoir, aucun recrédit de porte-monnaie). L'**annulation** d'une vente facturée : selon **P-3** ; l'**enregistrement** du remboursement d'une vente facturée : selon **P-4** (§Points UNVERIFIED).
- **G-21 — Justificatif d'avoir.** Un document propre à l'avoir, de **série distincte**, qui porte :
  - la référence de la vente d'origine (numéro et date) ;
  - les lignes remboursées ;
  - HT, TVA et TTC par taux ;
  - le vendeur figé de la vente d'origine ;
  - la mention de certification — **voir le point bloquant ci-dessous**.
  Le justificatif est remis **à la demande** ou **par e-mail**, jamais imprimé d'office (loi AGEC). Chaque émission passe au **journal des éditions** (G-14) ; une réimpression porte « DUPLICATA n° k — édité le … ». Pas de code d'accès. Même route et mêmes contrôles que le ticket (G-13, G-15).
  - ⚠ **Point bloquant pour l'étape du justificatif — à trancher par Maxime :** la « mention de certification » contredit G-12 (« jamais « logiciel certifié NF525 » — nous ne le sommes pas »). Quelle mention exacte pour un logiciel non certifié ?
  - ⚠ **Envoi par e-mail :** un expéditeur existe (Symfony Mailer, patron `Boutique/Notification/ConfirmationCommandeMailer.php`), mais `app/.env` porte `MAILER_DSN=null://null` et le prestataire reste à désigner (D82). Règle D94 : un canal non raccordé **refuse**, il n'annonce jamais un succès. Transport réel de la préprod : **UNVERIFIED**.

### D — Le portage

- **G-18** — Les migrations sont **renumérotées** après la dernière de `main` (`Version20261006105004` au 07/10), écrites à la main, `BINARY(16)` pour les uuid ; `Version20260908094500` (#47) n'est pas touchée. Leur nombre se compte d'après les objectifs, pas d'après #50 : clé du règlement, tentatives (G-6), taux gravé et ses attributs (G-7), journal des éditions (Q-C1), lignes d'avoir et leur taux gravé (G-19).

## Cas limites

| Cas | Comportement attendu | G |
|---|---|---|
| TPE accepte, réponse perdue (nginx 60 s), l'écran réessaie | même clé → règlement existant rendu, carte non repassée | G-1, G-2 |
| L'écran abandonne à 45 s pendant que le TPE travaille | « résultat inconnu », rejeu avec la même clé ; il reçoit « en cours » tant que la première tentative attend | G-2, G-3 |
| Nouveau clic sur « Régler », ou F5, pendant l'attente | la clé en attente est réemployée (ou retrouvée) : aucun second débit | G-2 |
| Double clic sur « Régler » | une seule intention, une seule clé, un seul débit | G-2, G-3 |
| Deux onglets ou deux postes sur la même vente | une seule tentative en cours ; le second voit ensuite le reste dû à jour | G-3 |
| Paiement scindé volontaire : 2 × 20 € par carte | deux intentions, deux clés, deux règlements | G-2 |
| Même clé, montant différent | refus, aucun effet | G-4 |
| Clé déjà enregistrée sur une autre vente | refus avant tout débit | G-1 |
| No-show facturé deux fois (deux agents, ou relance après un échec de validation) | un seul débit : clé de la facturation, unité unique, sérialisée | G-1, G-5 |
| Refus TPE puis nouvel essai | aucun argent n'a bougé : nouvel envoi au terminal permis | G-1 |
| Timeout TPE (ou tentative sans issue) puis nouvel essai | pas de nouvel envoi au terminal tant que le caissier n'a pas déclaré « accepté » (référence CB) ou « non passé » | G-6 |
| PMV débité, écriture du règlement en échec | tout est annulé, PMV compris | G-5 |
| Rejeu après validation de la vente | « déjà enregistré », pas de 409 | G-1 |
| Synchronisation hors-ligne rejouée | inchangée dans ce lot (lot dédié, Q-A2) ; les nouvelles gardes ne la cassent pas | G-1 |
| Taux légal modifié après la vente | le duplicata garde le taux gravé | G-7 |
| `TauxTva` corrigé par `PATCH` après la vente | idem : la valeur est gravée, pas la clé | G-7 |
| Correspondance comptable changée entre la vente et la génération des écritures | les écritures lisent le taux gravé : ticket = comptes | G-8 |
| Catégorie sans correspondance valide | refus par le créateur de la ligne, **avant** tout règlement | G-9 |
| Ligne de vente altérée en SQL après la vente | duplicata signalé en anomalie | G-10 |
| Vente en cours (non validée) | aucun ticket, quel que soit le canal | G-13 |
| Vente validée par l'écran, puis « Imprimer » | **original** : l'affichage ne comptait pas | G-14 |
| Vente directe (D44-bis) | pas d'impression automatique ; la première émission est l'original | G-14 |
| Vente gratuite | règle existante (`TicketPrintingPolicy`) inchangée | — |
| Vente annulée / remboursée partiellement | duplicata = l'original exact ; l'avoir a son propre justificatif | G-17, G-21 |
| Correction de règlement (D45) | duplicata = l'original exact, sans mention | G-17 |
| Remboursement d'un montant global sur une vente à deux taux | réparti au prorata des bases par taux, figé à la saisie, lignes négatives gravées | G-19 |
| Remboursement visant une ligne | au taux de cette ligne ; jamais plus que ce qui reste remboursable | G-19 |
| Remboursement partiel puis génération des écritures | l'extourne porte ce que porte l'avoir, pas l'écriture entière | G-19 |
| Vente qui a donné lieu à une facture | la caisse refuse l'annulation et le remboursement, renvoie vers l'avoir `AVF` | G-20 |
| Justificatif d'avoir demandé deux fois | original, puis DUPLICATA n° 2 au journal des éditions | G-21 |
| Envoi par e-mail avec un transport non raccordé | refus explicite, jamais « envoyé » (D94) | G-21 |
| Vente ouverte à 23:30 UTC | date et jour du fuseau de l'établissement | G-12 |
| Libellé de 120 caractères, 40 lignes | aucune troncature | G-12 |
| Utilisateur d'un autre établissement, jeton partenaire, jeton de terminal | 404 | G-15 |

## Hors périmètre

- **ESC/POS** et pilotage d'une imprimante thermique, tiroir-caisse (le PDF s'imprime partout ; arbitrage de Maxime rappelé par #50).
- **Adaptateur TPE réel** (interroger le sort d'une transaction) : lot prestataire.
- ⚠ **`TpeMock` est câblé pour tous les environnements** (`services.yaml:138`, avant `when@test` l.429) : l'en-tête `X-Tpe-Simule` permet à n'importe quel appelant de forcer « accepté ». À fermer avant tout usage réel — lot TPE, signalé.
- **Renvoi du ticket par e-mail/SMS** : aucun expéditeur n'existe (`TicketProcessor`, mode `renvoyer` refusé).
- **Synchronisation hors-ligne** : lot dédié, avant le premier poste hors-ligne (Q-A2) ; ses défauts mesurés (terminal re-sollicité, débit PMV sans trace, vente jamais scellée) y sont listés en C-4 et C-11.
- **Facture justificative** : concordance au centime avec la vente et avec le ticket dans un **lot Facturation** à part (Q-B2), validateur Factur-X à l'appui.
- **Invalidation des billets à l'annulation et au remboursement** : elle dépend d'`imprime` et le remboursement ne la fait jamais (`ContrePassationHandler.php:44-76`) ; ce lot n'y touche pas (G-14), à traiter avec le lot annulation (#93).
- « Imprimer le billet » (`Caisse.jsx:1958`, `window.print()`) : le billet n'est pas le ticket.
- `Etablissement::$langue` (périmètre Organisation) : français par défaut d'ici là.
- Réconciliation des taux **échéances ↔ ventes** pour un même produit (U-1) : arbitrage séparé (Q-B1).
- Reprise des ventes historiques : aucun taux rétroactif (D66-ter) ; leurs duplicatas disent « TVA non ventilée — vente antérieure au JJ/MM/AAAA ».
- Paiement en ligne (boutique, PSP) : il ne passe pas par `PaiementHandler`.
- `CardDebitFallback` (bascule carte → prélèvement) : non branché ; noté pour son lot qu'un **timeout n'est pas un refus**.
- **Validation rejouée** : un second « Valider » rend 409 sans effet (`ValiderVenteService`, « Seule une vente en cours peut être validée ») et l'unicité de séquence empêche un double scellement ; l'écran n'en fait pas encore un succès.
- La facture justificative désigne la ligne par le libellé **du catalogue** (`EmissionFactureJustificativeHandler.php:209`, `getLibelleRecherche()`), pas par le libellé gravé : écart signalé, non traité ici.
- Ticket imprimé par un **poste hors-ligne** : le front actuel n'a pas de mode hors-ligne ; la synchronisation remonte des ventes, pas des éditions.
- Certification NF525.

## Parcours utilisateur / UX

1. **Régler** — inchangé pour le caissier : moyen, montant, « Régler ». Si le serveur tarde, l'écran affiche « Paiement en cours de vérification » et relance seul avec la même clé ; « Régler » reste lié à ce paiement en attente ; il ne dit plus « réessayez ». Même chose sur l'écran de souscription. Après un timeout du terminal, l'écran demande ce qu'affiche le terminal : « accepté » (saisie de la référence du ticket CB) ou « non passé » (nouvel envoi permis).
2. **Ticket** — à la validation, l'écran affiche le ticket (consultation, non comptée). « Imprimer le ticket » ouvre le PDF 80 mm : c'est l'original. Au-dessus du seuil, l'écran propose d'imprimer ; rien n'est réputé imprimé.
3. **Réimprimer** — depuis l'historique des ventes : « Réimprimer » produit le PDF ; le papier porte DUPLICATA, son numéro et la date du jour.
4. **Rembourser** — le caissier choisit les lignes remboursées, ou saisit un montant global que l'écran montre réparti par taux avant de valider ; une vente facturée est refusée avec le renvoi vers l'avoir de facture `AVF`. Le justificatif d'avoir s'imprime à la demande (PDF) ou part par e-mail.
5. **Erreurs** — produit sans TVA réglée : refusé **à l'ajout au panier**, avec le geste qui répare (« réglez la TVA de la catégorie X dans Compta › Correspondances ») ; vente non validée : « le ticket existe une fois la vente validée ».

## Contraintes & décisions techniques connues

- **Portage** : les 7 commits utiles de #50/#59 servent de base (aucun conflit hors migration) ; les écarts de G-1…G-18 s'ajoutent en commits séparés. Les 3 commits de fusion ne se reprennent pas.
- **Migrations à la main**, SQL demandé à Doctrine avant (`doctrine:schema:update --dump-sql`), absence de dérive vérifiée après ; jamais un `migrations:diff` brut.
- **NF525** :
  - `Paiement` est append-only (`InalterabiliteListener`) : la clé se pose à la création, jamais après.
  - Une édition comptée est un enregistrement **ajouté**, jamais une écriture sur la vente scellée. Elle ne change aucun total de clôture (ils se calculent sur les ventes, `DailyClosureHandler.php:181-187`).
  - `CHAMPS_VENTE_FIGES` est une **liste noire** (`InalterabiliteListener.php:33-45`) : tout champ absent reste modifiable sur une vente scellée. Toute nouvelle colonne fiscale de `Vente` (instantané vendeur, ventilation) y entre, avec un test.
  - Le journal des éditions (Q-C1) a **son propre stockage**, pas `nf525_operation_scellee` : `ScellementHandler::dernierMaillon()` et `chaine()` ne filtrent que par point de vente (l.37-66), les éditions s'intercaleraient dans la chaîne des ventes. Même algorithme (`HashChainSignataire`), unicité (vente, n° d'édition), entité déclarée append-only dans `InalterabiliteListener::estAppendOnly()` et cloisonnée dans `PerimetreVenteExtension::CHEMINS` (liste blanche).
- **Un seul calcul** (D63-bis) : la ventilation vit dans un service partagé ; les écritures l'appellent, elles ne la recopient pas.
- **Route** : sous `/api` (sinon le repli SPA de nginx sert du HTML avec un 200 ; les tests ne traversent pas nginx — preuve par un appel réel après déploiement).
- **D5** : identifiants anglais dans les fichiers ajoutés.
- **Revue** : `relecteur` et `security-reviewer` en mode adversarial à chaque étape (argent, NF525, cloisonnement).

## Points UNVERIFIED

**CP-1** : levés par les réponses de Maxime du 07/10, Q-C4 comprise. Les points de droit qu'il a tranchés (numéro d'édition d'un duplicata, ventilation dans les données signées, impression systématique et loi AGEC, avoir) le sont **par décision**, appuyée sur les sources qu'il cite (§CP-1), pas par une lecture des textes faite ici.

**Bloquants pour le plan (CP-2)** :

- [x] Point de refus d'une ligne sans taux dans l'abonnement en ligne — **VERIFIED** : en tête de `SouscriptionAbonnementEnLigneHandler::souscrire()` (l.83), avant le mandat (l.152), la ligne (l.169) et le règlement (l.177).
- [x] `bin/verifier-derive-schema.sh` rend-il un verdict ? — **VERIFIED : non.** Mesuré le 07/10 avec le jeton `ticket07` : « Les migrations n'ont pas abouti », `Allowed memory size of 134217728 bytes exhausted`. Les migrations de ce lot se prouveront donc en exécutant leur SQL (`up`, `down`, `up`) sur une copie de la sauvegarde de préprod, puis par `SELECT` sur les colonnes, comme #270.
- [ ] **Mention de certification du justificatif d'avoir** (G-21) : contredit G-12 ; à trancher par Maxime. Bloque l'étape du rendu du justificatif d'avoir.
- [ ] **Transport e-mail réel de la préprod** (G-21, D94) : à mesurer sans lire de secret. Bloque l'étape d'envoi par e-mail.
- [ ] **P-3 — Une vente facturée peut-elle être annulée en caisse ?** La décision du 07/10 dit « refuse de rembourser » ; `annuler()` produit un avoir total comme `rembourser()` (`ContrePassationHandler.php:39-56`), sur le même fondement. Options : A — refus aussi (même règle fiscale, recommandé) ; B — refus du remboursement seulement. À trancher par Maxime ; bloque une ligne de l'étape « vente facturée ».
- [ ] **P-4 — Où s'enregistre le remboursement d'une vente facturée ?** La caisse refusera (G-20) et renverra vers l'`AVF`. Or l'avoir d'une facture **justificative** est une correction documentaire pure, **sans écriture** (`AvoirFactureHandler.php:30-31`), et **total seulement** (l.33) : sans décision, il ne resterait ni avoir NF525 de caisse, ni extourne, ni trace de l'argent rendu. Options : A — l'`AVF` déclenche la contre-passation de caisse liée (lignes, scellement, extourne), le client reçoit l'`AVF` (recommandé par l'architecte) ; B — l'`AVF` d'une justificative génère lui-même l'extourne (change RG-FACT-03) ; C — refus seul pour l'instant, trou consigné. À trancher par Maxime ; aucune étape planifiée d'ici là.
- [ ] **P-5 — Recrédit du porte-monnaie lors d'un remboursement partiel.** `rembourser()` recrédite **toute** la part PMV de la vente à chaque remboursement, même partiel (hypothèse écrite au code, `ContrePassationHandler.php:68-72`) : deux remboursements partiels d'une vente payée 50 € en PMV recréditent 100 €. Options : A — recrédit = le plus petit de (montant de l'avoir, part PMV non encore recréditée), recommandé, ~40 lignes au lot 4 ; B — en l'état, consigné. À trancher par Maxime (argent).

## Critères d'acceptation

- **G-1** : deux appels avec la même clé → un seul `Paiement`, le TPE simulé interrogé une fois (témoin : le second appel forcé en « refus » répond « accepté ») ; idem PMV (solde débité une fois) ; rejeu après validation → règlement d'origine rendu ; clé d'une autre vente → refus, aucun débit. Témoin de ce que la garde épargne : deux règlements identiques **avec deux clés** restent deux.
- **G-2** : chacun des deux écrans (`Caisse.jsx`, `SouscriptionAbonnement.jsx`) envoie la même clé au réessai, au nouveau clic pendant l'attente et après rechargement ; le message du coupe-circuit ne contient plus « réessayez ».
- **G-3** : deux appels **concurrents** (deux connexions) sur une vente à 45 € → un seul débit, le second reçoit « en cours », reste dû cohérent ; test réellement concurrent, pas séquentiel.
- **G-4** : même clé, montant différent → refus, aucun débit.
- **G-5** : échec forcé de l'écriture après un débit PMV → solde PMV intact ; no-show : échec forcé de la validation → aucun débit, facturation toujours « à facturer », relance → un seul débit ; aucun événement publié pour une transaction annulée.
- **G-6** : timeout simulé → le rejeu ne renvoie pas au terminal ; déclaration « accepté » avec référence → un règlement, tracé ; « non passé » → nouvel envoi permis ; processus interrompu pendant l'appel au terminal → la tentative existe, le rejeu ne renvoie pas au terminal.
- **G-7** : produit à 10 %, taux changé à 20 % après la vente → duplicata à 10 % ; `PATCH` du `TauxTva` → duplicata inchangé.
- **G-8** : sur un jeu de ventes multi-taux, ventilation du ticket = écritures, au centime ; un oracle de test indépendant (D67) balaie montants × taux ; la facture justificative n'est pas touchée.
- **G-9** : un refus avant paiement par créateur de ligne (caisse, réservation, no-show, boutique, abonnement en ligne) ; ligne hors-ligne sans taux → « ventilation incomplète » ; jamais un groupe « 0 % » pour une ligne sans taux (témoin : une vraie ligne à 0 % hors champ reste ventilée à 0 %).
- **G-10** : le payload scellé d'une vente neuve porte la ventilation et le vendeur ; vérification de chaîne verte avant et après le changement de format ; une ligne altérée en SQL → duplicata en anomalie.
- **G-11** : le filet de sortie de #50 reste vert sans être retouché pendant l'extraction.
- **G-12** : le HTML du rendu contient chaque mention ; la date est `Vente::$date` dans le fuseau de l'établissement (vente ouverte à 23:30 UTC) ; un ticket de 40 lignes à libellés longs n'est pas tronqué ; « certifié » absent ; aucun code d'accès.
- **G-13** : ticket demandé sur une vente en cours → refus, sur chaque canal.
- **G-14** : vente validée par l'écran (affichage compris), puis trois émissions → original, DUPLICATA n° 2, DUPLICATA n° 3 ; deux réimpressions simultanées → deux numéros distincts ; `imprime` posé ; annulation ensuite → billets invalidés.
- **G-15** : vente d'un autre établissement → 404 ; jeton partenaire, jeton de terminal → 404 ; sans `vente.lire` → 403 ; appel réel après déploiement → `application/pdf`, pas la coquille HTML ; aucun jeton dans une URL.
- **G-16** : plus aucun `window.print()` ni `POST /ticket` en mode `duplicata` derrière « Imprimer le ticket » et « Réimprimer ».
- **G-17** : vente annulée, remboursée ou corrigée puis réimprimée → HTML du duplicata identique à celui de l'original, hors la seule mention « DUPLICATA n° k — édité le … » ; aucune mention d'avoir ni de correction.
- **G-18** : `doctrine:migrations:status` sur une copie de la préprod liste les nouvelles migrations comme à exécuter ; `up`, `down`, `up` passent ; colonnes et tables présentes ensuite (preuve par `SELECT`, pas par le statut).
- **G-19** : remboursement global sur une vente à deux taux → lignes négatives au prorata des bases, somme exacte au centime, figées (un changement de correspondance ensuite ne les change pas) ; remboursement d'une ligne → son taux ; dépassement du remboursable → refus ; payload scellé de l'avoir portant lignes et ventilation ; écritures : l'extourne d'un remboursement partiel porte les montants de l'avoir, au centime ; annulation → extourne égale à l'écriture d'origine (témoin).
- **G-20** : vente facturée → refus d'annuler et de rembourser, message qui renvoie vers l'avoir `AVF`, aucun avoir créé, porte-monnaie intact ; vente non facturée → inchangé (témoin).
- **G-21** : le justificatif porte la série distincte, la référence de la vente (numéro, date), les lignes, HT/TVA/TTC par taux, le vendeur ; deux émissions → original puis DUPLICATA n° 2 au journal ; e-mail avec transport non raccordé → refus ; aucune impression d'office ; aucun code d'accès.

## Contradiction / Réponse

Historique des deux relectures, antérieures au CP-1 : les renvois aux options (« Q-B4-A », « question pour Maxime ») visent le QCM tranché le 07/10 (§CP-1).

### Première relecture (auteur de la spec, 07/10)

Trois axes : ce qui casse la chaîne NF525, ce qui encaisse deux fois, ce qui rend un ticket faux.

- **#50 gravait `Produit::$tauxTva` : le ticket aurait contredit les comptes** (10 % contre 20 % sur les 2 produits mesurés). → **retenue** : source en Q-B1, ticket = écritures (G-8).
- **Arrondi par taux ≠ arrondi par ligne des comptes** : un centime d'écart sur la même vente. → **retenue** : Q-B2.
- **La garde de #50 ne sérialise pas deux appels simultanés** : les deux passent la recherche, les deux débitent, le second échoue au `flush()` après débit. → **retenue** : G-3.
- **Même clé, autre montant : #50 rendait l'ancien règlement en silence.** → **retenue** : G-4.
- **PMV débité hors transaction** : un échec d'écriture laisse le porte-monnaie débité. → **retenue** : G-5.
- **Le front n'envoie aucune clé et dit « Réessayez » à 45 s** : la garde serveur seule ne protège rien. → **retenue** : G-2.
- **Un processus qui meurt pendant l'appel au terminal ne laisse aucune trace** : le rejeu repart au terminal. → **retenue** : tentative écrite avant l'appel (G-6), Q-A1.
- **`DebitPmvStrategie` appelle `encaisser()` sans clé.** → **retenue** : G-1 (voir aussi C-3 ci-dessous).
- **Le `GET` de #59 rend des originaux à l'infini, sans trace, et ne pose pas `imprime`** : une annulation laisserait les billets valides. → **retenue** : émission en `POST`, comptée, qui pose `imprime` (G-14).
- **Une réimpression scellée dans la chaîne des ventes dispute la séquence à une validation** (`uniq_op_pdv_sequence`). → **retenue** : Q-C1, recommandation d'un journal des éditions scellé à part.
- **Ne plus compter d'édition à la validation (Q-C3) ne doit pas affaiblir l'annulation**, qui lit `imprime`. → **retenue** : `imprime` garde son rôle et ses points de pose ; DUPLICATA se déduit du compteur (G-14).
- **Ticket d'une vente en cours** possible par `POST /ticket` comme par la route de #59. → **retenue** : G-13.
- **Date imprimée en UTC.** → **retenue** : fuseau de l'établissement (G-12).
- **Hauteur du PDF estimée à une ligne par libellé** : troncature. → **retenue** : G-12.
- **Une clé vers `TauxTva` ne fige rien** (`PATCH`). → **retenue** : la valeur est gravée (G-7).
- **Mentionner l'avoir ou la correction D45 sur un duplicata** pourrait être lu comme une altération de l'original. → **retenue sous condition** au premier tour ; **remplacée par Maxime le 07/10** : le duplicata reproduit exactement l'original, l'avoir a son propre justificatif (G-17 révisé, G-21).
- **Ventes historiques** : ni taux gravé ni vendeur figé. → **écartée de ce lot** : aucun taux rétroactif (D66-ter), duplicata qui le dit.
- **Validation rejouée → 409.** → **écartée de ce lot** : sans effet sur l'argent ni sur la chaîne.

### Contradicteur indépendant (agent séparé, qui n'a pas écrit la spec, 07/10) — verdict NEEDS FIXES, intégré

Chaque constat a été revérifié dans le code avant d'être intégré.

- **C-1 — L'écran appelle `POST /ticket` (mode `imprimer`) après chaque validation (`Caisse.jsx:943`) ; compté, cet affichage deviendrait l'original et le premier PDF un « DUPLICATA n° 2 » ; il pose aussi `imprime` sur presque toutes les ventes.** VERIFIED, HIGH. → **retenue** : l'affichage est une consultation non comptée, `imprime` garde ses points de pose (G-14) ; critère « validée par l'écran puis Imprimer → original » ; Q-C3 reformulée. Découpler l'invalidation des billets d'`imprime` → **écartée de ce lot**, lot annulation (#93).
- **C-2 — Un second écran encaisse sans clé : `SouscriptionAbonnement.jsx:319`**, même délai, même « Réessayez », et « reprenez-la depuis la caisse ». VERIFIED, HIGH. → **retenue** : G-2 nomme les deux écrans, un critère chacun.
- **C-3 — `DebitPmvStrategie` ouvre une vente neuve à chaque tentative et valide après le débit, hors de son `try` : une clé de portée (vente, clé) ne protège rien, deux agents simultanés débitent deux fois.** VERIFIED, HIGH. → **retenue** : clé tirée de la facturation de no-show (G-1), unité débit + règlement + validation + statut, sérialisée sur la facturation (G-5).
- **C-4 — Synchro hors-ligne : débit PMV sans trace après `clear()`, remontées concurrentes non sérialisées, refus TPE qui committe une vente à moitié construite jamais scellée ensuite.** VERIFIED (code), latent (aucun client hors-ligne). → **question pour Maxime** : Q-A2 (recommandation : lot dédié).
- **C-5 — La facture justificative recalcule à l'endroit (`LigneFacture::recalculer()`) : 1,15 € à 10 % est facturé 1,16 € ; la spec affirmait à tort qu'elle extrait la TVA ligne par ligne comme les écritures.** VERIFIED, HIGH. → **retenue** (F-2 corrigé) et **question pour Maxime** : Q-B2 reformulée (qui cède ; recommandation : facture en lot Facturation dédié, validateur Factur-X à l'appui).
- **C-6 — L'`id` du `Paiement` est une clé primaire globale : « l'`id` vaut clé » contredisait la portée (vente, clé).** VERIFIED, HIGH. → **retenue** : clé unique sur toute la table, refus avant débit si elle appartient à une autre vente (G-1, cas limite corrigé).
- **C-7 — Un journal d'éditions « même mécanisme » ne peut pas vivre dans `nf525_operation_scellee` : les éditions s'intercaleraient dans la chaîne des ventes.** VERIFIED, HIGH. → **retenue** : stockage propre, unicité (vente, n° d'édition), append-only, cloisonnement, critère de réimpressions simultanées (Contraintes, Q-C1-B).
- **C-8 — « Une intention = un clic » réintroduit le double débit : un nouveau clic ou un F5 pendant l'attente crée une nouvelle clé.** VERIFIED, MEDIUM-HIGH. → **retenue** : la clé vit jusqu'à une issue définitive, survit au rechargement (G-2).
- **C-9 — Un verrou de base tenu pendant l'appel au TPE est incompatible avec la tentative committée et dépasse `innodb_lock_wait_timeout`.** VERIFIED (raisonnement), valeur du réglage UNVERIFIED. → **retenue** : sérialisation par tentative unique « en cours », le second appel reçoit « en cours » (G-3).
- **C-10 — `CardRejectionRecorder` fait `flush()` puis publie au milieu de l'unité : l'événement partirait avant un commit qui peut être annulé.** VERIFIED, MEDIUM. → **retenue** : aucun `flush()` intermédiaire, publication après commit (G-5, D7-bis).
- **C-11 — En synchro, le serveur re-sollicite le TPE et le PMV pour un paiement déjà fait hors ligne ; une clé « tirée de l'opération » collerait deux règlements scindés.** VERIFIED, latent. → **question pour Maxime** : Q-A2 (options B et C).
- **C-12 — `LineLabelStamper` est un écouteur `prePersist` : il grave, il ne refuse pas ; G-9 ne citait que la synchro.** VERIFIED, MEDIUM. → **retenue** : G-9 liste chaque créateur de ligne et son point de refus ; l'abonnement en ligne reste à établir au plan (UNVERIFIED, bloquant CP-2).
- **C-13 — `CHAMPS_VENTE_FIGES` est une liste noire ; la spec disait « seuls champs mobiles ».** VERIFIED, MEDIUM. → **retenue** : toute nouvelle colonne fiscale de `Vente` y entre, avec un test (Contraintes).
- **C-14 — Le document relit les colonnes de l'entité, protégées au seul niveau ORM : « un duplicata se prouve contre la chaîne » était une promesse non tenue.** VERIFIED, MEDIUM. → **retenue** : si Q-B4-A, `DocumentTicket` confronte le payload scellé et signale l'écart (G-10).
- **C-15 — Écritures après G-8 : pas de règle de repli pour les ventes sans taux gravé ; le compte de produit reste lu au jour.** VERIFIED, MEDIUM. → **retenue** : sans taux gravé, lecture actuelle (D66-ter) ; seul le taux est gravé (G-7, G-8).
- **C-16 — Le ticket affiché porte le code d'accès : un duplicata pourrait devenir un second billet.** VERIFIED, MEDIUM. → **retenue comme règle** : le ticket PDF ne porte jamais de code d'accès, le billet reste distinct (G-12) ; Maxime peut l'écarter au CP-1.
- **C-17 — Route PDF : `PerimetreVenteExtension` laisse passer un utilisateur qui n'est pas un `Utilisateur` (partenaire, terminal) ; un PDF ouvert dans un onglet ne porte pas les en-têtes d'authentification.** VERIFIED (code). → **retenue** : contrôle explicite, jetons partenaire et terminal refusés, récupération authentifiée par en-têtes, jamais de jeton dans l'URL (G-15).
- **C-18 — Quel instant date le ticket ? `Vente::$date` est l'ouverture, et c'est elle qui est scellée.** VERIFIED, LOW-MEDIUM. → **retenue** : le ticket imprime `Vente::$date`, jamais l'horodatage du scellement (G-12). En synchro, c'est la date de remontée : relève de Q-A2.
- **C-19 — « Correspondance active » non définie ; la facture n'exige aucune validité, la spec disait « même source ».** VERIFIED. → **retenue** : `estValide()` à la création de la ligne (G-7) ; F-2 corrigé.
- **C-20 — « Deux migrations » : les objectifs en ajoutent d'autres.** VERIFIED, LOW. → **retenue** : G-18 compte d'après les objectifs.
- **C-21 — Hors des trois axes : `TpeMock` câblé pour tous les environnements (l'en-tête `X-Tpe-Simule` force « accepté ») ; le remboursement n'invalide jamais les billets ; « Imprimer le billet » non compté.** VERIFIED. → **écartées de ce lot**, signalées dans Hors périmètre (la première est à fermer avant tout usage réel).
- **Affirmations inexactes relevées** (facture « ligne par ligne », « même source », « seuls champs mobiles », 44/50 attribuées à la seule validation, « Réimprimer » présenté comme nouveau, clé « indépendante » sur une autre vente) : **toutes corrigées** dans F-2, F-3, G-1, G-16 et les Contraintes.
