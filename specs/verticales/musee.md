# Paquet `museum`

> Périmètre `claude-I`. Rédigé au [format proposé](paquet.md). Statut : **rédigé, non installable**.

## Composition

```yaml
id: museum
version: 1.0.0

activities:
  - type: entry                       # billetterie, salles, jauge, sous-quotas
  - type: appointment                 # visites guidées, guide qualifié par langue
  - type: equipment_rental            # audioguides
    subject: audio_guide
  - type: subscription                # pass annuel

modules: [ota-distribution]

vocabulary:
  resource: Salle
  resource_unit: Salle
  slot: Créneau de visite
  booking: Réservation
  participant: Visiteur
  staff: Guide
  group: Groupe scolaire
  entry: Billet
  rental: Audioguide
  capacity: Jauge de salle
  multi_entry_card: Pass annuel
```

`ota-distribution` est le seul module conservé : allouer un quota à un revendeur, arbitrer une
priorité entre revendeurs, récupérer le quota d'un no-show, et **reverser** au partenaire. Vendre par
un tiers qui garde sa propre relation client n'est aucune des neuf briques.

## Une correction à mon inventaire du 24/08

[composition.md](composition.md) rangeait la **visite guidée multilingue** du côté du code. En lisant
le détail, c'est faux : `VisiteGuidee` est une prestation sur rendez-vous, et la qualification du
guide par langue est **une compétence requise sur une ressource** — que `Reservation\Ressource`
exprime déjà, via `competenceRequise` (D16). Le musée n'a donc besoin d'aucun code pour ses visites :
il a besoin de déclarer que la langue est une compétence.

Un module de code de moins sur les cinq verticales. Je corrige l'inventaire plutôt que de laisser
passer, parce que c'est exactement le genre d'écart qui, non repris, fait garder du code pour rien.

## Données de départ

```yaml
seed:
  - xid: museum.settings
    entity: Musee\ParametreMuseeEtablissement
    noupdate: true
    data:
      seuilPastilleTendu: 80
      tauxRemiseAudioguideDefaut: "0.00"
      delaiOptionDossierGroupeJours: 15
      modeSousQuotaSalleDefaut: alerte

  - xid: museum.room.main
    entity: Musee\Salle
    noupdate: true
    data:
      nom: Salle principale
      espace: { ref: core.space.main }
      espaceAcces: { ref: core.access_space.main }

```

**Ce que la revue de cohérence a retiré de ce paquet.** J'avais écrit deux lignes de départ pour les
gratuités scolaires. C'est faux : `Musee\Gratuite` est une gratuité **accordée** — elle pointe un
dossier de groupe et une réservation. Ce n'est pas un référentiel, c'est une écriture. Et le quota,
`ContingentGratuite`, se rattache à une exposition ou à un créneau : il ne peut pas exister avant eux.

Un paquet ne sème donc **aucune gratuité**, et c'est correct : les motifs (`eleve`, `accompagnateur`)
sont une énumération PHP, donc du code, et le quota est un acte d'exploitation. La règle qui s'en
dégage vaut pour les cinq paquets : **on ne sème que ce qu'un exploitant retrouverait vide au premier
matin**, jamais une donnée transactionnelle.

## Un second signalement vers Smart Flow

`PolitiqueDelestage` propose trois modes quand la jauge est tendue : file d'attente sur place,
redirection de parcours, alerte seule. **C'est de la gestion d'affluence** — le quatrième sujet de
Smart Flow (`SF-0` : retards, créneaux libérés, liste d'attente, **affluence**), donc le périmètre de
`claude-E`.

Comme pour la liste d'attente de la patinoire : je ne touche pas, je signale. Deux verticales sur cinq
portent chacune un morceau de Smart Flow écrit avant lui. **Arbitrage à `claude-A`** — soit Smart Flow
les absorbe et deux paquets s'allègent, soit ils restent locaux et Smart Flow doit savoir qu'il a deux
implémentations concurrentes en face de lui. Ce qu'il ne faut pas, c'est que la question ne soit posée
qu'au moment de la collision.
