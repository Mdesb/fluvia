# Paquet `fitness`

> Périmètre `claude-I`. Rédigé au [format proposé](paquet.md). Statut : **rédigé, non installable**.

## Composition

```yaml
id: fitness
version: 1.0.0

activities:
  - type: subscription                # abonnement, échéancier SEPA, pause, résiliation
  - type: entry                       # badge d'accès à la salle

modules: [lone-worker-safety]

vocabulary:
  resource: Salle
  resource_unit: Poste
  slot: Cours
  booking: Inscription
  participant: Adhérent
  staff: Coach
  group: Cours collectif
  entry: Passage
  capacity: Effectif
  multi_entry_card: Carte de séances
```

`lone-worker-safety` est **le seul module de code**, et il est le plus défendable des cinq : bouton
SOS, détection de présence isolée, plage d'accès nocturne, limite d'occupation la nuit. Une salle
ouverte 24 h sans personnel a une obligation de sécurité qui ne ressemble à aucune activité — c'est
une règle nouvelle au sens de D15, pas un habillage.

Le reste — abonnement mensuel ou hebdomadaire, préavis, mandat SEPA, pause, réengagement, résiliation
— est **de l'abonnement standard**. `AbonnementFitness` pointe déjà `Offre\Formule`, `Crm\Client` et
`Sepa\MandatSepa` : la verticale ne réimplémente rien, elle paramètre. C'est exactement le ratio que
D15 annonçait, et le paquet `fitness` est celui qui s'en approche le plus.

## Données de départ

```yaml
seed:
  - xid: fitness.night_access
    entity: Sport\ConfigAccesNocturne
    noupdate: true
    data:
      espaceAcces: { ref: core.access_space.main }
      plageDebut: "22:00"
      plageFin: "06:00"
      videoActive: true
      boutonSosActif: true
      detectionPresenceIsoleeActive: true
      limiteOccupationNocturne: 15

  - xid: fitness.plan.monthly
    entity: Offre\Formule
    noupdate: true
    data: { label: Abonnement mensuel, periodicite: mensuel, preavisResiliationJours: 30 }
  - xid: fitness.plan.weekly
    entity: Offre\Formule
    noupdate: true
    data: { label: Abonnement hebdomadaire, periodicite: hebdomadaire, preavisResiliationJours: 7 }
```

## Le seul cas où `noupdate: false` mérite d'être discuté

`ConfigAccesNocturne` porte des réglages de **sécurité**, pas de commerce : bouton SOS actif,
détection de présence isolée active. On peut soutenir qu'une montée de version doit pouvoir
**réactiver** un garde-fou que l'exploitant aurait désactivé.

**Mon avis est non, et fermement.** Un logiciel qui rallume tout seul un dispositif de sécurité que
l'exploitant a coupé délibérément lui retire la maîtrise de son obligation légale, et le fait
silencieusement — il croit sa salle configurée comme il l'a laissée. Si le sujet doit être traité,
c'est par une **alerte à l'exploitant** (« la détection de présence isolée est désactivée depuis le
12/03 »), pas par une réécriture au dos de sa décision. `noupdate: true` ici aussi.

Ce cas est le seul des cinq paquets où j'aie hésité, et il vaut d'être consigné : il montre que la
règle « `noupdate: true` par défaut » tient même là où l'argument inverse est le plus fort.
