---
titre: "Contrôle d'accès : portiques, badges et supervision"
categorie: acces-controle
publicCible: agent
portee: global
moduleLie: acces
statut: publie
resume: "Topologie des points d'accès, appairage d'un support, supervision des passages et FMI."
motsCles: [acces, controle, badge, portique, appairage, fmi, supervision]
---
## Topologie

Un établissement est découpé en **espaces** (zones), chacun équipé d'un ou plusieurs **contrôleurs**
(boîtiers physiques) pilotant des **équipements** (portiques, tourniquets, lecteurs). Chaque droit
d'accès vendu est **appairé** à un **support** (QR, carte RFID, wallet).

## Passage

À chaque présentation d'un support, l'équipement transmet un événement de **passage** : autorisé ou
refusé (droit expiré, déjà utilisé, support bloqué...). L'historique des passages est consultable pour
investiguer un litige.

## Perte ou vol d'un support

Une déclaration de perte/vol **bloque immédiatement** le support concerné et l'ajoute à la liste de
révocation diffusée aux contrôleurs — y compris ceux fonctionnant en mode dégradé (cf. article dédié).

## Supervision FMI

Le tableau de supervision « **File Moyenne d'Inoccupation** » (FMI) donne une vue en temps réel de la
fréquentation par espace, avec des seuils d'alerte configurables (mode blocage ou vigilance) pour
anticiper une saturation.
