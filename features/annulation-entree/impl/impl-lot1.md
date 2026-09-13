# Suivi d'implémentation — annulation-entree · lot 1

- **Spec :** specs/spec-annulation-entree.md (validée CP-1 le 13/09/2026, amendée le 14/09)
- **Plan :** plans/plan-lot1.md · **Décisions :** D115 à D118
- **État :** construit, relu (relecteur + security-reviewer, mode adversarial), corrigé, testé — en attente de commit

## Étapes du plan
| # | Étape | État | Où |
|---|---|---|---|
| 1 | Back — motif fermé (3 valeurs, 422 sinon) | ✅ | `app/src/Vente/State/AnnulerVenteProcessor.php` |
| 2 | Back — description API `/annuler` | ✅ | `app/src/Vente/Entity/Vente.php` |
| 3 | Front — client `annulerVente(venteId, motif)` | ✅ | `frontend/src/api/client.js` |
| 4 | Front — `FormulaireAnnulation` (radios, escalade lisible) | ✅ | `frontend/src/components/HistoriqueVentesModal.jsx` |
| 5 | Front — historique : Annuler remplace Rembourser quand annulable | ✅ | idem |
| 6 | Front — fin de vente : bouton + « Vente annulée. Avoir n° X émis. » | ✅ | `frontend/src/pages/Caisse.jsx` |
| T | Tests CA-2, CA-3, CA-4, CA-5 | ✅ 7 tests, 74 assertions | `app/tests/Vente/Api/ContrePassationTest.php` |

## Écarts par rapport au plan (journal de session)
- **Bug latent trouvé par le test CA-3** : une vente hors périmètre faisait un **500** (`\assert($data instanceof Vente)`), pas un 404. Corrigé pour l'annulation seule (`NotFoundHttpException('Vente introuvable.')`). **Dix autres processeurs du module Vente ont le même assert** : Issue à ouvrir, hors lot.
- `ContrePassationTest` étend `AutorisationApiTestCase` (sur-ensemble de `VenteApiTestCase`) pour disposer du catalogue `OperationSensible` et des aides de rôle. Les trois tests existants passent inchangés.
- `Vente.session` est un objet embarqué (`id`, `numero`), pas une IRI.
- Front : 105 lignes contre 85 prévues (résultat de fin de vente en deux états). Le budget de diff en hook aurait signalé l'écart.
- `abandonner()` (vente en cours) appelle toujours l'annulation sans motif et reçoit 422, comme avant ; le message dit désormais « Choisissez un motif… » : pré-existant, à reprendre dans un correctif rapide séparé.

## Relecture adversariale (14/09)
- **security-reviewer** : prêt à committer, aucun bloquant ; deux limites à écrire dans la spec (faites, §8 bis) : la restriction à la session courante n'est qu'un affichage ; le jeton d'escalade n'est pas lié au motif (lot 1 bis).
- **relecteur** : à corriger d'abord, cinq majeurs, tous repris : `annulable` fuyait sans session ouverte ; le numéro d'avoir n'était pas affiché depuis l'historique ; l'état d'annulation survivait à un changement de vente ; CA-2 n'assertait pas la demande d'escalade ; G-1 au-dessus du seuil d'impression automatique (voir ci-dessous). Altitude : paramètre sans appelant, `?.` inutile, `json_encode` dans une assertion, paramètre de helper à un seul appel — supprimés.
- **G-1 au-dessus du seuil d'impression** : non couvert, acté dans la spec. `TicketVente` ne reçoit que le ticket construit (pas la vente), sert aussi aux duplicatas et est le bloc imprimé : y greffer le bouton touchait le papier. L'annulation passe par l'historique ; bandeau post-impression = correctif rapide séparé si le guichet le réclame.
- **Second passage de tests** après correctifs : 7 tests, 75 assertions, pile démontée.
- **Config des garde-fous** : le commit a révélé que `app/src/Vente/**` manquait aux zones sensibles de `kit-sdd.json` ; ajouté.

## Documentation
- Fiches support (`docs/support/caisse/`) : comment annuler une vente, messages, dépannage ; notes de version ; INDEX. Libellés vérifiés mot pour mot dans le front.
- Spec §8 bis : limites connues du lot 1.

## Reste à faire (hors lot 1)
- Lot 1 bis : limite d'office en régie (D118), motif lié au jeton d'escalade, périmètre `PropreSession`.
- Lot 2 : annuler une ligne. Lot 3 : rembourser une carte multi-entrées (D118 prorata).
- Issue : les dix `\assert` du module Vente (500 au lieu de 404).
- Correctif rapide : message d'`abandonner()`.

## Commit sous garde-fous (14/09)
- Kit : `git add -A` refusé ; commit refusé sur zone sensible sans revue ; accepté une fois `.kit-sdd/revues/test--methode.md` consigné (VERDICT FAVORABLE).
- Dépôt : le pre-commit a refusé une première fois — garde-fou n°27 « espacement en ligne », six marges littérales dans le JSX du lot. Converties vers l'échelle `var(--esp-…)`. Le kit ne remplace pas les garde-fous du projet : il les précède.
