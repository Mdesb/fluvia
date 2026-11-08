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

## En-tête des factures : votre identité et votre logo

Chaque facture PDF porte en tête l'identité de la structure qui facture : **raison sociale**,
**adresse**, **SIRET** (ou, à défaut, SIREN) et **numéro de TVA intracommunautaire**, s'il est
renseigné. Ces informations se saisissent dans Facturation › Paramétrage, rubrique « Identité de facturation ».
Ce sont les mêmes que celles transmises dans la facture électronique (Factur-X).

Vous pouvez y ajouter un **logo** (image JPEG, 1 Mo au maximum). Il apparaît alors en haut à
gauche de chaque facture PDF.

Quand la vente a été encaissée à un guichet rattaché à une **régie de recettes**, la facture indique
aussi la **régie émettrice** et son acte constitutif. Une facture saisie directement, sans passage
en caisse, n'indique pas de régie.

**Une facture émise garde l'identité du jour de son émission.** Si vous changez ensuite votre
raison sociale, votre adresse, votre SIRET, votre numéro de TVA, votre logo ou le nom de votre
régie, les nouvelles valeurs s'appliquent aux factures émises après le changement. Une facture
déjà émise, réimprimée ou retéléchargée, montre toujours l'émetteur d'origine, dans le PDF comme
dans la facture électronique. Seules font exception les factures émises avant la mise en place de
cette conservation : leur identité d'origine n'a pas été gardée, et elles affichent l'identité actuelle.
