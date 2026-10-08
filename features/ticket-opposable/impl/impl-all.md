# Suivi d'implémentation — ticket-opposable

**État :** plan — CP-1 validé le 07/10 ; plan écrit (44 étapes, 15 PR), fiches en cours ; P-1 à P-5 à trancher par Maxime <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/ticket-opposable (worktree `/home/debian/wt/ticket-opposable`)
**Spec :** features/ticket-opposable/specs/spec-ticket-opposable.md
**Plan :** features/ticket-opposable/plans/plan-ticket-opposable.md
**Inventaire :** features/ticket-opposable/refs/inventaire-pr50-pr59.md

## Checkpoints

- [x] **CP-1** — spec validée par Maxime le 07/10/2026 (10 questions, Q-C4 comprise ; règle du duplicata révisée)
- [ ] **CP-2** — plan
- [ ] **CP-3** — revue avant merge

## Étapes (reprises du plan)

Voir le plan, §2 (É1 à É44). Ordre de livraison : règlement d'abord (lots 1 à 4), puis ticket, TVA, avoirs, PDF, justificatif d'avoir.

## Journal de Session

- 07/10 — #50 et #59 fermées, branche neuve depuis `main` `4462a2d8`. Inventaire et spec écrits ; spec relue en contradiction (§Contradiction / Réponse de la spec). Branches d'origine conservées pour le portage.
- 07/10 — Contradiction indépendante (agent séparé, verdict NEEDS FIXES, 21 constats) intégrée : chaque constat revérifié, retenu, écarté ou porté en question. Q-A2 ajoutée (synchronisation hors-ligne), Q-B2 reformulée (la facture justificative recalcule à l'endroit), Q-C3 précisée (l'affichage à l'écran ne compte pas).

- 07/10 — CP-1 validé par Maxime (Q-C4 comprise, règle du duplicata révisée). Plan écrit par l'agent architecte, puis révisé pour le périmètre avoir. `bin/verifier-derive-schema.sh` mesuré cassé (limite mémoire) avec le jeton `ticket07` : preuve des migrations par exécution de leur SQL. Points P-1 à P-5 remontés à Maxime.

- 08/10 — Lot 4 (É10-É11, #298) : le no-show débite en une seule transaction, sous le verrou de sa facturation puis de la session système, avec une clé HMAC tirée de la facturation ; exonération et vente d'agent prennent le même verrou ; jamais plus remboursé que le total moins les avoirs émis. Aucune migration. Tests rouges puis verts (pile `lot4t07`), relecture adversariale en trois passes (APPROVE). Reste : le marquage SEPA (`prelevement_differe`) ne prend pas le verrou.

## Journal de Rétropropagation
