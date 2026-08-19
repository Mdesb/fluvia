# i18n & agent de traduction — v0

Service transverse du noyau. **Le code/DB est en anglais (D5)** ; l'utilisateur voit sa langue
(français par défaut) via des **clés de traduction**. Un **agent de traduction IA tourne au build**
et remplit les catalogues ; l'app ne fait **aucun appel IA en requête** — elle lit des catalogues
statiques (performance + invariant « dégradation propre »).

## Principe
1. **Clés stables, jamais de chaîne en dur.** Le code référence `finance.invoice.overdue_badge`,
   jamais « Facture en retard ». La source de référence est le catalogue **`en`** (anglais).
2. **Catalogues par langue** (`en`, `fr`, …) au format Symfony Translation (XLIFF/YAML), versionnés
   dans le dépôt. `fr` = langue par défaut d'affichage ; `en` = langue source/référence.
3. **Repli** : une clé manquante dans la locale cible retombe sur la source `en` — jamais la clé brute.

## L'agent de traduction (au build / en CI)
Étape de build dédiée, non dans le chemin de requête :
1. **Extraction** : collecte toutes les clés utilisées dans le code + leur texte source `en`.
2. **Diff** : pour chaque locale cible, repère les clés **manquantes** ou **`stale`** (source `en`
   modifiée depuis la dernière traduction).
3. **Traduction IA** (API Anthropic) : traduit uniquement ces clés, avec un **glossaire métier** par
   locale (termes figés : Invoice→Facture, Booking→Réservation, Ledger→Grand livre…) et le contexte
   de la clé. **Préserve** les variables ICU/placeholders (`{count}`, `%amount%`) sans les traduire.
4. **Écriture** avec un **statut** par entrée : `machine` (auto, à relire) · `reviewed` (validé humain)
   · `stale` (source changée).

## Relecture (workflow)
- Une entrée `reviewed` n'est **jamais** écrasée par l'agent.
- Quand le texte source `en` d'une clé change, ses traductions passent à `stale` → re-traduites au
  prochain build (statut `machine`), et re-signalées à la relecture.
- Un écran d'admin (ou un simple diff de catalogue en PR) permet de valider les `machine` → `reviewed`.

## Garde-fous
- **Déterminisme** : l'app ne traduit jamais à la volée ; elle sert les catalogues committés/relus.
- **Aucun secret / PII** envoyé à l'IA : seules les chaînes d'interface (clés + source `en` + glossaire).
- **CI** : build échoue si une clé utilisée n'a pas de source `en`, ou si un placeholder est perdu à
  la traduction (contrôle de cohérence des variables entre source et cible).

## Statut
◆ à implémenter — service `App\I18n` (résolution runtime des clés) + commande de build
`app:translate` (agent IA). Rang : service transverse du noyau, au même niveau que OCR / GED / Signature.
