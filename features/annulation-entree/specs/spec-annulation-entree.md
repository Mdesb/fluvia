# Spec — Annuler une entrée vendue par erreur (`annulation-entree`)

- **Module :** Vente / Caisse · **Statut :** **validée en CP-1 le 13/09/2026** (Maxime, QCM), amendée le 14/09 après plan (lot 1 bis, message)
- **Demande d'origine :** « Un régisseur doit pouvoir annuler une entrée vendue par erreur dans les cinq minutes. » + « quand il y a plusieurs produits dans une vente, on devrait pouvoir annuler une seule ligne » + « rembourser 6 entrées d'une carte de 10 »
- **Décisions :** D116 (trois gestes, trois lots) · D117 (motifs fermés) · D118 (régisseur par défaut en régie) · D119 (prorata du prix payé, carte close)
- **Livraison :** lot 1 (annuler la vente en trois étapes) · lot 1 bis (limite d'office en régie) · lot 2 (annuler une ligne) · lot 3 (rembourser une carte). Chaque lot : plan, code, relecture adversariale, fiche support, commit sous garde-fous.

## 0. Ce qui existe déjà (chercheur, VERIFIED)
- `POST /ventes/{id}/annuler` (`vente.annuler`) annule une **vente entière validée** par contre-passation : un `Avoir` scellé, motif obligatoire (422 sinon), vente → `Annulee`, supports d'accès invalidés. Tests : `ContrePassationTest`.
- `ServiceAutorisation` est appelé avant l'annulation : une `LimiteAutorisation` sur `vente.annuler` (plafond `0.00`, escalade au-delà) envoie l'opérateur vers une `DemandeEscalade` (réponse 403 `escalade_requise`, déjà lue par le front pour « rembourser »).
- Aucun bouton Annuler après une vente ; l'historique montre « Rembourser » ; `annulerVente` envoie un corps vide. Aucune impression d'avoir n'existe.
- Remboursement partiel par **montant** existe ; il ne touche pas `DroitAcces.creditRestant`.

## 1. Objectif
Au guichet, défaire une vente faite par erreur en trois gestes, sans jargon, sans altérer la chaîne scellée, avec la validation du régisseur là où l'établissement est en régie.

## 2. Lot 1 — annuler la vente en trois étapes
- G-1 · Sur l'écran de fin de vente (ventes sous le seuil d'impression automatique), à côté de « Afficher le ticket », un bouton **Annuler cette vente** ; le même dans l'historique sur une vente validée de la session en cours (droit `vente.annuler`). « Rembourser » masqué quand la vente est annulable. **Acté le 14/09 après relecture** : au-dessus du seuil, le ticket s'imprime sans écran de fin de vente et le bouton n'y est pas (le bloc ticket est le document imprimé) ; l'annulation passe alors par l'historique. Un bouton dans le bandeau post-impression est un correctif rapide séparé si le guichet le réclame.
- G-2 · Une fenêtre : « Annuler la vente n° X — <lignes et total> », motif en trois boutons radio (**Erreur de saisie** présélectionné, Client parti, Doublon), **aucun texte libre** (D117). Bouton « Oui, annuler ».
- G-3 · Résultat : « **Vente annulée. Avoir n° X émis.** » (pas de mention d'impression tant qu'aucune impression n'existe). Si une limite s'applique : « Demandez au régisseur de valider. Rien n'est annulé pour l'instant. »
- G-5 · Corrections connexes : description API de `/annuler`, motif transmis par le front, 422 en français de guichet, motif hors liste refusé.
- Taille : back ≈ 8 lignes, front ≈ 85, tests ≈ 45. Aucune entité, migration ni fichier nouveau.

## 3. Lot 1 bis — validation du régisseur par défaut en régie (D118)
À la persistance d'un profil exploitant `RegieDirecte`, poser si absente une `LimiteAutorisation` sur `vente.annuler` (plafond `0.00`, escalade au-delà) visant le rôle caissier. L'établissement peut desserrer. Sa propre lecture du code d'onboarding avant plan.

## 4. Lot 2 — annuler une seule ligne
Avoir portant la ligne, référencé à la vente, daté du geste, grand total non diminué ; seul le `BilletSupport` de la ligne est invalidé ; la vente reste `Validee` avec avoir partiel ; l'annulation entière ensuite porte sur le reste. État à formaliser dans `StatutVente` ou par le cumul des avoirs : à trancher au plan.

## 5. Lot 3 — rembourser une carte multi-entrées (D119)
Prorata du prix payé (entrées non consommées / entrées totales × prix payé), `creditRestant` remis à zéro dans le même geste scellé, support invalidé. Test de référence : carte de 10 à 40 €, 4 consommées → avoir 24,00 €, crédit 0.

## 6. Acteurs & droits
| Acteur | Peut | Droit |
|---|---|---|
| Opérateur de caisse | annuler une vente validée de sa session | `vente.annuler` (existant) |
| Régisseur | valider une demande d'annulation ; annuler hors de sa session | `vente.annuler` + `LimiteAutorisation` (existant) |
Pas de nouvelle permission (D54 non applicable). Cloisonnement : D3/D8, vente résolue puis contrôlée (garde-fou n°1).

## 7. Critères d'acceptation du lot 1
- CA-1 · Vente validée dans ma session, aucune limite : motif par défaut → Avoir scellé, `Annulee`, support invalidé, message avec numéro d'avoir.
- CA-2 · Limite plafond `0.00` sur `vente.annuler` : → 403 `escalade_requise`, `DemandeEscalade` créée, rien annulé ; le régisseur valide → CA-1.
- CA-3 · Vente d'un autre établissement → 404. CA-4 · Déjà annulée → bouton absent ; API 409.
- CA-5 · `{}` → 422 ; `{motif:"Bidon"}` → 422 avec message listant les trois motifs.
- CA-6 · « Rembourser » masqué sur une vente annulable, visible sinon.

## 8. Hypothèses restantes
- ⚠ UNVERIFIED · Forme de `Vente.session` dans `vente:read` (IRI ou objet) : à lire au premier essai front.
- ⚠ UNVERIFIED · Annulation après clôture journalière : non modifié, non lu ; à documenter tel quel dans la fiche support.

## 8 bis. Limites connues du lot 1 (relecture sécurité du 14/09)
- **La restriction « vente de la session en cours » est un affichage.** Le serveur n'impose que le droit `vente.annuler`, le périmètre d'établissement et, s'il existe, une `LimiteAutorisation`. Un caissier habilité peut annuler par l'API une vente d'une autre session du même établissement. Pour l'imposer côté serveur : une limite de périmètre `PropreSession` sur `vente.annuler`, à poser avec le lot 1 bis (D118).
- **Le jeton d'escalade n'est pas lié au motif.** Le régisseur valide sans connaître le motif, et le rejeu peut en porter un autre (D39 « rejouer la règle entière »). Portée faible (trois valeurs non nominatives) ; correctif au lot 1 bis : `motif` dans `RequeteAutorisation` et `DemandeEscalade`, comparé au rejeu.
- **Le remboursement garde un motif libre** (route `/rembourser`, non touchée) : D117 ne porte que sur l'annulation ; un texte nominatif peut entrer dans la chaîne scellée par cette route. Décision à prendre au lot 3.
- **Bug latent hors périmètre** : dix processeurs du module Vente gardent un `\assert($data instanceof Vente)` qui rend 500 au lieu de 404 sur une vente hors périmètre. Corrigé ici pour l'annulation seule ; Issue à ouvrir pour les dix autres.

## 9. Contradiction (faite avant CP-1)
- **Contradicteur** : « l'annulation par ligne n'est pas dans la demande, écriture scellée nouvelle, état non défini ; la fenêtre codée exige une migration et n'existe pas par défaut » — Réponse : ligne **remise par Maxime** (demande explicite), état à formaliser au plan du lot 2 ; fenêtre codée **retenue comme objection** : plus de constante de temps, autorisation graduée existante.
- **Perspective juridique** : « motif libre = donnée personnelle ineffaçable ; annulation par mandataire seul engage le régisseur » — Réponse : retenue, tranchée par Maxime (droit) → D117, D118.
- **Simplificateur** : « sept étapes, geste caché, deux boutons voisins, jargon » — Réponse : retenue → G-1, G-2, G-3.
- Compétences déclarées : Maxime tranche le juridique et le financier ; aucun renvoi vers un professionnel.
