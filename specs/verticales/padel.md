# Paquet `padel`

> Périmètre `claude-I`. Rédigé au [format proposé](paquet.md). Statut : **rédigé, non installable**.

## Composition

```yaml
id: padel
version: 1.0.0

activities:
  - type: resource_booking            # terrains, plages pleine/creuse, durées autorisées
    unit: court
  - type: equipment_rental            # raquettes et balles, avec caution
    subject: gear

modules: [padel-tournament, court-lighting]

vocabulary:
  resource: Terrain
  resource_unit: Terrain
  slot: Réservation de terrain
  booking: Partie
  participant: Joueur
  staff: Juge-arbitre
  group: Poule
  entry: Accès terrain
  rental: Location de matériel
  deposit: Caution matériel
  multi_entry_card: Carte de parties
```

**Deux modules de code conservés**, et les deux se justifient au test de D15 — retirer le paquet
laisserait du code sans emploi :

- `padel-tournament` : poules, matchs, classement, niveau de joueur avec historique. Une inscription à
  un tournoi ressemble à une réservation, mais la **génération d'un tableau et sa progression** n'est
  aucune des neuf briques. C'est une règle réellement nouvelle.
- `court-lighting` : relais d'éclairage par terrain, commande, mode de repli, répartition du surcoût.
  Du pilotage de matériel, avec un mode dégradé — rien à voir avec une activité.

## Données de départ

```yaml
seed:
  - xid: padel.settings
    entity: Padel\ParametragePadel
    noupdate: true
    data:
      echelleNiveauMin: 1
      echelleNiveauMax: 10
      modeRepartitionSurcout: egale
      toleranceEntreeBadgeMinutes: 15
      majorationCoachMontant: "0.00"
      produitTerrainRef: { ref: padel.product.court }
      typeTarifMembreRef: { ref: padel.tariff.member }
      typeTarifNonMembreRef: { ref: padel.tariff.guest }

  - xid: padel.timeslot.peak
    entity: Padel\PlageHoraire
    noupdate: true
    data: { libelle: pleine, joursApplicables: [1,2,3,4,5] }
  - xid: padel.timeslot.offpeak
    entity: Padel\PlageHoraire
    noupdate: true
    data: { libelle: creuse, joursApplicables: [6,7] }

  - xid: padel.court.1                       # bloc généré de 1 à 4
    entity: Padel\TerrainPadel
    noupdate: true
    data:
      ressource: { ref: padel.resource.court.1 }   # Reservation\Ressource, créée par le même paquet
      type: indoor
      dureesAutoriseesMinutes: [60, 90]
      actif: true

  - xid: padel.price.court1.peak.member
    entity: Padel\GrilleTarifaireTerrain
    noupdate: true
    data:
      terrain: { ref: padel.court.1 }
      plageHoraire: { ref: padel.timeslot.peak }
      statutJoueur: membre
      dureeMinutes: 60
      prix: "24.00"
```

## Ce que ce paquet prouve, et qui n'était pas visible sur la piscine

**`ParametragePadel` porte trois références en `Uuid` nu** — `produitTerrainRef`,
`typeTarifMembreRef`, `typeTarifNonMembreRef` — vers des objets du module `Offre`. Ce ne sont pas des
relations Doctrine : ce sont des identifiants internes recopiés.

C'est la démonstration la plus nette du besoin d'`xid`. **Un paquet ne peut pas connaître un UUID à
l'avance** : il est tiré à l'installation. Sans table de correspondance, ces trois champs ne sont
remplissables que par une intervention manuelle après installation — ce qui veut dire qu'aujourd'hui
le padel n'est pas installable sans un humain qui recopie trois UUID. La résolution `{ ref: … }` du
format n'est donc pas un confort d'écriture, c'est ce qui rend le paquet installable tout court.

**Corollaire pour l'installateur** : il doit poser les lignes dans l'ordre des dépendances et résoudre
les `ref` au fur et à mesure, pas en deux passes. Un cycle entre deux `xid` est une erreur
d'installation à refuser franchement, pas à contourner.

**Second point** : `TerrainPadel.ressource` pointe déjà `Reservation\Ressource`. La verticale s'appuie
donc **déjà** sur le noyau de réservation — la conversion vers D15 ne casse rien ici, elle rend
explicite un rattachement qui existe. Bonne nouvelle pour le coût de la conversion, et confirmation
de D16 : réserver est bien un acte unique, seul le mot change.
