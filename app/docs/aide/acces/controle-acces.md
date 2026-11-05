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

## Titre de groupe (clubs, écoles)

Un club ou une école peut entrer avec **un seul badge**. Au moment d'appairer la carte (onglet
**Badges**, « Appairer une carte »), renseignez **Personnes par passage** : 25 pour une classe de 25
élèves, par exemple. Ensuite, à chaque passage de ce badge :

- la porte reçoit l'ordre de laisser passer **25 personnes** (dès que le pilote de la borne est
  branché) ; une borne qui travaille hors connexion le sait aussi, par sa liste locale ;
- la **fréquentation** et la **jauge** comptent 25 entrées ; le journal montre le passage du badge et
  24 lignes « Membre d'un groupe » ;
- sur une **carte à entrées**, 25 entrées sont décomptées — si la carte n'en a plus assez, le passage
  est refusé ;
- en **mode blocage**, la jauge refuse le groupe si ses 25 personnes dépasseraient le seuil.

Laissez 1 pour un badge individuel. Un réappairage avec 1 ne change pas le nombre déjà enregistré sur
le droit.

## Perte ou vol d'un support

Une déclaration de perte/vol **bloque immédiatement** le support concerné : le contrôle du billet et
toute validation en ligne le refusent aussitôt. Le blocage figure aussi dans la liste locale servie
aux bornes ; une borne qui travaille hors connexion ne l'applique qu'à sa **synchronisation
suivante** (cf. article sur les bornes et le fonctionnement hors connexion).

## Supervision FMI

Le tableau de supervision « **File Moyenne d'Inoccupation** » (FMI) donne une vue en temps réel de la
fréquentation par espace, avec des seuils d'alerte configurables (mode blocage ou vigilance) pour
anticiper une saturation.
