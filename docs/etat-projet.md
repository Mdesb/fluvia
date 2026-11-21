# Tableau de bord — Fluvia

> **Vue régénérée par `/point-projet`** — ne pas éditer à la main, ne rien y stocker d'introuvable ailleurs.
> Dernière mise à jour : 2026-11-21 (par cycle complet — #370 alerte de scellement NF525)

## Où on en est
Les priorités fiscales NF525 sont traitées dans l'ordre #369 → #370 → #365 → #363. #369 (ordre des clés) et #370 (alerte de scellement) sont codés, testés et en PR brouillon ; #365 (PayFiP) est le prochain chantier.

## Dernières livraisons
| Date | Livré | Impact pour l'utilisateur |
|------|-------|---------------------------|
| 2026-11-21 | #370 alerte de scellement (PR Mdesb/fluvia#374, brouillon) | Un refus de scellement ne passe plus inaperçu : l'administrateur et l'éditeur sont prévenus, l'alerte porte une référence et se ferme seule |
| 2026-11 | #369 ordre des clés NF525 (PR Mdesb/fluvia#373, brouillon) | Le trousseau de scellement est contrôlé dans un ordre sûr |
| 2026-11 | #371 (PR Mdesb/fluvia#372) | Attend d'être passée « prête » |

## Reste à faire (par module)
- **Paiement** — #365 PayFiP *(prioritaire, suivant)*
- **RGPD** — #363 occultation dans le journal d'audit
- **Aide** — `vente/borne-libre-service.md` porte `publicCible: administrateur` (valeur refusée) : la suite PHP a 1 échec sur `main` (`ImporterAideCommandTest`), à corriger en voie légère
- **NF525** — Issues à ouvrir : signature des archives ZIP, unicité côté facturation ; clôture de #361

## ⏳ En attente de toi (décisions / validations)
- **#373** : mesure `check-keys` sur la préprod avant de passer la PR « prête ».
- **#374** : 10 points ouverts listés dans la PR (droit lecture qui écrit, archive ancienne qui rouvre l'alerte, Administrateur groupe, etc.).
- **#372** : la passer « prête ».
- Feu vert pour corriger le fichier d'aide de la borne.

## Santé du projet
| Indicateur | État | Dernier contrôle |
|------------|------|------------------|
| CI (garde-fous) | 🟢 sur les PR fusionnées depuis le 01/10 | 2026-10-07 |
| Suite PHP (hors CI) | 🟠 3636/3637 — 1 échec préexistant sur `main` (fichier d'aide) | 2026-11-21 |
| Cohérence (`/verifier-coherence`) | non relancé ce lot | — |
| Performance requêtes (`/audit-donnees`) | #370 : N+1 corrigé, index en place | 2026-11-21 |
