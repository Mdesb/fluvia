# Plan — lot 1 : annuler la vente en trois étapes

- **Spec source :** features/annulation-entree/specs/spec-annulation-entree.md (validée CP-1 le 13/09, amendée le 14/09)
- **Taille attendue :** back ≈ 8 lignes · front ≈ 85 · tests ≈ 45 · **aucun fichier nouveau, aucune migration, aucune entité**
- **CP-2 :** porte automatique (taille tenue, cohérence spec ↔ plan) + deux divergences tranchées par Maxime le 14/09 : lot 1 bis séparé ; message « Vente annulée. Avoir n° X émis. »

## Décisions techniques
- **D-A · Liste fermée des motifs = constante dans `AnnulerVenteProcessor`** (`app/src/Vente/State/AnnulerVenteProcessor.php:54`), pas dans le handler : `ContrePassationHandler::creerAvoir` sert aussi au remboursement avec motif libre. Le front duplique les trois libellés (3 lignes) ; le test CA-5 verrouille la liste côté back.
- **D-B · « Annulable » sans appel réseau.** Fin de vente : à `Caisse.jsx:884` `setFinVente({ info, ticket })` → ajouter `vente` ; droit via `aLeDroit(droits, 'vente.annuler')`. Historique : `detail.statut === 'validee' && aLeDroit(...) && detail.session ≙ sessionCourante` ; `HistoriqueVentesModal` reçoit une prop `sessionId`. Forme de `Vente.session` (IRI ou objet) UNVERIFIED : à lire au premier essai.
- **D-C · Escalade ≠ succès, déjà distinct.** Succès = 201 `{avoir, numero, statutVente…}`. Escalade = 403 `{decision:'escalade_requise', demandeEscalade…}` (`EscaladeRequiseExceptionListener`), déjà lu par le front pour « rembourser » (`HistoriqueVentesModal.jsx:30-31`, `client.js:102-115`). On copie ce branchement.
- **D-D · Limite d'office en régie → lot 1 bis.** Trois faits : une limite n'entraîne l'escalade que si `plafondMontant` est posé (recette : `0.00` + escalade au-delà) ; le résolveur ne voit une limite que par utilisateur ou rôle ; « en régie » = `TypeExploitant::RegieDirecte` porté par `ProfilExploitant`, posé après la création. Ancrage : persistance d'un `ProfilExploitant` RegieDirecte (≈ 15 lignes), lecture dédiée.
- **D-E · Message.** Aucune impression d'avoir dans le code ; le projet refuse d'annoncer un geste non exécuté. Afficher « Vente annulée. Avoir n° {numero} émis. »

## Étapes ordonnées
1. **Back — motif fermé + message** · `AnnulerVenteProcessor.php` : `private const MOTIFS = ['Erreur de saisie', 'Client parti', 'Doublon']` ; motif hors liste → 422 « Choisissez un motif : Erreur de saisie, Client parti ou Doublon. » ≈ 7 lignes.
2. **Back — description API** · `Vente.php:125` → « Annule une vente validée. Corps : { motif ∈ {…}, demandeEscalade? } ». 1 ligne.
3. **Front — client** · `client.js:594` : `annulerVente(venteId, motif, demandeEscalade)` → `body: { motif, ...(demandeEscalade && { demandeEscalade }) }`. 2 lignes. ⚠ `Caisse.jsx:903 abandonner()` appelle déjà `annulerVente(id)` sur une vente ouverte : comportement inchangé (422/409 affichés), à signaler.
4. **Front — fenêtre** · dans `HistoriqueVentesModal.jsx`, à côté de `FormulaireRemboursement`, une fonction `FormulaireAnnulation({ vente, onAnnulee, onFermer })` exportée : titre « Annuler la vente n° X », lignes + total, 3 radios (Erreur de saisie coché), « Oui, annuler », branche 403 → « Demandez au régisseur de valider. Rien n'est annulé pour l'instant. » ≈ 45 lignes. Deux usages justifient la fonction ; pas de fichier neuf.
5. **Front — historique** · condition `!annulable` sur Rembourser ; bouton « Annuler cette vente » sous les mêmes conditions ; prop `sessionId`. ≈ 15 lignes.
6. **Front — fin de vente** · `Caisse.jsx:884` ajouter `vente` ; `FinDeVente` reçoit `vente`, `droits`, `onAnnulee` ; bouton après « Afficher le ticket » ; résultat D-E. ≈ 20 lignes.

## Tests (`app/tests/Vente/Api/ContrePassationTest.php`)
- CA-1 : couvert par `testCa13AnnulationGenereAvoirEtInvalideSupport`.
- CA-2 : `testPlafondZeroEscaladeToujours` via `configurerLimiteAnnuler(plafond:'0.00', …)` ≈ 12 lignes.
- CA-3 : utilisateur affecté à l'établissement B → 404. ≈ 15 lignes.
- CA-4 : second POST /annuler → 409. ≈ 6 lignes.
- CA-5 : `{}` → 422 ; `{motif:'Bidon'}` → 422 + message. ≈ 12 lignes.
- CA-6, G-1..G-3 : front ; infra de test front UNVERIFIED → checklist manuelle en préprod.

## Ce qu'on ne construit pas
Enum PHP des motifs · endpoint exposant la liste · composant ou fichier nouveau · service « Annulabilité » · migration · seed régie (lot 1 bis) · impression d'un ticket d'avoir · texte libre · code de temps · modification du handler.

## Risques
Forme de `detail.session` (D-B) · message vs impression réelle (D-E, tranché) · lot 1 bis repousse la moitié de G-4 (tranché).
