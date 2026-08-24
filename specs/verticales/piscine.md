# Paquet `swimming-pool`

> Périmètre `claude-I`. Premier paquet rédigé au [format proposé](paquet.md) — il sert de banc d'essai
> à ce format autant qu'il décrit la piscine. Statut : **rédigé, non installable** (l'installateur est
> du noyau et n'existe pas).

## Composition

```yaml
id: swimming-pool
version: 1.0.0

activities:
  - type: entry                       # entrées grand public, jauge, contrôle d'accès
  - type: resource_booking            # créneaux de bassin, affectation de lignes
    unit: lane
  - type: coaching                    # encadrement qualifié MNS / BNSSA
  - type: equipment_rental            # casiers de vestiaire, avec caution
    subject: locker

modules: [pool-safety]                # POSS : jauge réglementaire, prorata, seuil

vocabulary:
  resource: Bassin
  resource_unit: Ligne d'eau
  slot: Créneau public
  participant: Baigneur
  staff: Maître-nageur
  capacity: Jauge POSS
  deposit: Caution casier
  multi_entry_card: Carte d'entrées
```

Les quatre activités et le module unique tombent exactement sur le découpage relevé dans
[composition.md](composition.md) : ce qui déborde des neuf briques, c'est le POSS et rien d'autre.

## Données de départ

Tout est en `noupdate: true` — un exploitant règle ses tarifs, ses bassins et ses cautions dès la
première semaine, et une montée de version qui les réécrit est la régression que D15 interdit
nommément. Aucune ligne de ce paquet ne justifie l'inverse.

```yaml
seed:
  # --- paramétrage de l'établissement ---
  - xid: swimming-pool.settings
    entity: Piscine\ParametrePiscineEtablissement
    noupdate: true
    data:
      delaiForcageCasierJours: 3
      montantCautionCasierDefaut: "10.00"
      modeProrataDefaut: lignes

  # --- un bassin de démarrage, pour que l'écran ne soit pas vide ---
  - xid: swimming-pool.pool.main
    entity: Piscine\Bassin
    noupdate: true
    data:
      libelle: Grand bassin
      espace: { ref: core.space.main }     # référence par xid, jamais par libellé
      nbLignes: 4
      capacite: 150

  - xid: swimming-pool.lane.1              # … .2, .3, .4
    entity: Piscine\LigneEau
    noupdate: true
    data: { numero: 1, bassin: { ref: swimming-pool.pool.main }, etat: publique }

  # --- vestiaire ---
  - xid: swimming-pool.locker.a.1          # … bloc de 1 à 40
    entity: Piscine\Casier
    noupdate: true
    data: { numero: 1, zone: Vestiaire A, etat: libre }

  # --- tarifs (module Offre) ---
  - xid: swimming-pool.tariff.adult
    entity: Offre\TypeTarif
    noupdate: true
    data: { code: ADULT, label: Adulte }
  - xid: swimming-pool.tariff.child
    entity: Offre\TypeTarif
    noupdate: true
    data: { code: CHILD, label: Enfant }
  - xid: swimming-pool.tariff.school
    entity: Offre\TypeTarif
    noupdate: true
    data: { code: SCHOOL, label: Scolaire }
```

## Deux enseignements que ce paquet apporte au format

**1. Un paquet sème dans les entités d'autres modules.** Les trois tarifs sont des `Offre\TypeTarif`,
et le bassin référence un `Organisation\Espace`. C'est normal — un paquet compose, il ne possède pas
— mais cela impose deux choses au noyau : que les objets du socle portent eux aussi un `xid`
(`core.space.main` ci-dessus), et que l'installateur écrive dans des modules qu'il ne connaît pas,
donc **par le mapping Doctrine, sans passer par les processeurs d'API**. Le contrôle de périmètre doit
alors être posé par l'installateur lui-même, pas hérité d'un `Perimetre<X>Extension` — c'est
précisément le cas que le garde-fou de cloisonnement surveille.

**2. `noupdate: false` n'a trouvé aucun emploi ici.** J'ai cherché une ligne de départ que le client
n'éditerait jamais et que le paquet aurait donc intérêt à tenir à jour : il n'y en a pas. Les seuls
référentiels réellement figés de la piscine — `MNS`, `BNSSA` — sont une **énumération PHP**
(`TypeEncadrement`), donc du code, pas des données de départ. Cela renforce le défaut proposé dans
[paquet.md](paquet.md) : `noupdate: true` par défaut, `false` écrit à la main et justifié.

## Ce qui reste ouvert

- **`core.space.main` n'existe pas.** Le socle ne pose aucun identifiant externe. Sans lui, le bassin
  de départ ne sait pas à quel espace se rattacher, et le paquet n'est pas installable. Signalé à
  `claude-A` — c'est le point 2 des arbitrages ouverts.
- **Le bloc de 40 casiers est écrit en compréhension**, pas ligne à ligne. Le format doit dire s'il
  accepte une génération (`range`) ou s'il exige quarante lignes. Mon avis : une génération, sinon les
  paquets deviennent illisibles et personne ne les relit — mais chaque ligne générée doit recevoir son
  `xid` propre, sans quoi la règle « une ligne supprimée ne ressuscite pas » ne tient plus.
