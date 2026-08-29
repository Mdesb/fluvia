# Paquet `ice-rink`

> Périmètre `claude-I`. Rédigé au [format proposé](paquet.md). Statut : **rédigé, non installable**.

## Composition

```yaml
id: ice-rink
version: 1.0.0

activities:
  - type: entry                       # séances de glace, zones, jauge
  - type: equipment_rental            # patins par pointure, avec caution
    subject: skates

modules: [ephemeral-season, skate-sharpening]

vocabulary:
  resource: Zone de glace
  resource_unit: Piste
  slot: Séance de glace
  booking: Session
  participant: Patineur
  staff: Encadrant glace
  entry: Entrée
  rental: Location de patins
  deposit: Caution patins
  capacity: Jauge de zone
  multi_entry_card: Carte de séances
```

- `ephemeral-season` : une patinoire de Noël ouvre six semaines. `SaisonEphemere` porte une **fenêtre
  qui gèle tout le reste** — un listener refuse les écritures hors fenêtre. Aucune brique d'activité
  n'exprime « ce métier n'existe que du 1er décembre au 15 janvier ».
- `skate-sharpening` : l'affûtage sort un patin du parc, le rend indisponible, puis le réintègre. Un
  cycle de maintenance sur du matériel loué — pas une activité.

## Données de départ

```yaml
seed:
  - xid: ice-rink.zone.ice
    entity: Patinoire\ZonePatinoire
    noupdate: true
    data: { typeZone: glace, espaceAcces: { ref: core.access_space.main } }
  - xid: ice-rink.zone.stands
    entity: Patinoire\ZonePatinoire
    noupdate: true
    data: { typeZone: gradins, espaceAcces: { ref: core.access_space.main } }

  - xid: ice-rink.skates.size.34            # bloc généré, pointures 28 à 46
    entity: Patinoire\ParcPatins
    noupdate: true
    data:
      pointure: 34
      quantiteTotale: 12
      produitLocationRef: { ref: ice-rink.product.skate_rental }
      actif: true
```

Le parc de patins est **le meilleur exemple du non-écrasement**. Un exploitant ajuste ses quantités par
pointure à chaque saison, en fonction de ce qui casse et de ce qui manque. Une montée de version qui
réinjecte « 12 paires en 34 » lui fait perdre un inventaire tenu à la main toute l'année. `noupdate:
true`, sans discussion possible.

`ParcPatins.produitLocationRef` est, comme chez le padel, **un `Uuid` nu vers `Offre`** — troisième
occurrence du même motif. Voir [padel.md](padel.md) : c'est ce qui rend la table de correspondance
`xid` indispensable et non facultative.

## Un signalement, pas une prise de périmètre

`ListeAttentePointure` — s'inscrire quand sa pointure est sortie, être promu quand elle rentre, se
voir proposer la pointure voisine — est **une liste d'attente**. C'est le périmètre de `claude-E`
(Smart Flow, `SF-2`), pas le mien.

Je ne la déplace pas et je ne la touche pas (règle 2). Je signale : si Smart Flow pose une liste
d'attente générique, la patinoire devrait la consommer plutôt que d'en garder une seconde, et ce
paquet perdrait un module de code au passage. **Arbitrage à `claude-A`**, pas à moi ni à `claude-E`.
