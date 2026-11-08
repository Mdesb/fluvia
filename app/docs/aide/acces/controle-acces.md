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

Le pilote des bornes ITBOX n'est pas encore branché : tant qu'il ne l'est pas, aucune porte physique
n'est commandée par Fluvia. Le contrôle se fait alors à la main (contrôle du billet, ouverture
manuelle tracée, comptage non nominatif) — voir l'article sur les bornes et le fonctionnement hors
connexion.

## Perte ou vol d'un support

Une déclaration de perte/vol **bloque immédiatement** le support concerné : le contrôle du billet et
toute validation en ligne le refusent aussitôt. Le blocage figure aussi dans la liste locale servie
aux bornes ; une borne qui travaille hors connexion ne l'applique qu'à sa **synchronisation
suivante** (cf. article sur les bornes et le fonctionnement hors connexion).

## Supervision FMI

Le tableau de supervision « **File Moyenne d'Inoccupation** » (FMI) donne une vue en temps réel de la
fréquentation par espace, avec des seuils d'alerte configurables (mode blocage ou vigilance) pour
anticiper une saturation.
