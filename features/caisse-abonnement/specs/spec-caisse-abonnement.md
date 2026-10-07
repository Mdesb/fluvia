# Spec — Caisse → abonnement (le comptoir crée l'adhésion)

**Statut :** validée (CP-1) — Maxime, 16/09/2026 (4 arbitrages tranchés, voir §Décisions CP-1)
**Auteur :** Jarvis (allaccess-06)
**Date :** 2026-09-16
**Lot :** priorité du chantier « abonnement cohérent » (arbitrage Maxime 16/09). Suit les lots 0-1 mergés (`features/abonnement/`).

## Contexte & problème

Faits **VERIFIED**, mesurés le 16/09 sur le code servi (`c7a87aef`) et la base `billetterie_preprod` :

- `app/src/Caisse` et `app/src/Vente` ne référencent **jamais** `Membership` (grep vide). Le moteur de vente comptoir (`Vente/Service/ValiderVenteService`) traite un produit à **facette Formule** comme un billet ponctuel scellé NF525, sans créer d'abonnement.
- Un seul créateur d'abonnement, `Membership\Service\SouscriptionAbonnementHandler::souscrire()` (l.139), deux appelants : le **guichet back-office** et la **boutique en ligne**. La boutique en ligne est le patron correct : `SouscriptionAbonnementEnLigneHandler` scelle **d'abord** la `Vente` (l.171, commit), **puis** appelle `souscrire()` (l.193) — deux phases, publication post-commit (D7-bis). Elle crée déjà un `Paiement` (l.143) **et** un échéancier, avec un 1er mois proratisable (`montantPremiereCentimes`).
- **Chiffré** : `PRD-GOLD01` (facette formule+accès) vendu **10× au comptoir → 0 abonnement**.

Ce lot **généralise le patron en ligne au comptoir**, sans inventer de variante. **Comptoir seulement.**

## Décisions CP-1 (16/09)

1. **Périmètre = le comptoir seul.** La trace de Vente pour la souscription au guichet back-office (ancien G-2) **sort** : elle dépend de D44-bis (« vendre sans caisse », pendant) et le problème chiffré est 100 % comptoir.
2. **Le canal porte le régime juridique.** Un abonnement créé au comptoir porte `canal=Guichet` et **n'ouvre pas** de rétractation (présentiel) ; l'en-ligne porte `canal=EnLigne` (vente à distance, rétractation 14 j). `SubscriberRegime` cesse de déduire la rétractation du seul profil payeur.
3. **Bénéficiaire = client par défaut**, lien discret « bénéficiaire différent » (cas famille, RG-M3-12/17) ; si le payeur est une **personne morale**, capter raison sociale + SIRET + adresse (D107).
4. **Paiement comptoir = le cœur câblé maintenant, la carte au lot PSP.** Ce lot : encaisser le 1er mois par les moyens câblés (**espèces** réel ; **CB-TPE** dès que le bouchon `EncaissementImmediatStubAdapter` est remplacé) + **mandat SEPA récurrent capté par lien** (signature tracée, D19). La **vraie CB comptoir** et l'**empreinte CB** (garantie impayé, inexistante aujourd'hui) partent au **lot prestataire carte**, comme D10 l'a fait pour l'en-ligne.

## Objectifs (Goals)

- **G-1** — Une **vente comptoir validée** contenant un produit à facette `Formule` crée l'abonnement (`Membership`) via `SouscriptionAbonnementHandler::souscrire()`, **appelé après le commit de la vente** (deux phases, patron en ligne l.171→193) — jamais imbriqué dans la transaction scellée NF525.
- **G-2** — Le **1er mois** (proratisé, `montantPremiereCentimes`) est **encaissé au comptoir** par un moyen câblé (espèces ; CB-TPE au branchement), tracé dans la `Vente`. Les échéances suivantes sont en SEPA.
- **G-3** — Le **mandat SEPA récurrent** est capté **par lien** (le client signe, consentement tracé, D19) — jamais un mandat `Actif` fabriqué depuis un IBAN tapé par le caissier. L'abonnement est créé « actif, mandat en attente » jusqu'à signature.
- **G-4** — Prix et périodicité **de la Formule** (canal `Guichet` réutilisé, aucun enum neuf), **jamais de montant libre** (01/09, 06/09).
- **G-5** — **Idempotence** (critique, `souscrire()` après une vente scellée D45) : re-traiter la vente / formule déjà active → **1 seul** `Membership` ; échec `souscrire()` post-scellement → vente valide + reprise, **0 avoir manuel**.
- **G-6** — Un **seul écran** caissier, valeurs pré-remplies, vocabulaire **Abonnement / Client / Prélèvement** (jamais « facette », « Membership », « échéancier »).
- **G-7** — L'abonnement **porte son canal** (`Guichet`) et le **régime le lit** (pas de rétractation en présentiel).

## Hors périmètre

- **Trace de Vente de la souscription guichet back-office** (ancien G-2) → lot D44-bis.
- **Vraie CB comptoir + empreinte CB** (garantie impayé) → **lot prestataire carte** (besoin PSP, cf. D10).
- Cycle de vie complet (résiliation, avoirs, pause) → lot 2 ; onglet Exploitation → lot 3 ; correctifs SEPA (cloisonnement, remise auto) → lots dédiés ; `App\Subscription` → à part. Renommage `Membership`.

## Parcours utilisateur (3 étapes, validé CP-1)

1. Le caissier ajoute le produit-formule au panier (geste normal) ; il **valide**.
2. Un volet pré-rempli s'ouvre : **Client** (= payeur ; lien discret « bénéficiaire différent » ; si personne morale, raison sociale + SIRET + adresse) ; **encaissement du 1er mois** (espèces / CB-TPE) au montant de la Formule.
3. L'abonnement est créé (**mandat en attente**) ; un **lien de signature du mandat** part au client (SMS/e-mail). Erreurs affichées **dans le volet** (déjà abonné, formule incompatible), disant quoi faire, la vente non perdue.

## Contraintes & décisions techniques connues

- Crochet à la **validation** (`Vente/Service/ValiderVenteService`), **après** commit/scellement, appelant `souscrire()` — ne jamais dupliquer sa logique, ni imbriquer la tokenisation IBAN/SEPA dans la transaction scellée NF525 (D45, `InalterabiliteListener`).
- `Vente`/`Caisse` = **zones sensibles** → revue security-reviewer obligatoire au build.
- **Coordination build** : `App\Vente` (« six endroits construisent une Vente », claude-G) et `App\Sepa`/`Membership` (mandat/échéancier — « domaine de la session SEPA, de concert »). La spec se fait avec ; le build se coordonne.
- Réutiliser `SubscriptionPriceResolver` sur le canal `Guichet` ; `GenerateurEcheancierHandler` pour l'échéancier (1er terme = mois encaissé).

## Critères d'acceptation

- **G-1** : vente comptoir validée avec `PRD-GOLD01` → **1 `Membership`** lié à cette `Vente`, créé après commit ; sans facette Formule → 0 (témoin négatif).
- **G-2** : le 1er mois figure comme `Paiement` tracé sur la `Vente` ; l'échéancier suivant est en SEPA.
- **G-3** : l'abonnement est créé sans mandat `Actif` ; un lien de signature est émis ; aucun prélèvement avant signature.
- **G-4** : montant = grille `Formule` (canal `Guichet`) ; montant libre refusé.
- **G-5** : re-traiter / formule déjà active → **1 seul** `Membership` ; échec post-scellement → vente valide, reprise, **0 avoir**.
- **G-6** : parcours **3 étapes**, un écran, zéro mot technique.
- **G-7** : abonnement comptoir → `canal=Guichet`, **pas** de rétractation.

## Contradiction / Réponse (challenge du 16/09 — contradicteur + finance + juridique + simplificateur)

- **G-2 d'origine (trace guichet) contredit D44-bis (pendant).** → **retenue** : sorti du périmètre (Décision CP-1 n°1).
- **« Même transaction » faux, casserait le scellement NF525.** → **retenue, corrigé** : G-1 explicitement après commit ; risque couvert par G-5.
- **Canal `caisse` inexistant → 422.** → **retenue** : réutilise `Guichet` (G-4).
- **IBAN tapé ≠ mandat signé (D19).** → **retenue** : mandat par lien, « en attente » (G-3, Décision CP-1 n°4).
- **Régime = canal, pas profil.** → **retenue** : G-7 (Décision CP-1 n°2).
- **Payeur personne morale → SIRET (D107).** → **retenue** : capté au volet (Décision CP-1 n°3).
- **Mandat au comptoir = 3→6 étapes (simplificateur).** → **retenue** : parcours 3 étapes, mandat différé par lien.
- **Préavis résiliation 30 j / L215-1 (juridique).** → **écartée de ce lot** : relève du lot 2 (cycle de vie) et de `spec-regime-par-profil` (#96).
