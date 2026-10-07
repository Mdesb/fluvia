# Spec — ticket-opposable

**Statut :** en revue — **CP-1 en attente de Maxime** (9 questions, §Questions CP-1) <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** session de maintenance du 07/10 (Claude)
**Date :** 2026-10-07
**Origine :** reprise propre de #50 (`feat/caisse-materiel`) et #59 (`feat/caisse-ticket-route`), décision de Maxime du 07/10 (QCM) : fermer les deux PR, repartir de `main`, reprendre leurs commits avec des migrations renumérotées, en cycle SDD complet. Inventaire morceau par morceau : `features/ticket-opposable/refs/inventaire-pr50-pr59.md`.
**Zone sensible :** argent (règlement), NF525 (chaîne scellée, duplicata), fiscal (TVA).

## Contexte & problème

Le besoin : un client qui paie au guichet repart avec un **ticket qu'on peut lui opposer** — juste sur la TVA, imprimable sur un rouleau de 80 mm, réimprimable à l'identique — et **ne paie jamais deux fois** parce qu'une réponse s'est perdue.

Faits **VERIFIED**, mesurés le 07/10 sur `origin/main` (`4462a2d8`) et sur la base `billetterie_preprod` (lecture seule, par `bin/console dbal:run-sql` dans `billetterie-preprod-php-1`).

### F-1 — Un règlement rejoué encaisse deux fois

- `PaiementHandler::encaisser()` (`app/src/Vente/Service/PaiementHandler.php:48`) ne cherche jamais un règlement déjà enregistré. Le débit du porte-monnaie virtuel (l.112) et l'ordre au TPE (l.126) partent avant toute écriture ; l'`id` fourni (l.153) n'est protégé que par la clé primaire, donc au `flush()`, après le débit.
- `PaiementProcessor` (`app/src/Vente/State/PaiementProcessor.php:35-36`) appelle `encaisser()` puis `flush()`, **sans transaction ni verrou** sur la vente : deux appels simultanés lisent le même reste dû.
- Le débit PMV est un `UPDATE` exécuté sur-le-champ (`app/src/Crm/Adapter/PorteMonnaieVirtuelAdapter.php:64-67`), hors de l'écriture du `Paiement` : si celle-ci échoue ensuite, le porte-monnaie reste débité sans règlement.
- **L'écran invite au double encaissement.** Il n'envoie aucune clé (`frontend/src/pages/Caisse.jsx:764`, corps `{ moyen, montant }`), abandonne au bout de 45 s (`frontend/src/api/client.js:657`) alors que nginx attend 60 s (`infra/nginx/billetterie-preprod.conf:142`), et affiche alors « Le serveur n'a pas répondu à temps (délai dépassé). **Réessayez** dans un instant. » (`client.js:251`). Un TPE qui accepte à la 50ᵉ seconde, un caissier qui réessaie : deux débits.
- Trois appelants passent par `encaisser()` : `PaiementProcessor:35`, `SynchroOperationsProcessor:154` (hors-ligne), `Reservation/Facturation/DebitPmvStrategie:76`.
- Aucun TPE réel n'est branché : `services.yaml:138` câble `TpeMock`. Un refus ou un timeout ne crée aucun `Paiement` (CA-10) mais est consigné par `CardRejectionRecorder` (PAY-3).

### F-2 — La vente ne connaît pas la TVA, et deux sources se contredisent

- `LigneVente` ne porte aucun taux ; aucune occurrence réelle de « tva » dans `app/src/Vente`.
- La TVA d'une vente n'existe qu'**après coup**, et à deux endroits qui lisent la même source : les écritures (`app/src/Compta/Regime/RegimeBase.php:31-71`) et la facture justificative (`app/src/Facturation/Service/EmissionFactureJustificativeHandler.php:196-236`). Source : la **correspondance comptable de la catégorie** du produit, lue le jour du calcul ; TVA extraite du TTC **ligne par ligne**. Sans correspondance, la facture justificative prend **« hors champ 0 % » en silence** (l.235).
- `Produit::$tauxTva` (`app/src/Offre/Entity/Produit.php:232`) sert aux **échéances d'abonnement** (U-1 tranché par Maxime le 08/09, `features/chaine-encaissement/specs/spec-chaine-encaissement.md` ; `InstallmentInvoicer::tauxApplicable()`), pas aux ventes. La fiche produit le dit depuis #270 : « Ce taux sert aux factures des échéances d'abonnement. Pour les ventes, la comptabilité prend le taux de la catégorie » (`frontend/src/components/ProduitFiche.jsx:1472`). Le cahier aussi : « TVA appliquée → M6 » (`specs/L2-vente/spec-vente.md:26`).
- **#50 gravait `Produit::$tauxTva` sur la ligne.** Mesure préprod : 2 produits sur 21 en portent un (10 %), et **pour ces deux-là la correspondance comptable dit 20 %**. Le ticket de #50 aurait affiché 10 % quand les comptes et la facture enregistrent 20 %. À l'inverse, 13 produits sur 21 ont une catégorie comptable correspondue.
- Un produit publié a forcément une catégorie comptable (`PublicationGuard.php:35`) et la caisse ne vend que du publié (#274). Restent deux trous : une catégorie **sans correspondance active** (2 produits sur 15 en préprod), et une ligne qui ne désigne **aucun produit** (réservation sans produit, `LineLabelStamper.php:30-31`) — les écritures la mettent alors en anomalie (`MappingComptableGuard.php:30-32`).
- `TauxTva::$taux` se modifie par `PATCH` (droit `compta.gerer`) ; la chaîne M6 a dû figer le taux dans son instantané pour cette raison (`ScellementEcritureHandler.php:48-51`). Une clé vers `TauxTva` ne suffit donc pas à figer un taux.

### F-3 — Le ticket n'est ni complet, ni compté, ni daté juste

- `TicketProcessor` construit le document en place (`app/src/Vente/State/TicketProcessor.php:125`) : lignes gravées (D61), mais ni TVA, ni vendeur, ni moyens de paiement. Il n'exige pas une vente validée.
- `Vente::$imprime` est un booléen : aucun compteur, aucune trace de réédition, alors que le cahier exige « renvoi et duplicata **tracés** » (`specs/L2-vente/spec-vente.md:84`).
- À la validation, au-dessus du seuil, la vente est **marquée** imprimée (`ValiderVenteService.php:201`) sans qu'aucune imprimante ne soit pilotée ; le seuil par défaut vaut 0,00 € (`TicketPrintingPolicy::impressionAutomatique()`), donc toute vente payante en session est marquée. Préprod : 44 des 50 ventes validées sont « imprimées ». Le bouton « Imprimer le ticket » est un `window.print()` de l'écran (`Caisse.jsx:2056`) : réimpressions illimitées, aucune trace.
- Le payload scellé d'une vente (`ValiderVenteService::payload()`, l.394-418) ne porte ni taux ni TVA. La vérification recalcule chaque empreinte sur le payload **stocké** (`HashChainSignataire.php:85`) : ajouter des champs aux opérations futures ne casse pas les anciennes.
- PHP tourne en UTC et les dates sont stockées en UTC : la dernière vente validée en préprod est du 20/09 à 23:02 UTC, soit **le 21/09 à 01:02 à Paris**. `Etablissement::$fuseauHoraire` existe (déjà lu par la clôture journalière).
- L'identité du vendeur existe : `ProfilExploitant` porte raison sociale, SIREN, SIRET, n° de TVA intracommunautaire, adresse (`app/src/Compta/Entity/ProfilExploitant.php:78-148`). `Etablissement` ne porte pas de langue.
- Outillage présent : `dompdf/dompdf` ^3.1 (`app/composer.json:16`), `App\Platform\Pdf\PoliceDeclaree`. nginx route `/api` vers le serveur (`billetterie-preprod.conf:135`).
- Un avoir ne porte qu'un montant (`app/src/Vente/Entity/Avoir.php`) ; l'annulation passe la vente en `annulee`, le remboursement (partiel possible) en `avoir_emis` (`ContrePassationHandler.php:39-73`). L'annulation n'invalide les billets émis que si `imprime` est vrai (l.44-50) : **tout document émis sans poser `imprime` laisse un billet valide sur une vente annulée.**

### F-4 — Les migrations de #50 ne peuvent pas partir telles quelles

- `app/migrations/Version20260908094500.php` existe déjà sur `main` (#47, `facturation_parametre.taux_tva_defaut_id`) et figure en préprod dans `doctrine_migration_versions`. La migration homonyme de #50 ne tournerait **jamais** — l'incident exact du garde-fou n°50.
- Ni `vente_paiement.cle_idempotence` ni `vente_ligne.taux_tva` n'existent en préprod.
- Fusion à blanc de #59 sur `main` (`git merge-tree`) : **un seul conflit**, cette migration. Les fichiers Vente touchés n'ont pas bougé sur `main` depuis la base de #50.

## Décisions déjà rendues, qui s'imposent

- **07/10, Maxime** : repartir proprement (ce lot), SDD complet.
- **D45** : on ne modifie jamais une opération scellée ; on ajoute, on scelle, daté du geste.
- **D61** : ce qui va sur le ticket est gravé sur la ligne au moment de la vente, jamais relu du catalogue.
- **D63-bis** : un seul calcul, plusieurs appelants — jamais une copie de règle.
- **D66-ter** : une migration ne fabrique pas de donnée fiscale (aucun taux rétroactif).
- **D107** : sans destinataire, c'est un ticket, pas une facture.
- **D44-bis** : une vente directe n'a ni session, ni espèces, ni impression automatique.
- **U-1 (08/09)** : le taux des **échéances d'abonnement** est celui du produit, et on refuse plutôt que d'inventer.

## Objectifs (Goals)

### A — Le règlement ne s'encaisse qu'une fois

- **G-1** — Un règlement porte une **clé d'idempotence** fournie par l'appelant ; l'`id` fourni vaut clé. Un appelant serveur tire sa clé de son fait générateur (la réservation pour `DebitPmvStrategie`, l'opération pour la synchronisation), jamais d'un tirage au hasard. Rejouer la même clé sur la même vente rend le règlement déjà enregistré **sans solliciter ni le TPE ni le PMV**, pour les trois appelants de `encaisser()`, y compris quand la vente a été validée entre-temps (réponse « déjà enregistré », pas un 409 « encaissement clos »).
- **G-2** — L'écran génère une clé **par intention de règlement** (un moyen, un montant, un clic sur « Régler ») et la réutilise pour tout réessai, y compris après son coupe-circuit. Son coupe-circuit ne se dit plus « réessayez » : il dit que le résultat est inconnu et rejoue avec la **même** clé.
- **G-3** — Deux demandes simultanées sur une même vente (double clic, deux onglets, réessai pendant l'attente du TPE) sont **sérialisées** : jamais deux sollicitations du TPE ou du PMV pour une même clé, et la somme encaissée ne dépasse jamais le dû, hors rendu espèces.
- **G-4** — Même clé, contenu différent (moyen ou montant) : **refus explicite**, aucun effet.
- **G-5** — Le débit PMV et l'écriture du règlement réussissent ou échouent **ensemble**.
- **G-6** — La **tentative** vers le terminal est écrite (et validée en base) **avant** l'appel, avec sa clé : si le processus meurt pendant l'attente, un rejeu sait qu'une demande est partie sans issue connue. Après un **timeout** — ou une tentative sans issue — : comportement tranché en **Q-A1**.

### B — La TVA est juste, une fois pour toutes

- **G-7** — Chaque ligne **grave à sa création** le taux appliqué — sa **valeur**, sa catégorie EN 16931 et son libellé, pas seulement une clé vers `TauxTva` (F-2) — depuis la source tranchée en **Q-B1**. Jamais relu ensuite. Gravé par le même mécanisme que le libellé (`LineLabelStamper`, six appelants construisent une ligne).
- **G-8** — La ventilation (par taux : base HT, TVA, TTC) sort d'**un seul calcul**, partagé par le ticket, la facture justificative et les écritures de vente : sur une même vente, **ticket = facture = écritures, au centime** (arrondi tranché en **Q-B2**).
- **G-9** — Une ligne sans taux résolu n'est **jamais** rangée à 0 % en silence ; conduite tranchée en **Q-B3**. Un chemin qui peut refuser refuse **avant tout encaissement** ; un chemin qui ne le peut pas (synchronisation hors-ligne : la vente a déjà eu lieu) grave « sans taux » et le ticket le dit.
- **G-10** — Scellement de la ventilation dans l'empreinte NF525 de la vente : tranché en **Q-B4**.

### C — Le ticket est complet, compté et fidèle

- **G-11** — **Un seul document** (`DocumentTicket`) lu par la réponse JSON et par le PDF ; son extraction de `TicketProcessor` est prouvée par l'égalité de la sortie (filet de #50).
- **G-12** — Rendu **PDF 80 mm**, sans troncature quel que soit le contenu (libellés longs, nombreuses lignes), portant : identité du vendeur (**Q-C2**), site, n° de ticket, date et heure **dans le fuseau de l'établissement**, lignes (libellé gravé, quantité, prix unitaire, remise, montant), total TTC, ventilation TVA, moyens de paiement et rendu, mention d'édition (**Q-C1**). **Jamais** « logiciel certifié NF525 » — nous ne le sommes pas, un test l'interdit. Libellé en français, à défaut la seule traduction disponible.
- **G-13** — **Pas de ticket pour une vente non validée** : refus, quel que soit le canal (JSON, PDF, impression).
- **G-14** — **Toute émission est comptée et tracée** selon **Q-C1** : la première est l'original, les suivantes portent « DUPLICATA n° k » et la date de la réédition. La mention se déduit **du nombre d'éditions comptées**, plus de `imprime`. Émettre est un acte : la route du PDF est une **écriture** (`POST`), jamais un `GET` qu'un rafraîchissement ou un préchargement rejouerait. `imprime` garde son seul rôle actuel — dire à l'annulation qu'il faut invalider les billets (F-3) — : toute émission le pose, rien ne le retire, et Q-C3 ne change pas ce que l'annulation lit.
- **G-15** — Route sous `/api` (nginx), droit `vente.lire` comme `POST /ventes/{id}/ticket`, cloisonnement identique à `GET /api/ventes/{id}` ; hors périmètre de l'utilisateur : **404**, jamais 403.
- **G-16** — Écran : « Imprimer le ticket » ouvre le **PDF serveur** (compté) au lieu de `window.print()` ; l'historique des ventes propose « Réimprimer (duplicata) ».
- **G-17** — Une vente annulée ou remboursée reste réimprimable (le document a existé) ; le duplicata porte, **sous** le document d'origine, les événements postérieurs — « ANNULÉE — avoir n° X du JJ/MM/AAAA », « REMBOURSÉE — avoir n° X, montant », « RÈGLEMENT CORRIGÉ le … (D45) » — sans jamais les fondre dans le document d'origine.

### D — Le portage

- **G-18** — Les deux migrations sont **renumérotées** après la dernière de `main` (`Version20261006105004` au 07/10), écrites à la main, `BINARY(16)` pour les uuid ; `Version20260908094500` (#47) n'est pas touchée.

## Cas limites

| Cas | Comportement attendu | G |
|---|---|---|
| TPE accepte, réponse perdue (nginx 60 s), l'écran réessaie | même clé → règlement existant rendu, carte non repassée | G-1, G-2 |
| L'écran abandonne à 45 s pendant que le TPE travaille | « résultat inconnu », rejeu automatique même clé ; le second appel attend le premier | G-2, G-3 |
| Double clic sur « Régler » | une seule intention, une seule clé, un seul débit | G-2, G-3 |
| Deux onglets ou deux postes sur la même vente | sérialisés ; le second voit le reste dû mis à jour | G-3 |
| Paiement scindé volontaire : 2 × 20 € par carte | deux intentions, deux clés, deux règlements | G-2 |
| Même clé, montant différent | refus, aucun effet | G-4 |
| Clé déjà utilisée sur une autre vente | indépendante : la portée est (vente, clé) | G-1 |
| Refus TPE puis nouvel essai | aucun argent n'a bougé : nouvel envoi au terminal permis | G-1 |
| Timeout TPE puis nouvel essai | selon Q-A1 | G-6 |
| PMV débité, écriture du règlement en échec | tout est annulé, PMV compris | G-5 |
| Rejeu après validation de la vente | « déjà enregistré », pas de 409 | G-1 |
| Synchronisation hors-ligne rejouée | l'`id` fourni vaut clé ; aucune double écriture | G-1 |
| Taux légal modifié après la vente | le duplicata garde le taux gravé | G-7 |
| `TauxTva` corrigé par `PATCH` après la vente | idem : la valeur est gravée, pas la clé | G-7 |
| Correspondance comptable changée entre la vente et la génération des écritures | les écritures lisent le taux gravé : ticket = comptes | G-8 |
| Catégorie sans correspondance active | selon Q-B3, refus **avant** tout règlement | G-9 |
| Vente en cours (non validée) | aucun ticket, quel que soit le canal | G-13 |
| Vente directe (D44-bis) | pas d'impression automatique ; la première émission est l'original | G-14 |
| Vente gratuite | règle existante (`TicketPrintingPolicy`) inchangée | — |
| Vente annulée / remboursée partiellement | duplicata avec la mention de l'avoir | G-17 |
| Correction de règlement (D45) | duplicata d'origine + mention datée de la correction | G-17 |
| Vente à 23:30 UTC | date et jour du fuseau de l'établissement | G-12 |
| Libellé de 120 caractères, 40 lignes | aucune troncature | G-12 |
| Utilisateur d'un autre établissement | 404 | G-15 |

## Hors périmètre

- **ESC/POS** et pilotage d'une imprimante thermique, tiroir-caisse (le PDF s'imprime partout ; arbitrage de Maxime rappelé par #50).
- **Adaptateur TPE réel** (interroger le sort d'une transaction) : lot prestataire.
- **Renvoi du ticket par e-mail/SMS** : aucun expéditeur n'existe (`TicketProcessor`, mode `renvoyer` refusé).
- **Justificatif d'avoir imprimable** et ventilation TVA d'un remboursement partiel : selon Q-C4.
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

1. **Régler** — inchangé pour le caissier : moyen, montant, « Régler ». Si le serveur tarde, l'écran affiche « Paiement en cours de vérification » et relance seul avec la même clé ; il ne dit plus « réessayez ». Après un timeout du terminal : selon Q-A1.
2. **Ticket** — à la fin de la vente, « Imprimer le ticket » ouvre le PDF 80 mm. Selon Q-C3, c'est l'original, ou un duplicata si l'original est réputé sorti à la validation.
3. **Réimprimer** — depuis l'historique des ventes : « Réimprimer (duplicata) » ; le papier porte DUPLICATA, son numéro et la date du jour.
4. **Erreurs** — produit sans TVA réglée (Q-B3) : refusé **à l'ajout au panier**, avec le geste qui répare (« réglez la TVA de la catégorie X dans Compta › Correspondances ») ; vente non validée : « le ticket existe une fois la vente validée ».

## Contraintes & décisions techniques connues

- **Portage** : les 7 commits utiles de #50/#59 servent de base (aucun conflit hors migration) ; les écarts de G-2…G-18 s'ajoutent en commits séparés. Les 3 commits de fusion ne se reprennent pas.
- **Migrations à la main**, SQL demandé à Doctrine avant (`doctrine:schema:update --dump-sql`), absence de dérive vérifiée après ; jamais un `migrations:diff` brut.
- **NF525** : `Paiement` est append-only (`InalterabiliteListener`) — la clé se pose à la création, jamais après. Une édition comptée est un enregistrement **ajouté**, jamais une écriture sur la vente scellée ; si un compteur vit sur `Vente`, il rejoint `imprime` parmi les seuls champs mobiles d'une vente scellée (`InalterabiliteListener::CHAMPS_VENTE_FIGES`). Une édition ne change aucun total de clôture (ils se calculent sur les ventes, `DailyClosureHandler.php:181-187`).
- **Un seul calcul** (D63-bis) : la ventilation vit dans un service partagé ; écritures et facture justificative l'appellent, elles ne la recopient pas.
- **Route** : sous `/api` (sinon le repli SPA de nginx sert du HTML avec un 200 ; les tests ne traversent pas nginx — preuve par un appel réel après déploiement).
- **D5** : identifiants anglais dans les fichiers ajoutés.
- **Revue** : `relecteur` et `security-reviewer` en mode adversarial à chaque étape (argent, NF525, cloisonnement).

## Points UNVERIFIED

**Bloquants pour CP-1** — ce sont les 9 questions ci-dessous. Trois reposent sur des affirmations que le dépôt ne permet pas de vérifier et que seul Maxime tranche :

- [ ] La norme NF525 exige-t-elle un **numéro d'édition** sur un duplicata et sa **trace** ? Affirmé par l'auteur de #50/#59, non vérifiable dans le dépôt (le cahier, lui, exige « duplicata tracés »). → Q-C1.
- [ ] Les données signées d'un ticket doivent-elles porter la **ventilation par taux** ? Non vérifiable dans le dépôt. → Q-B4.
- [ ] L'interdiction d'imprimer systématiquement les tickets (loi AGEC) s'applique-t-elle à l'impression automatique au-dessus du seuil (CA-11) ? → Q-C3.

**Non bloquant pour CP-1, bloquant pour le plan (CP-2)** :

- [ ] `bin/verifier-derive-schema.sh` rend-il de nouveau un verdict ? #50 le disait mort sur une limite mémoire ; le script n'a pas changé depuis le 01/09. À mesurer avant d'écrire les migrations ; à défaut, preuve par exécution du SQL sur une copie de la sauvegarde, comme #270.

## Questions CP-1 pour Maxime

Une réponse par question. Les recommandations sont argumentées par les mesures ci-dessus.

### Objet A — Le règlement

**Q-A1. Après un timeout du terminal, que se passe-t-il si le caissier relance le même règlement ?**
Un timeout n'est pas un refus : le terminal a pu accepter pendant qu'on cessait d'attendre. La clé ne protège rien ici, puisqu'aucun `Paiement` n'est écrit (CA-10).

- **A — Bloquer et faire constater.** Le même règlement ne repart pas au terminal tant que le caissier n'a pas dit ce que le terminal affiche : « accepté » (il saisit la référence du ticket CB, le règlement est créé et tracé : qui, quand) ou « non passé » (nouvel envoi permis). *Conséquence : jamais de double débit ; un geste de plus, sur un cas rare ; une déclaration « accepté » fausse se voit au rapprochement bancaire, et elle est signée.*
- **B — Renvoyer au terminal** (état de #50). *Conséquence : zéro geste ; double débit possible dès qu'un vrai TPE sera branché.*
- **C — Reporter au lot TPE réel**, dont l'adaptateur saura interroger la transaction. *Conséquence : rien à faire maintenant (seul `TpeMock` existe) ; le trou s'ouvre le jour du branchement si ce lot l'oublie.*

**Recommandation : A.** Le coût est minime aujourd'hui (aucun terminal réel) et le trou ne s'ouvre jamais.

### Objet B — La TVA

**Q-B1. Quelle est la source du taux de TVA d'une ligne de vente ?**

- **A — La catégorie comptable** (correspondance M6), gravée sur la ligne. C'est ce que font déjà les écritures et la facture justificative, et ce que dit la fiche produit depuis #270. *Conséquence : ticket = comptes ; 13 produits sur 21 couverts en préprod ; `Produit::$tauxTva` reste aux seules échéances (U-1) — pour les 2 produits mesurés, une formule vendue au comptoir dirait 20 % et ses échéances 10 %.*
- **B — Le taux du produit** (étendre U-1 aux ventes, choix de #50). *Conséquence : écritures et facture justificative changent de source (lot élargi à Compta et Facturation) ; 19 produits sur 21 sans taux, donc refus ou défaut ; les 2 produits renseignés passent de 20 % à 10 % en comptabilité.*
- **C — Un résolveur unique pour tout** (ventes, écritures, factures, échéances) : le produit s'il porte un taux, sinon la catégorie ; et la fiche produit refuse un taux qui contredit sa catégorie. *Conséquence : une seule règle partout ; les 2 produits doivent être corrigés avant de se vendre ; lot élargi à Offre, Compta et Facturation.*

**Recommandation : A pour ce lot**, et la contradiction échéances ↔ ventes (2 produits mesurés) en arbitrage séparé. A garde la **règle** des comptes et ne change que ce qu'ils **lisent** : le taux gravé sur la ligne au lieu de la correspondance du jour (G-8) ; B et C changent la règle elle-même, dans un lot de caisse.

**Q-B2. Comment arrondir la TVA ?**

- **A — Par ligne, puis sommée par taux** — comme les écritures (`RegimeBase.php:50`) et la facture justificative (l.204). *Conséquence : ticket = facture = comptes au centime ; la TVA d'un taux peut différer d'un centime de celle recalculée sur le total de ce taux.*
- **B — Par taux, sur le total TTC du taux** (choix de #50). *Conséquence : la ventilation du ticket est « exacte » par taux ; elle peut différer d'un centime des écritures et de la facture de la même vente — sauf à changer aussi l'arrondi de M6 et de Facturation.*

**Recommandation : A.** Un ticket qui contredit d'un centime la facture de la même vente est un ticket faux, et changer l'arrondi des comptes déborde ce lot.

**Q-B3. Une ligne dont la catégorie n'a pas de correspondance active (pas de taux) : que fait la caisse ?**

- **A — Refuser à l'ajout au panier**, avant tout règlement, avec le geste qui répare. *Conséquence : jamais un ticket sans TVA pour une vente neuve ; en préprod, 2 produits sur 15 deviennent invendables jusqu'à la correspondance.*
- **B — Vendre, et le ticket imprime « VENTILATION INCOMPLÈTE »** (choix de #50). *Conséquence : aucune vente bloquée ; un ticket qui ne justifie pas la TVA, des écritures en anomalie, et la facture justificative qui dit 0 % pour la même vente.*
- **C — Vendre au taux par défaut de l'exploitant**, gravé et signalé. *Conséquence : aucune vente bloquée ; un taux que personne n'a choisi pour ce produit, sur un document opposable.*

**Recommandation : A** au comptoir — B seulement pour ce qui ne peut pas refuser (vente hors-ligne déjà faite, ventes historiques). Refuser *après* paiement est exclu dans tous les cas.

**Q-B4. La ventilation TVA entre-t-elle dans l'empreinte NF525 de la vente ?**

- **A — Oui, à partir de ce lot** : le payload scellé ajoute, par ligne, le taux, et par taux, base HT, TVA et TTC. *Conséquence : un duplicata se prouve contre la chaîne ; les opérations anciennes restent vérifiables (l'empreinte se recalcule sur le payload stocké) ; le format du payload change à une date connue.*
- **B — Non** : la TVA reste hors de la chaîne Vente (elle est scellée côté M6, dans les écritures). *Conséquence : rien ne change dans la chaîne ; un ticket dont la TVA serait altérée ne romprait aucune empreinte de vente.*

**Recommandation : A.** C'est additif, sans effet sur l'existant, et c'est ce qui rend la TVA du ticket opposable plutôt que simplement affichée.

### Objet C — Le ticket

**Q-C1. Comment trace-t-on les éditions d'un ticket ?**

Le papier porte, dans tous les cas sauf D, « DUPLICATA n° k — édité le … ».

- **A — Une opération scellée dans la chaîne des ventes** du point de vente. *Conséquence : trace inaltérable ; mais une réimpression dispute le numéro de séquence à une validation de vente au même instant — l'unicité `uniq_op_pdv_sequence` (`OperationScellee.php:28`) fait échouer le perdant, qui peut être la vente.*
- **B — Un journal des éditions scellé à part** : sa propre chaîne d'empreintes par point de vente, même mécanisme (`HashChainSignataire`). *Conséquence : inaltérable et vérifiable, sans jamais gêner une validation ; une seconde chaîne à vérifier et à exporter.*
- **C — Un compteur sur la vente et une ligne au journal d'audit.** *Conséquence : plus léger ; trace modifiable en base, donc non opposable.*
- **D — La mention DUPLICATA seule, sans compteur** (état de #59). *Conséquence : réimpressions illimitées et indiscernables ; contraire au cahier (« duplicata tracés »).*

**Recommandation : B.** Le patron de D45 — on ajoute, on scelle, daté du geste — sans qu'une réimpression au back-office puisse faire échouer une vente au comptoir.

**Q-C2. Quelle identité de vendeur figure sur le ticket ?**

- **A — L'exploitant** (raison sociale, adresse, SIRET, n° de TVA intracommunautaire) et le nom du site, **figés à la validation** dans le payload scellé. *Conséquence : un duplicata reproduit l'identité du jour de la vente, même après un déménagement ou un changement de délégataire.*
- **B — Le même contenu, relu à l'édition.** *Conséquence : plus simple ; un duplicata tiré après un changement d'exploitant porte la nouvelle identité.*
- **C — Un en-tête libre, paramétré par établissement.** *Conséquence : souple ; rien ne garantit les mentions obligatoires.*

**Recommandation : A.** Un duplicata doit dire qui a vendu ce jour-là, pas qui gère le site aujourd'hui.

**Q-C3. Le ticket papier sort-il automatiquement à la validation ?**
Aujourd'hui, au-dessus du seuil (CA-11), la vente est *marquée* imprimée sans qu'aucune imprimante ne soit pilotée : le premier ticket réellement demandé sort alors marqué DUPLICATA, et le client reçoit le duplicata d'un original qu'il n'a jamais eu (fait constaté par le test de #50).

- **A — À la demande seulement** : aucune édition n'est comptée à la validation ; le premier ticket demandé est l'original ; le seuil devient « proposer l'impression ». *Conséquence : aligné sur l'interdiction de l'impression systématique (loi AGEC, à confirmer par toi) ; modifie CA-11 ; `imprime` reste posé à la validation comme aujourd'hui, pour que l'annulation continue d'invalider les billets (G-14).*
- **B — Garder CA-11** : au-dessus du seuil, l'original est réputé émis à la validation. *Conséquence : statu quo ; le défaut ci-dessus demeure tant qu'aucune imprimante ne confirme.*
- **C — Compter à la validation seulement quand une imprimante confirme** (lot ESC/POS) ; d'ici là, comme A. *Conséquence : A aujourd'hui, le comportement de CA-11 revient le jour où le matériel sait dire qu'il a imprimé.*

**Recommandation : C** — c'est-à-dire A maintenant. On n'écrit pas un fait qui n'a pas eu lieu (même raison que D44-bis pour la vente directe).

**Q-C4. Le client remboursé repart-il avec un justificatif d'avoir dans ce lot ?**

- **A — Non, lot suivant** : le duplicata de la vente d'origine mentionne l'avoir (n°, date, montant) ; le justificatif d'avoir vient avec le lot annulation (#93, brouillon). *Conséquence : périmètre tenu ; pendant ce temps, le client remboursé n'a pas de papier propre à l'avoir.*
- **B — Oui, sans ventilation TVA** : n° d'avoir, vente d'origine, montant, motif. *Conséquence : un papier tout de suite ; pas de TVA pour un remboursement partiel, faute de savoir le ventiler (un avoir ne porte qu'un montant).*
- **C — Oui, avec ventilation au prorata des lignes.** *Conséquence : complet ; règle de prorata fiscale à arrêter, et lot nettement plus gros.*

**Recommandation : A.** L'avoir ne porte pas de lignes ; le ventiler est un chantier à lui seul, et #93 travaille déjà sur l'annulation.

## Critères d'acceptation

- **G-1** : deux appels avec la même clé → un seul `Paiement`, le TPE simulé interrogé une fois (témoin : le second appel forcé en « refus » répond « accepté ») ; idem PMV (solde débité une fois) ; rejeu après validation → règlement d'origine rendu. Témoin de ce que la garde épargne : deux règlements identiques **sans** clé restent deux.
- **G-2** : l'écran envoie la même clé au réessai (test du client) ; le message du coupe-circuit ne contient plus « réessayez ».
- **G-3** : deux appels **concurrents** (deux connexions) sur une vente à 45 € → un seul débit, reste dû cohérent ; test réellement concurrent, pas séquentiel.
- **G-4** : même clé, montant différent → refus, aucun débit.
- **G-5** : échec forcé de l'écriture après un débit PMV → solde PMV intact.
- **G-6** : selon Q-A1.
- **G-7** : produit à 10 %, taux changé à 20 % après la vente → duplicata à 10 % ; `PATCH` du `TauxTva` → duplicata inchangé.
- **G-8** : sur un jeu de ventes multi-taux, ventilation du ticket = lignes de la facture justificative = écritures, au centime ; un oracle de test indépendant (D67) balaie montants × taux.
- **G-9** : selon Q-B3 ; jamais un groupe « 0 % » pour une ligne sans taux (témoin : une vraie ligne à 0 % hors champ reste ventilée à 0 %).
- **G-10** : selon Q-B4 ; vérification de chaîne verte avant et après le changement de format.
- **G-11** : le filet de sortie de #50 reste vert sans être retouché pendant l'extraction.
- **G-12** : le HTML du rendu contient chaque mention ; la date sort dans le fuseau de l'établissement (vente à 23:30 UTC) ; un ticket de 40 lignes à libellés longs n'est pas tronqué ; « certifié » absent.
- **G-13** : ticket demandé sur une vente en cours → refus, sur chaque canal.
- **G-14** : trois émissions → original, DUPLICATA n° 2, DUPLICATA n° 3 ; `imprime` posé dès la première ; annulation ensuite → billets invalidés.
- **G-15** : vente d'un autre établissement → 404 ; sans `vente.lire` → 403 ; appel réel après déploiement → `application/pdf`, pas la coquille HTML.
- **G-16** : plus aucun `window.print()` sur le ticket.
- **G-17** : vente annulée puis réimprimée → mention de l'avoir sous le document d'origine.
- **G-18** : `doctrine:migrations:status` sur une copie de la préprod liste les deux nouvelles migrations comme à exécuter ; `up`, `down`, `up` passent ; colonnes présentes ensuite (preuve par `SELECT`, pas par le statut).

## Contradiction / Réponse (relecture adversariale du 07/10)

Trois axes : ce qui casse la chaîne NF525, ce qui encaisse deux fois, ce qui rend un ticket faux.

- **#50 gravait `Produit::$tauxTva` : le ticket aurait contredit les comptes** (10 % contre 20 % sur les 2 produits mesurés). → **retenue** : source en Q-B1, ticket = facture = écritures (G-8).
- **Arrondi par taux ≠ arrondi par ligne des comptes** : un centime d'écart sur la même vente. → **retenue** : Q-B2, recommandation par ligne.
- **La garde de #50 ne sérialise pas deux appels simultanés** : les deux passent la recherche, les deux débitent, le second échoue au `flush()` après débit. → **retenue** : G-3.
- **Même clé, autre montant : #50 rendait l'ancien règlement en silence.** → **retenue** : G-4.
- **PMV débité hors transaction** : un échec d'écriture laisse le porte-monnaie débité. → **retenue** : G-5.
- **Le front n'envoie aucune clé et dit « Réessayez » à 45 s** : la garde serveur seule ne protège rien. → **retenue** : G-2.
- **Un processus qui meurt pendant l'appel au terminal ne laisse aucune trace** : le rejeu repart au terminal. → **retenue** : tentative écrite avant l'appel (G-6), Q-A1.
- **`DebitPmvStrategie` appelle `encaisser()` sans clé.** → **retenue** : clé tirée du fait générateur (G-1).
- **Le `GET` de #59 rend des originaux à l'infini, sans trace, et ne pose pas `imprime`** : une annulation laisserait les billets valides. → **retenue** : émission en `POST`, comptée, qui pose `imprime` (G-14).
- **Une réimpression scellée dans la chaîne des ventes dispute la séquence à une validation** (`uniq_op_pdv_sequence`) : une réimpression au back-office peut faire échouer une vente au comptoir. → **retenue** : Q-C1, recommandation d'un journal des éditions scellé à part.
- **Ne plus compter d'édition à la validation (Q-C3) ne doit pas affaiblir l'annulation**, qui lit `imprime` pour invalider les billets. → **retenue** : `imprime` garde son rôle et sa pose actuelle ; DUPLICATA se déduit du compteur (G-14).
- **Ticket d'une vente en cours** possible par `POST /ticket` comme par la route de #59. → **retenue** : G-13.
- **Date imprimée en UTC** : une vente de 01:02 à Paris sort datée de la veille. → **retenue** : fuseau de l'établissement (G-12).
- **Hauteur du PDF estimée à une ligne par libellé** : un libellé long passe à la ligne et le ticket est tronqué. → **retenue** : G-12, critère « 40 lignes à libellés longs ».
- **Une clé vers `TauxTva` ne fige rien** : `taux` se corrige par `PATCH`. → **retenue** : la valeur est gravée (G-7).
- **Mentionner l'avoir ou la correction D45 sur un duplicata** pourrait être lu comme une altération de l'original. → **retenue sous condition** : mentions séparées, sous le document, jamais fondues (G-17) ; Maxime peut l'écarter au CP-1.
- **Ventes historiques** : ni taux gravé ni vendeur figé. → **écartée de ce lot** : aucun taux rétroactif (D66-ter), duplicata qui le dit.
- **Validation rejouée → 409.** → **écartée de ce lot** : sans effet sur l'argent ni sur la chaîne (unicité de séquence), hors périmètre.
