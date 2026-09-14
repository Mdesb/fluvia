# Spec — Tunnel boutique en 3 étapes (#101, partie restructuration)

> **Écrite en autonomie (Jarvis), à valider avant implémentation.** La partie **dé-jargonnage** de #101 est livrée (PR #121). Reste la **restructuration 5→3**, qui touche le **consentement RGPD** et le **paiement** : zone sensible → **cycle SDD complet**, pas un refactor à l'aveugle. Cette spec la cadre ; elle n'est pas encore implémentée, faute de pouvoir exercer le tunnel en test headless (il faut un panier vivant + PSP).

## Aujourd'hui — 5 étapes

`frontend/src/public/pages/Tunnel.jsx` : `['Identification', 'Bénéficiaires', 'Consentement', 'Paiement', 'Confirmation']`. Chaque étape appelle un processor back dédié (l'étape Consentement appelle `boutique.consentement(panier, {rgpd})`).

Frictions relevées par l'audit (perspective simplificateur) : « Consentement » est un **écran** de jargon RGPD à lui seul ; l'identification bloque un achat invité.

## Cible — 3 étapes

1. **Vos billets et bénéficiaires** — noms/prénoms inline + (mineurs) date de naissance, **et** la case de consentement RGPD **sur le même écran** (une case, pas un écran).
2. **Paiement**.
3. **Confirmation**.

## ⚠ Invariant à préserver : le consentement reste explicite et enregistré

Fusionner ne veut PAS dire affaiblir. La case RGPD :
- reste **cochée explicitement** par l'utilisateur (jamais pré-cochée) ;
- déclenche toujours l'appel back `boutique.consentement(panier, {rgpd:true})` **avant** de passer au paiement ;
- l'étape « bénéficiaires » enchaîne donc **deux** appels back (enregistrer les bénéficiaires, puis le consentement) dans son `onOk`, avec la même gestion d'erreur qu'aujourd'hui.

C'est ce chaînage + la machine à états qui rendent le changement sensible, et pourquoi il se teste (bout-en-bout, sur un panier réel) avant d'être livré.

## Achat invité (identification optionnelle)

Rendre l'étape Identification **facultative** (achat invité) est ce qui fait passer de 4 à 3 étapes. C'est un changement d'**authentification** (le back doit accepter une commande sans compte, avec e-mail de contact) — à traiter comme un lot distinct, après la fusion du consentement.

## Découpage proposé

- **Lot A** — fusionner Consentement dans Bénéficiaires (5→4). Consentement préservé (case + appel back chaîné). Test bout-en-bout du tunnel.
- **Lot B** — achat invité : identification optionnelle (4→3). Touche l'auth back.

## À valider

- Le back accepte-t-il déjà un panier sans client identifié (achat invité), ou faut-il l'ouvrir (lot B) ?
- L'ordre des appels back dans l'étape fusionnée (bénéficiaires puis consentement) et le rollback si le second échoue.

---

*Spec de la restructuration du tunnel (#101). La dé-jargonnage est livrée (PR #121) ; cette restructuration, sensible (RGPD + paiement), attend une implémentation SDD testée bout-en-bout.*
