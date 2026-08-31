# Salon de massage, salon de coiffure : le métier est déjà écrit, sous un autre nom

*27/08/2026. Demandé par Maxime : « il manque les verticales salon de massage, salon de coiffure etc ».*

## Ce que j'ai failli faire, et pourquoi c'était faux

Écrire un module. En regardant `App\Reservation`, il est apparu qu'il modélise déjà ce dont vit un
salon :

| Ce qu'un salon appelle | Ce que le dépôt appelle |
|---|---|
| une prestation, avec sa durée | `Activite.dureeMinutes` |
| un praticien | `Ressource` à `capacitePropre = 1` |
| ses horaires | `DisponibiliteRessource` (jour de semaine, ouverture, fermeture) |
| ses congés | `IndisponibiliteRessource` |
| un rendez-vous | `Creneau` + `Reservation` |
| la politique d'annulation | `RegleAnnulation`, `ResolveurRegleAnnulation` |
| **le client qui ne vient pas** | `FacturationNoShow`, `DeclencherFacturationNoShowHandler` |

Le dernier est le plus révélateur : la facturation des non-présentations est **ce qui fait vivre un
salon**, et elle était déjà là.

> **Avant d'écrire un module pour un métier, il faut regarder si le métier n'est pas déjà écrit sous
> un autre nom.**

Ce qui manquait n'était pas le métier : c'était **l'écran**. Le front ne mentionnait `disponibilite`
nulle part — un coiffeur ne pouvait pas déclarer qu'il travaille le mardi.

**Livré** : *Réservation › Horaires et absences* — semaine type par ressource, absences à venir,
ajout et retrait. Une ressource sans horaire est signalée, avec sa conséquence : *rien ne peut être
réservé dessus*.

## Ce qui reste, et qui est un vrai choix de produit

**Deux modèles de prise de rendez-vous existent, et ils ne se ressemblent pas.**

**A — Créneaux préétablis.** L'exploitant crée des créneaux ; le client en choisit un. C'est ce que
fait le module aujourd'hui, et c'est juste pour une piscine, un terrain de padel, une visite guidée :
la séance de 14 h existe indépendamment de qui la réserve.

**B — Placement libre dans une plage.** Il n'y a pas de créneau : il y a des horaires d'ouverture, et
une coupe de 45 minutes se pose là où elle tient. C'est le modèle du coiffeur, du masseur, du
praticien — et c'est celui que le module ne fait **pas**.

La différence n'est pas cosmétique :

- en A, deux clients ne peuvent pas se chevaucher parce que le créneau est unique ;
- en B, il faut **calculer les trous** entre les rendez-vous déjà pris, en tenant compte de la durée
  de la prestation demandée, des absences, et — pour un salon — du temps de battement entre deux
  clients.

**Ce qui est nécessaire pour B, et qui n'existe pas :** un service qui, pour une ressource, une date
et une durée, rend les débuts possibles. Tout le reste est là.

**Question ouverte pour Maxime :** vend-on B ? Si oui, c'est un lot de développement à part entière —
pas une case à cocher — et il faut trancher trois points que le code ne peut pas deviner :

1. **Le pas de proposition** : des débuts toutes les 15 minutes, ou collés à la fin du rendez-vous
   précédent ? Le premier laisse des trous invendables, le second empêche le client de choisir.
2. **Le battement entre deux clients** (nettoyage, remise en état) : porté par la prestation, par le
   praticien, ou par l'établissement ?
3. **Le rendez-vous avec praticien imposé ou indifférent** — « avec Julie » ou « avec qui est
   libre ». Le second double la complexité du calcul, et c'est souvent celui qui remplit l'agenda.
