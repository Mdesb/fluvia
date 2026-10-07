# Spec — ticket-opposable

**Statut :** en revue — **CP-1 en attente de Maxime** (10 questions, §Questions CP-1) <!-- brouillon → en revue → validée (CP-1) -->
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
- Un avoir ne porte qu'un montant (`app/src/Vente/Entity/Avoir.php`) ; l'annulation passe la vente en `annulee`, le remboursement (partiel possible) en `avoir_emis` (`ContrePassationHandler.php:39-73`). L'annulation n'invalide les billets émis que si `imprime` est vrai (l.44-50) ; le remboursement ne les invalide jamais.

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

## Objectifs (Goals)

### A — Le règlement ne s'encaisse qu'une fois

- **G-1** — Un règlement porte une **clé d'idempotence**, **unique sur toute la table** (comme l'`id`, qui vaut clé). Rejouer une clé déjà enregistrée sur la même vente rend le règlement existant **sans solliciter ni le TPE ni le PMV**, y compris quand la vente a été validée entre-temps (réponse « déjà enregistré », pas un 409 « encaissement clos »). Une clé déjà enregistrée sur **une autre vente** est refusée **avant tout débit**. Chaque appelant fournit sa clé :
  - les deux écrans (`Caisse.jsx`, `SouscriptionAbonnement.jsx`) : G-2 ;
  - `DebitPmvStrategie` : une clé tirée de la **facturation de no-show**, jamais de la vente qu'il ouvre ;
  - la synchronisation hors-ligne : selon **Q-A2**.
- **G-2** — Un écran génère une clé **par intention de règlement** (un moyen, un montant) et la garde **jusqu'à une issue définitive** : tout réessai, tout nouveau clic sur « Régler » pendant l'attente, tout rechargement de la page réemploie la clé en attente (ou l'écran relit les tentatives de la vente à son retour). Le coupe-circuit ne dit plus « réessayez » : il dit que le résultat est inconnu et rejoue avec la **même** clé.
- **G-3** — Deux demandes simultanées sur une même vente (double clic, deux onglets, réessai pendant l'attente du TPE) sont **sérialisées par une tentative unique « en cours » par vente** (contrainte d'unicité et statut), pas par un verrou de base tenu pendant l'appel au terminal : la seconde reçoit « en cours » et rejoue plus tard. Jamais deux sollicitations du TPE ou du PMV pour une même clé ; la somme encaissée ne dépasse jamais le dû, hors rendu espèces.
- **G-4** — Même clé, contenu différent (moyen ou montant) : **refus explicite**, aucun effet.
- **G-5** — Ce qui déplace l'argent et ce qui l'écrit forment **une seule unité** : débit PMV et `Paiement` ; pour `DebitPmvStrategie`, débit, `Paiement`, validation de la vente et statut de la facturation de no-show, sérialisés sur cette facturation. Aucun `flush()` intermédiaire dans l'unité de l'appelant ; les événements (dont le refus de carte de PAY-3) partent **après le commit** (D7-bis).
- **G-6** — La **tentative** vers le terminal est écrite (et validée en base) **avant** l'appel, avec sa clé : si le processus meurt pendant l'attente, un rejeu sait qu'une demande est partie sans issue connue. Après un **timeout** — ou une tentative sans issue — : comportement tranché en **Q-A1**.

### B — La TVA est juste, une fois pour toutes

- **G-7** — Chaque ligne **grave à sa création** le taux appliqué — sa **valeur**, sa catégorie EN 16931 et son libellé, pas seulement une clé vers `TauxTva` (F-2) — depuis la source tranchée en **Q-B1**, résolue par `MappingComptable::estValide()` au moment de la création. Gravé par `LineLabelStamper` (le même mécanisme que le libellé) ; jamais relu ensuite. Seul le **taux** est gravé : le compte de produit reste lu à la génération des écritures.
- **G-8** — La ventilation (par taux : base HT, TVA, TTC) sort d'**un seul calcul** (D63-bis), partagé par le ticket et les écritures de vente : sur une même vente, **ticket = écritures, au centime**. Les écritures lisent le taux gravé ; sans taux gravé (ventes antérieures), elles gardent leur lecture actuelle (D66-ter). La facture justificative : selon **Q-B2**.
- **G-9** — Une ligne sans taux résolu n'est **jamais** rangée à 0 % en silence ; conduite tranchée en **Q-B3**. Le refus vient du **créateur de la ligne**, avant tout mouvement d'argent — pas de l'écouteur, qui ne fait que graver :
  - caisse (`AjoutLigneHandler`) : à l'ajout au panier ;
  - réservation et no-show (`VenteReservationHandler`, `DebitPmvStrategie`) : avant le débit, comme le no-show refuse déjà un créneau sans produit (`DebitPmvStrategie.php:56`) ;
  - boutique (`ConfirmerCommandeHandler`) : à la création de la ligne (l.175), avant la confirmation du paiement (l.213) ;
  - abonnement en ligne (`SouscriptionAbonnementEnLigneHandler:169`) : point de refus à établir au plan ;
  - synchronisation hors-ligne : la vente a déjà eu lieu, la ligne est gravée « sans taux » et le ticket le dit.
- **G-10** — Scellement de la ventilation (et, selon Q-C2, du vendeur) dans l'empreinte NF525 de la vente : tranché en **Q-B4**. Si oui, `DocumentTicket` **confronte** ce qu'il imprime au payload scellé et signale tout écart (une ligne altérée en SQL donne un duplicata en anomalie).

### C — Le ticket est complet, compté et fidèle

- **G-11** — **Un seul document** (`DocumentTicket`) lu par la réponse JSON et par le PDF ; son extraction de `TicketProcessor` est prouvée par l'égalité de la sortie (filet de #50).
- **G-12** — Rendu **PDF 80 mm**, sans troncature quel que soit le contenu (libellés longs, nombreuses lignes), portant : identité du vendeur (**Q-C2**), site, n° de ticket, **`Vente::$date`** dans le **fuseau de l'établissement** (jamais l'horodatage du scellement), lignes (libellé gravé, quantité, prix unitaire, remise, montant), total TTC, ventilation TVA, moyens de paiement et rendu, mention d'édition (**Q-C1**). **Jamais** « logiciel certifié NF525 » — nous ne le sommes pas, un test l'interdit. **Jamais de code d'accès** : le billet reste un document distinct, et un duplicata ne doit pas devenir un second billet. Libellé en français, à défaut la seule traduction disponible.
- **G-13** — **Pas de ticket pour une vente non validée** : refus, quel que soit le canal (JSON, PDF, impression).
- **G-14** — **Toute émission est comptée et tracée** selon **Q-C1** : la première est l'original, les suivantes portent « DUPLICATA n° k » et la date de la réédition. La mention se déduit **du nombre d'éditions comptées**, plus de `imprime`.
  - **Émettre, c'est produire le papier** (le PDF, demain l'imprimante). L'affichage du ticket à l'écran après la validation (`POST /ticket`, `Caisse.jsx:943`) est une consultation : il ne compte pas.
  - La route du PDF est une **écriture** (`POST`), jamais un `GET` qu'un rafraîchissement ou un préchargement rejouerait.
  - `imprime` garde son seul rôle et ses points de pose actuels — dire à l'annulation qu'il faut invalider les billets (F-3) — ; toute émission le pose aussi, rien ne le retire, et Q-C3 ne change pas ce que l'annulation lit.
- **G-15** — Route sous `/api` (nginx), droit `vente.lire` comme `POST /ventes/{id}/ticket`. Cloisonnement par le même fournisseur que `/ventes/{id}/ticket`, ou par un contrôle explicite vente ↔ établissement actif ; un utilisateur qui n'est pas un `Utilisateur` (jeton partenaire, terminal d'accès) n'obtient rien. Hors périmètre : **404**, jamais 403. L'écran récupère le PDF par un appel authentifié par en-têtes, **jamais un jeton dans l'URL**.
- **G-16** — Écran : « Imprimer le ticket » (aujourd'hui `window.print()`, `Caisse.jsx:2056`) et « Réimprimer » de l'historique (aujourd'hui `POST /ticket` en mode `duplicata`, `Caisse.jsx:1144`) passent par le **PDF serveur compté**.
- **G-17** — Une vente annulée ou remboursée reste réimprimable (le document a existé) ; le duplicata porte, **sous** le document d'origine, les événements postérieurs — « ANNULÉE — avoir n° X du JJ/MM/AAAA », « REMBOURSÉE — avoir n° X, montant », « RÈGLEMENT CORRIGÉ le … (D45) » — sans jamais les fondre dans le document d'origine.

### D — Le portage

- **G-18** — Les migrations sont **renumérotées** après la dernière de `main` (`Version20261006105004` au 07/10), écrites à la main, `BINARY(16)` pour les uuid ; `Version20260908094500` (#47) n'est pas touchée. Leur nombre se compte d'après les objectifs, pas d'après #50 : clé du règlement, tentatives (G-6), taux gravé et ses attributs (G-7), journal des éditions (Q-C1).

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
| Timeout TPE puis nouvel essai | selon Q-A1 | G-6 |
| PMV débité, écriture du règlement en échec | tout est annulé, PMV compris | G-5 |
| Rejeu après validation de la vente | « déjà enregistré », pas de 409 | G-1 |
| Synchronisation hors-ligne rejouée | selon Q-A2 | — |
| Taux légal modifié après la vente | le duplicata garde le taux gravé | G-7 |
| `TauxTva` corrigé par `PATCH` après la vente | idem : la valeur est gravée, pas la clé | G-7 |
| Correspondance comptable changée entre la vente et la génération des écritures | les écritures lisent le taux gravé : ticket = comptes | G-8 |
| Catégorie sans correspondance valide | selon Q-B3, refus par le créateur de la ligne, **avant** tout règlement | G-9 |
| Ligne de vente altérée en SQL après la vente | duplicata signalé en anomalie (si Q-B4-A) | G-10 |
| Vente en cours (non validée) | aucun ticket, quel que soit le canal | G-13 |
| Vente validée par l'écran, puis « Imprimer » | **original** : l'affichage ne comptait pas | G-14 |
| Vente directe (D44-bis) | pas d'impression automatique ; la première émission est l'original | G-14 |
| Vente gratuite | règle existante (`TicketPrintingPolicy`) inchangée | — |
| Vente annulée / remboursée partiellement | duplicata avec la mention de l'avoir | G-17 |
| Correction de règlement (D45) | duplicata d'origine + mention datée de la correction | G-17 |
| Vente ouverte à 23:30 UTC | date et jour du fuseau de l'établissement | G-12 |
| Libellé de 120 caractères, 40 lignes | aucune troncature | G-12 |
| Utilisateur d'un autre établissement, jeton partenaire, jeton de terminal | 404 | G-15 |

## Hors périmètre

- **ESC/POS** et pilotage d'une imprimante thermique, tiroir-caisse (le PDF s'imprime partout ; arbitrage de Maxime rappelé par #50).
- **Adaptateur TPE réel** (interroger le sort d'une transaction) : lot prestataire.
- ⚠ **`TpeMock` est câblé pour tous les environnements** (`services.yaml:138`, avant `when@test` l.429) : l'en-tête `X-Tpe-Simule` permet à n'importe quel appelant de forcer « accepté ». À fermer avant tout usage réel — lot TPE, signalé.
- **Renvoi du ticket par e-mail/SMS** : aucun expéditeur n'existe (`TicketProcessor`, mode `renvoyer` refusé).
- **Justificatif d'avoir imprimable** et ventilation TVA d'un remboursement partiel : selon Q-C4.
- **Invalidation des billets à l'annulation et au remboursement** : elle dépend d'`imprime` et le remboursement ne la fait jamais (`ContrePassationHandler.php:44-76`) ; ce lot n'y touche pas (G-14), à traiter avec le lot annulation (#93).
- « Imprimer le billet » (`Caisse.jsx:1958`, `window.print()`) : le billet n'est pas le ticket.
- `Etablissement::$langue` (périmètre Organisation) : français par défaut d'ici là.
- Réconciliation des taux **échéances ↔ ventes** pour un même produit (U-1) : signalée en Q-B1, arbitrage séparé.
- Reprise des ventes historiques : aucun taux rétroactif (D66-ter) ; leurs duplicatas disent « TVA non ventilée — vente antérieure au JJ/MM/AAAA ».
- Paiement en ligne (boutique, PSP) : il ne passe pas par `PaiementHandler`.
- `CardDebitFallback` (bascule carte → prélèvement) : non branché ; noté pour son lot qu'un **timeout n'est pas un refus**.
- **Validation rejouée** : un second « Valider » rend 409 sans effet (`ValiderVenteService`, « Seule une vente en cours peut être validée ») et l'unicité de séquence empêche un double scellement ; l'écran n'en fait pas encore un succès.
- La facture justificative désigne la ligne par le libellé **du catalogue** (`EmissionFactureJustificativeHandler.php:209`, `getLibelleRecherche()`), pas par le libellé gravé : écart signalé, non traité ici.
- Ticket imprimé par un **poste hors-ligne** : le front actuel n'a pas de mode hors-ligne ; la synchronisation remonte des ventes, pas des éditions.
- Certification NF525.

## Parcours utilisateur / UX

1. **Régler** — inchangé pour le caissier : moyen, montant, « Régler ». Si le serveur tarde, l'écran affiche « Paiement en cours de vérification » et relance seul avec la même clé ; « Régler » reste lié à ce paiement en attente ; il ne dit plus « réessayez ». Même chose sur l'écran de souscription. Après un timeout du terminal : selon Q-A1.
2. **Ticket** — à la validation, l'écran affiche le ticket (consultation, non comptée). « Imprimer le ticket » ouvre le PDF 80 mm : c'est l'original (selon Q-C3).
3. **Réimprimer** — depuis l'historique des ventes : « Réimprimer » produit le PDF ; le papier porte DUPLICATA, son numéro et la date du jour.
4. **Erreurs** — produit sans TVA réglée (Q-B3) : refusé **à l'ajout au panier**, avec le geste qui répare (« réglez la TVA de la catégorie X dans Compta › Correspondances ») ; vente non validée : « le ticket existe une fois la vente validée ».

## Contraintes & décisions techniques connues

- **Portage** : les 7 commits utiles de #50/#59 servent de base (aucun conflit hors migration) ; les écarts de G-1…G-18 s'ajoutent en commits séparés. Les 3 commits de fusion ne se reprennent pas.
- **Migrations à la main**, SQL demandé à Doctrine avant (`doctrine:schema:update --dump-sql`), absence de dérive vérifiée après ; jamais un `migrations:diff` brut.
- **NF525** :
  - `Paiement` est append-only (`InalterabiliteListener`) : la clé se pose à la création, jamais après.
  - Une édition comptée est un enregistrement **ajouté**, jamais une écriture sur la vente scellée. Elle ne change aucun total de clôture (ils se calculent sur les ventes, `DailyClosureHandler.php:181-187`).
  - `CHAMPS_VENTE_FIGES` est une **liste noire** (`InalterabiliteListener.php:33-45`) : tout champ absent reste modifiable sur une vente scellée. Toute nouvelle colonne fiscale de `Vente` (instantané vendeur, ventilation) y entre, avec un test.
  - Le journal des éditions (Q-C1-B) a **son propre stockage**, pas `nf525_operation_scellee` : `ScellementHandler::dernierMaillon()` et `chaine()` ne filtrent que par point de vente (l.37-66), les éditions s'intercaleraient dans la chaîne des ventes. Même algorithme (`HashChainSignataire`), unicité (vente, n° d'édition), entité déclarée append-only dans `InalterabiliteListener::estAppendOnly()` et cloisonnée dans `PerimetreVenteExtension::CHEMINS` (liste blanche).
- **Un seul calcul** (D63-bis) : la ventilation vit dans un service partagé ; les écritures l'appellent, elles ne la recopient pas.
- **Route** : sous `/api` (sinon le repli SPA de nginx sert du HTML avec un 200 ; les tests ne traversent pas nginx — preuve par un appel réel après déploiement).
- **D5** : identifiants anglais dans les fichiers ajoutés.
- **Revue** : `relecteur` et `security-reviewer` en mode adversarial à chaque étape (argent, NF525, cloisonnement).

## Points UNVERIFIED

**Bloquants pour CP-1** — ce sont les 10 questions ci-dessous. Quatre reposent sur des affirmations que le dépôt ne permet pas de vérifier et que seul Maxime tranche :

- [ ] La norme NF525 exige-t-elle un **numéro d'édition** sur un duplicata et sa **trace** ? Affirmé par l'auteur de #50/#59, non vérifiable dans le dépôt (le cahier, lui, exige « duplicata tracés »). → Q-C1.
- [ ] Les données signées d'un ticket doivent-elles porter la **ventilation par taux** ? Non vérifiable dans le dépôt. → Q-B4.
- [ ] L'interdiction d'imprimer systématiquement les tickets (loi AGEC) s'applique-t-elle à l'impression automatique au-dessus du seuil (CA-11) ? → Q-C3.
- [ ] Une facture EN 16931 / Factur-X peut-elle être pilotée par le TTC (HT déduit) sans enfreindre ses règles de calcul ? `infra/valider-facturx.sh` existe, rien n'a été mesuré. → Q-B2.

**Non bloquants pour CP-1, bloquants pour le plan (CP-2)** :

- [ ] `bin/verifier-derive-schema.sh` rend-il de nouveau un verdict ? #50 le disait mort sur une limite mémoire ; le script n'a pas changé depuis le 01/09. À mesurer avant d'écrire les migrations ; à défaut, preuve par exécution du SQL sur une copie de la sauvegarde, comme #270.
- [ ] Point de refus d'une ligne sans taux dans `SouscriptionAbonnementEnLigneHandler` (G-9).

## Questions CP-1 pour Maxime

Une réponse par question. Les recommandations sont argumentées par les mesures ci-dessus.

### Objet A — Le règlement

**Q-A1. Après un timeout du terminal, que se passe-t-il si le caissier relance le même règlement ?**
Un timeout n'est pas un refus : le terminal a pu accepter pendant qu'on cessait d'attendre. La clé ne protège rien ici, puisqu'aucun `Paiement` n'est écrit (CA-10).

- **A — Bloquer et faire constater.** Le même règlement ne repart pas au terminal tant que le caissier n'a pas dit ce que le terminal affiche : « accepté » (il saisit la référence du ticket CB, le règlement est créé et tracé : qui, quand) ou « non passé » (nouvel envoi permis). *Conséquence : jamais de double débit ; un geste de plus, sur un cas rare ; une déclaration « accepté » fausse se voit au rapprochement bancaire, et elle est signée.*
- **B — Renvoyer au terminal** (état de #50). *Conséquence : zéro geste ; double débit possible dès qu'un vrai TPE sera branché.*
- **C — Reporter au lot TPE réel**, dont l'adaptateur saura interroger la transaction. *Conséquence : rien à faire maintenant (seul `TpeMock` existe) ; le trou s'ouvre le jour du branchement si ce lot l'oublie.*

**Recommandation : A.** Le coût est minime aujourd'hui (aucun terminal réel) et le trou ne s'ouvre jamais.

**Q-A2. La synchronisation hors-ligne entre-t-elle dans ce lot ?**
Mesuré : elle rappelle `encaisser()` comme un paiement neuf, donc **re-sollicite le TPE** pour une carte déjà passée hors ligne ; ses opérations ne portent pas d'identifiant de règlement ; et un refus ou une validation en échec peut laisser un porte-monnaie débité sans trace, ou une vente réelle jamais scellée. Aucun poste hors-ligne n'existe aujourd'hui (le front n'a pas de mode hors-ligne) : le défaut est latent.

- **A — Non, lot dédié** avant le premier poste hors-ligne (issue à ouvrir) ; ce lot ne change rien à son comportement. *Conséquence : périmètre tenu ; la synchro reste fausse tant qu'elle n'a pas de client.*
- **B — Oui, en entier** : un règlement synchronisé est un **fait** (le TPE n'est jamais sollicité, sa référence vient de l'opération), clé = (opération, rang du règlement), débit PMV + règlement + validation en une unité, doublon = vente **validée** seulement, remontées sérialisées. *Conséquence : la synchro devient sûre ; le lot grossit nettement.*
- **C — Le minimum** : en synchro, le TPE n'est jamais sollicité et la clé est (opération, rang) ; le reste en lot dédié. *Conséquence : le pire (double carte) est fermé ; les autres défauts attendent.*

**Recommandation : A.** Le défaut n'a pas de client aujourd'hui ; le corriger à moitié donnerait l'impression qu'il est traité.

### Objet B — La TVA

**Q-B1. Quelle est la source du taux de TVA d'une ligne de vente ?**

- **A — La catégorie comptable** (correspondance M6 valide), gravée sur la ligne. C'est ce que font déjà les écritures et la facture justificative, et ce que dit la fiche produit depuis #270. *Conséquence : ticket = comptes ; 13 produits sur 21 couverts en préprod ; `Produit::$tauxTva` reste aux seules échéances (U-1) — pour les 2 produits mesurés, une formule vendue au comptoir dirait 20 % et ses échéances 10 %.*
- **B — Le taux du produit** (étendre U-1 aux ventes, choix de #50). *Conséquence : écritures et facture justificative changent de source (lot élargi à Compta et Facturation) ; 19 produits sur 21 sans taux, donc refus ou défaut ; les 2 produits renseignés passent de 20 % à 10 % en comptabilité.*
- **C — Un résolveur unique pour tout** (ventes, écritures, factures, échéances) : le produit s'il porte un taux, sinon la catégorie ; et la fiche produit refuse un taux qui contredit sa catégorie. *Conséquence : une seule règle partout ; les 2 produits doivent être corrigés avant de se vendre ; lot élargi à Offre, Compta et Facturation.*

**Recommandation : A pour ce lot**, et la contradiction échéances ↔ ventes (2 produits mesurés) en arbitrage séparé. A garde la **règle** des comptes et ne change que ce qu'ils **lisent** : le taux gravé sur la ligne au lieu de la correspondance du jour (G-8) ; B et C changent la règle elle-même, dans un lot de caisse.

**Q-B2. Sur une même vente, ticket, écritures et facture justificative doivent-ils concorder au centime — et qui cède ?**
Mesuré : les écritures extraient la TVA du TTC ligne par ligne ; la facture justificative recalcule à l'endroit (HT × taux) et peut s'écarter d'un centime de la vente (1,15 € à 10 % → 1,16 € facturés). Ce défaut existe déjà sur `main`.

- **A — Ticket = écritures dans ce lot** (TVA extraite du TTC ligne par ligne, sommée par taux) ; la facture justificative est corrigée dans un **lot Facturation dédié**, validateur Factur-X à l'appui. *Conséquence : le ticket est juste tout de suite ; d'ici le lot Facturation, la facture d'un ticket peut différer d'un centime, comme aujourd'hui.*
- **B — Ticket = écritures = facture dans ce lot** : la facture justificative passe au pilotage par le TTC (HT déduit). *Conséquence : concordance totale ; lot élargi à Facturation, et conformité EN 16931 / Factur-X à re-prouver (UNVERIFIED).*
- **C — Arrondi par taux sur le total TTC** (choix de #50). *Conséquence : la ventilation du ticket s'écarte d'un centime des écritures ; la facture reste à part.*

**Recommandation : A.** Le ticket ne doit jamais contredire les comptes ; la facture a son propre modèle et son propre contrôle de conformité, elle mérite son lot.

**Q-B3. Une ligne dont la catégorie n'a pas de correspondance valide (pas de taux) : que fait-on ?**

- **A — Refuser à la création de la ligne**, avant tout règlement, avec le geste qui répare. *Conséquence : jamais un ticket sans TVA pour une vente neuve ; en préprod, 2 produits sur 15 deviennent invendables jusqu'à la correspondance.*
- **B — Vendre, et le ticket imprime « VENTILATION INCOMPLÈTE »** (choix de #50). *Conséquence : aucune vente bloquée ; un ticket qui ne justifie pas la TVA, des écritures en anomalie, et la facture justificative qui dit 0 % pour la même vente.*
- **C — Vendre au taux par défaut de l'exploitant**, gravé et signalé. *Conséquence : aucune vente bloquée ; un taux que personne n'a choisi pour ce produit, sur un document opposable.*

**Recommandation : A** partout où l'on peut refuser (caisse, réservation, no-show, boutique) — B seulement pour ce qui ne peut pas refuser (vente hors-ligne déjà faite, ventes historiques). Refuser *après* paiement est exclu dans tous les cas.

**Q-B4. La ventilation TVA entre-t-elle dans l'empreinte NF525 de la vente ?**

- **A — Oui, à partir de ce lot** : le payload scellé ajoute, par ligne, le taux, et par taux, base HT, TVA et TTC ; le ticket confronte ce qu'il imprime au payload scellé. *Conséquence : un duplicata se prouve contre la chaîne, et une altération en base se voit ; les opérations anciennes restent vérifiables (l'empreinte se recalcule sur le payload stocké) ; le format du payload change à une date connue.*
- **B — Non** : la TVA reste hors de la chaîne Vente (elle est scellée côté M6, dans les écritures). *Conséquence : rien ne change dans la chaîne ; un ticket dont la TVA serait altérée en base ne romprait aucune empreinte de vente.*

**Recommandation : A.** C'est additif, sans effet sur l'existant, et c'est ce qui rend la TVA du ticket opposable plutôt que simplement affichée.

### Objet C — Le ticket

**Q-C1. Comment trace-t-on les éditions d'un ticket ?**

Le papier porte, dans tous les cas sauf D, « DUPLICATA n° k — édité le … ».

- **A — Une opération scellée dans la chaîne des ventes** du point de vente. *Conséquence : trace inaltérable ; mais une réimpression dispute le numéro de séquence à une validation de vente au même instant — l'unicité `uniq_op_pdv_sequence` (`OperationScellee.php:28`) fait échouer le perdant, qui peut être la vente.*
- **B — Un journal des éditions scellé à part** : son propre stockage et sa propre chaîne d'empreintes par point de vente, même algorithme (`HashChainSignataire`). *Conséquence : inaltérable et vérifiable, sans jamais gêner une validation ; une seconde chaîne à vérifier et à exporter.*
- **C — Un compteur sur la vente et une ligne au journal d'audit.** *Conséquence : plus léger ; trace modifiable en base, donc non opposable.*
- **D — La mention DUPLICATA seule, sans compteur** (état de #59). *Conséquence : réimpressions illimitées et indiscernables ; contraire au cahier (« duplicata tracés »).*

**Recommandation : B.** Le patron de D45 — on ajoute, on scelle, daté du geste — sans qu'une réimpression au back-office puisse faire échouer une vente au comptoir.

**Q-C2. Quelle identité de vendeur figure sur le ticket ?**

- **A — L'exploitant** (raison sociale, adresse, SIRET, n° de TVA intracommunautaire) et le nom du site, **figés à la validation** dans le payload scellé. *Conséquence : un duplicata reproduit l'identité du jour de la vente, même après un déménagement ou un changement de délégataire.*
- **B — Le même contenu, relu à l'édition.** *Conséquence : plus simple ; un duplicata tiré après un changement d'exploitant porte la nouvelle identité.*
- **C — Un en-tête libre, paramétré par établissement.** *Conséquence : souple ; rien ne garantit les mentions obligatoires.*

**Recommandation : A.** Un duplicata doit dire qui a vendu ce jour-là, pas qui gère le site aujourd'hui.

**Q-C3. Le ticket papier est-il réputé sorti à la validation ?**
Aujourd'hui, la validation au-dessus du seuil (0 € par défaut) et l'affichage du ticket à l'écran marquent la vente imprimée sans qu'aucune imprimante ne soit pilotée : le premier ticket réellement demandé sortirait marqué DUPLICATA, et le client recevrait le duplicata d'un original qu'il n'a jamais eu (fait constaté par le test de #50). Dans toutes les options, l'affichage à l'écran ne compte pas (G-14) et `imprime` garde sa pose actuelle.

- **A — À la demande seulement** : aucune édition n'est comptée à la validation ; le premier ticket demandé est l'original ; le seuil devient « proposer l'impression ». *Conséquence : aligné sur l'interdiction de l'impression systématique (loi AGEC, à confirmer par toi) ; modifie CA-11.*
- **B — Garder CA-11** : au-dessus du seuil, l'original est réputé émis à la validation. *Conséquence : statu quo ; le défaut ci-dessus demeure tant qu'aucune imprimante ne confirme.*
- **C — Compter à la validation seulement quand une imprimante confirme** (lot ESC/POS) ; d'ici là, comme A. *Conséquence : A aujourd'hui ; le comportement de CA-11 revient le jour où le matériel sait dire qu'il a imprimé.*

**Recommandation : C** — c'est-à-dire A maintenant. On n'écrit pas un fait qui n'a pas eu lieu (même raison que D44-bis pour la vente directe).

**Q-C4. Le client remboursé repart-il avec un justificatif d'avoir dans ce lot ?**

- **A — Non, lot suivant** : le duplicata de la vente d'origine mentionne l'avoir (n°, date, montant) ; le justificatif d'avoir vient avec le lot annulation (#93, brouillon). *Conséquence : périmètre tenu ; pendant ce temps, le client remboursé n'a pas de papier propre à l'avoir.*
- **B — Oui, sans ventilation TVA** : n° d'avoir, vente d'origine, montant, motif. *Conséquence : un papier tout de suite ; pas de TVA pour un remboursement partiel, faute de savoir le ventiler (un avoir ne porte qu'un montant).*
- **C — Oui, avec ventilation au prorata des lignes.** *Conséquence : complet ; règle de prorata fiscale à arrêter, et lot nettement plus gros.*

**Recommandation : A.** L'avoir ne porte pas de lignes ; le ventiler est un chantier à lui seul, et #93 travaille déjà sur l'annulation.

## Critères d'acceptation

- **G-1** : deux appels avec la même clé → un seul `Paiement`, le TPE simulé interrogé une fois (témoin : le second appel forcé en « refus » répond « accepté ») ; idem PMV (solde débité une fois) ; rejeu après validation → règlement d'origine rendu ; clé d'une autre vente → refus, aucun débit. Témoin de ce que la garde épargne : deux règlements identiques **avec deux clés** restent deux.
- **G-2** : chacun des deux écrans (`Caisse.jsx`, `SouscriptionAbonnement.jsx`) envoie la même clé au réessai, au nouveau clic pendant l'attente et après rechargement ; le message du coupe-circuit ne contient plus « réessayez ».
- **G-3** : deux appels **concurrents** (deux connexions) sur une vente à 45 € → un seul débit, le second reçoit « en cours », reste dû cohérent ; test réellement concurrent, pas séquentiel.
- **G-4** : même clé, montant différent → refus, aucun débit.
- **G-5** : échec forcé de l'écriture après un débit PMV → solde PMV intact ; no-show : échec forcé de la validation → aucun débit, facturation toujours « à facturer », relance → un seul débit ; aucun événement publié pour une transaction annulée.
- **G-6** : selon Q-A1 ; processus interrompu pendant l'appel au terminal → la tentative existe, le rejeu ne renvoie pas au terminal.
- **G-7** : produit à 10 %, taux changé à 20 % après la vente → duplicata à 10 % ; `PATCH` du `TauxTva` → duplicata inchangé.
- **G-8** : sur un jeu de ventes multi-taux, ventilation du ticket = écritures, au centime ; un oracle de test indépendant (D67) balaie montants × taux ; facture : selon Q-B2.
- **G-9** : selon Q-B3, un test par créateur de ligne ; jamais un groupe « 0 % » pour une ligne sans taux (témoin : une vraie ligne à 0 % hors champ reste ventilée à 0 %).
- **G-10** : selon Q-B4 ; vérification de chaîne verte avant et après le changement de format ; une ligne altérée en SQL → duplicata en anomalie.
- **G-11** : le filet de sortie de #50 reste vert sans être retouché pendant l'extraction.
- **G-12** : le HTML du rendu contient chaque mention ; la date est `Vente::$date` dans le fuseau de l'établissement (vente ouverte à 23:30 UTC) ; un ticket de 40 lignes à libellés longs n'est pas tronqué ; « certifié » absent ; aucun code d'accès.
- **G-13** : ticket demandé sur une vente en cours → refus, sur chaque canal.
- **G-14** : vente validée par l'écran (affichage compris), puis trois émissions → original, DUPLICATA n° 2, DUPLICATA n° 3 ; deux réimpressions simultanées → deux numéros distincts ; `imprime` posé ; annulation ensuite → billets invalidés.
- **G-15** : vente d'un autre établissement → 404 ; jeton partenaire, jeton de terminal → 404 ; sans `vente.lire` → 403 ; appel réel après déploiement → `application/pdf`, pas la coquille HTML ; aucun jeton dans une URL.
- **G-16** : plus aucun `window.print()` ni `POST /ticket` en mode `duplicata` derrière « Imprimer le ticket » et « Réimprimer ».
- **G-17** : vente annulée puis réimprimée → mention de l'avoir sous le document d'origine.
- **G-18** : `doctrine:migrations:status` sur une copie de la préprod liste les nouvelles migrations comme à exécuter ; `up`, `down`, `up` passent ; colonnes et tables présentes ensuite (preuve par `SELECT`, pas par le statut).

## Contradiction / Réponse

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
- **Mentionner l'avoir ou la correction D45 sur un duplicata** pourrait être lu comme une altération de l'original. → **retenue sous condition** : mentions séparées, sous le document (G-17) ; Maxime peut l'écarter au CP-1.
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
