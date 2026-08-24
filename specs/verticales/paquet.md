# Format de paquet verticale

> Périmètre `claude-I` (`specs/verticales/**`). Application de **D15**.
> **Proposition à `claude-A`.** Le carnet donne `ACT-0` en `CLAIM` chez lui ; ce document est ce que
> j'ai pu écrire sans sortir de mon périmètre, à partir des cinq verticales réelles. Il est fait pour
> être adopté ou remplacé, pas pour trancher à sa place.

## Ce qu'un paquet est, et ce qu'il n'est pas

Un paquet verticale **n'est pas un module**. Un module apporte du code parce qu'il apporte une règle
nouvelle ; un paquet ne fait que **composer** des activités existantes, les nommer dans la langue du
métier, et poser des données de départ. Les deux coexistent : le paquet `swimming-pool` compose quatre
activités et s'appuie sur le module de code `pool-safety` qui, lui, porte le POSS.

La distinction se teste en une question : *si je retire ce paquet, reste-t-il du code sans emploi ?*
Si oui, ce n'était pas un paquet.

## Les cinq blocs

```yaml
id: swimming-pool          # anglais (D5), unique, stable — sert de préfixe aux identifiants externes
version: 1.0.0

activities:                # parmi les neuf types de D15 — aucune invention ici
  - type: entry
  - type: resource_booking
    unit: lane
  - type: coaching
  - type: equipment_rental
    subject: locker

modules: [pool-safety]     # modules de code requis, par `id` de manifeste. Souvent vide.

vocabulary:                # surcharge des clés du catalogue — voir vocabulaire.md
  resource: Bassin
  resource_unit: Ligne d'eau
  participant: Baigneur

seed:                      # données de départ — voir ci-dessous, c'est le bloc qui compte
  - xid: swimming-pool.tariff.adult
    entity: Offre\TypeTarif
    noupdate: true
    data: { nom: Adulte, ordreAffichage: 1, actif: true }
```

`activities` ne prend que les neuf types de D15. **Un paquet qui a besoin d'un dixième type est un
signalement, pas une extension** : il remonte à l'intégrateur, il ne s'invente pas un type local.

## Le bloc `seed` — le seul point non négociable

D15 est explicite : *« données de départ à identifiants stables, avec non-écrasement à la mise à jour,
faute de quoi une montée de version écrase les tarifs du client »*. Trois règles en découlent, et la
troisième est celle qu'on oublie.

**1. On réconcilie par `xid`, jamais par libellé.** Chaque ligne de départ porte un identifiant externe
stable, préfixé par l'`id` du paquet. Une table de correspondance `xid → (entité, id interne,
établissement)` fait foi. Réconcilier par libellé casse à la première traduction et crée un doublon à
la première faute de frappe du client.

**2. `noupdate: true` est le défaut pour tout ce que le client peut modifier.** Tarifs, libellés,
capacités, horaires : la montée de version **crée si absent, et ne touche jamais si présent**.
`noupdate: false` est réservé aux référentiels techniques que le client n'édite pas — et il doit être
écrit à la main, ligne par ligne. Le défaut inverse serait la régression que D15 nomme.

**3. Une ligne supprimée par le client ne ressuscite pas.** C'est le piège de la réinstallation : si
la table de correspondance n'enregistre que ce qui existe, une montée de version recrée ce que
l'exploitant a délibérément supprimé — et il le resupprime à chaque version, sans jamais comprendre
d'où ça revient. La correspondance doit donc porter trois états : `installé`, `modifié par le client`,
**`supprimé par le client`** — le troisième est absorbant.

## Ce que l'installation doit respecter

- **Cloisonnement (D3/D8).** Installer un paquet écrit des lignes rattachées à un établissement. Le
  périmètre vient de la session serveur, jamais du corps de la requête — au même titre qu'un appel
  d'API. Les cinq verticales ont chacune leur `Perimetre<X>Extension` ; l'installateur ne les
  court-circuite pas.
- **Idempotence.** Installer deux fois == installer une fois. C'est ce que la table de correspondance
  garantit, et c'est testable sans base de démonstration.
- **Désinstallation non destructive.** Alignée sur la règle du manifeste de module : désactiver
  n'entraîne aucune migration destructive, les données restent, seule l'exposition change. Retirer un
  paquet ne supprime pas les tarifs que le client a facturés.

## Ce qui reste à trancher par `claude-A`

1. **Où vit l'installateur.** Il est du noyau (`Platform` ou `Fonctionnalite`), donc hors de mon
   périmètre. Je ne le poserai pas sans accord (règle 2).
2. **Où vit la table de correspondance `xid`.** Même remarque. Une table du noyau, à mon sens, et pas
   une par verticale — sans quoi les cinq réinventent la même chose de cinq façons.
3. **Le sort de `PresetVerticale`.** La table figée qu'il remplace est dans `app/src/Fonctionnalite`,
   chez `claude-A`. La bascule doit être synchrone avec la livraison des cinq paquets, sinon la
   conversion se fait fenêtre ouverte.

## Les cinq paquets

| Paquet | Activités composées | Module de code conservé | État |
|---|---|---|---|
| `swimming-pool` | entrée · réservation de ressource · encadrement · location | `pool-safety` (POSS) | [rédigé](piscine.md) |
| `padel` | réservation de ressource · location | `padel-tournament`, `court-lighting` | [rédigé](padel.md) |
| `ice-rink` | entrée · location | `ephemeral-season`, `skate-sharpening` | [rédigé](patinoire.md) |
| `fitness` | abonnement · entrée | `lone-worker-safety` | [rédigé](sport.md) |
| `museum` | entrée · rendez-vous · location · abonnement | `ota-distribution` | [rédigé](musee.md) |

Détail de la répartition paquet/code dans [composition.md](composition.md).
