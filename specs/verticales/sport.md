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

  - xid: fitness.product.monthly
    entity: Offre\Produit
    noupdate: true
    data: { nom: Abonnement mensuel }
  - xid: fitness.plan.monthly
    entity: Offre\Formule                # facette du produit ci-dessus, elle n'a pas de nom propre
    noupdate: true
    data: { produit: { ref: fitness.product.monthly }, periodicite: mensuel, sepaActif: true }
```

## Deux écarts relevés en revue de cohérence

**1. `Offre\Formule` n'a pas de nom.** C'est une *facette* d'un `Produit` : le libellé vit sur le
produit. Une ligne de départ qui croit semer « une formule nommée » sème donc deux objets liés, et
l'ordre compte. Le format le supporte (`{ ref: … }`), mais il fallait le voir : ma première rédaction
était fausse.

**2. Le fitness sait faire de l'hebdomadaire, le noyau ne sait pas le dire.**
`PeriodiciteAbonnementFitness` propose `mensuel` **et** `hebdomadaire` ; `Offre\PeriodiciteFormule`
ne propose que `mensuel`, `annuel`, `personnalise`. Un abonnement hebdomadaire ne peut donc être
exprimé côté offre que par `personnalise`, ce qui le rend invisible à tout ce qui raisonne sur la
périodicité — facturation, relance, prorata.

Ce n'est pas un défaut du paquet, c'est un écart entre la verticale et le noyau, et il préexiste à ma
conversion. `Offre` est le périmètre de `claude-G` : je ne le corrige pas, je le signale à
`claude-A`. Le paquet reste rédigé avec `mensuel` seul tant que l'écart n'est pas tranché.

**3. Le préavis de résiliation n'est pas paramétrable par formule.** `preavisResiliationJours` est
porté par `AbonnementFitness`, donc par **chaque abonnement souscrit**, pas par le plan. Un paquet ne
peut pas semer « 30 jours de préavis » comme valeur par défaut de l'offre : il n'y a pas d'endroit où
l'écrire. Signalé pour la même raison.

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
