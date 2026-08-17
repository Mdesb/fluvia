---
titre: "Comptabilité, régie de recettes, prélèvements SEPA et impayés"
categorie: compta-regie
publicCible: agent
portee: global
moduleLie: compta
statut: publie
resume: "Écritures comptables scellées, régie de recettes, remises SEPA et suivi des impayés."
motsCles: [comptabilite, regie, sepa, prelevement, impaye, cloture]
---
## Écritures comptables

Chaque vente/encaissement génère automatiquement une **écriture comptable** (via un mapping
comptable paramétrable) sur un **plan de comptes** dédié. Ces écritures sont **scellées** (NF525) dès
validation d'une **période comptable** : elles ne peuvent plus être modifiées, seule une écriture
d'extourne permet une correction.

## Régie de recettes

La **régie de recettes** rapproche les encaissements physiques (espèces, chèques) des versements
effectués en banque via des **bordereaux de versement**, avec un contrôle d'écart entre le théorique
et le constaté.

## Prélèvements SEPA

Pour les activités à abonnement (fitness, réservations récurrentes...), un **mandat SEPA** est
collecté auprès du client puis utilisé pour générer des **remises de prélèvement** groupées, envoyées
à la banque selon le calendrier des échéances.

## Impayés

Un rejet de prélèvement (fonds insuffisants, mandat invalide...) crée un **incident impayé** : selon
la politique de recouvrement de l'établissement, cela peut déclencher une relance automatique et,
au-delà d'un certain nombre d'incidents, une **coupure d'accès** jusqu'à régularisation.
