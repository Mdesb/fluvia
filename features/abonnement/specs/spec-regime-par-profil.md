# Spec — Régime d'abonnement par profil (deux CGV) — #96

> **CP-1 décidé par Jarvis en autonomie (Maxime absent), à valider par Maxime (juriste).** Applique la décision **D113** (audit du 14/09) : particuliers et collectivités relèvent de régimes distincts. L'audit reprochait un régime « non fixé » — ce document le fixe.

## Le profil

Le régime découle du **type du payeur** (`Crm\Client.type`) :

| Profil | Payeur | Cadre |
|---|---|---|
| **Consommateur** | personne physique (souscription notamment via la boutique) | droit de la consommation |
| **Professionnel / collectivité** | personne morale | son marché / contrat de droit commun |

Défaut protecteur : hors personne morale identifiée, on retient le régime **consommateur** (le plus protecteur) plutôt que de supposer un professionnel.

## Régime consommateur (personne physique)

| Règle | Décision (à valider) | Fondement |
|---|---|---|
| **Rétractation** | 14 jours sur une souscription à distance, sauf exécution immédiate expressément demandée (et renoncement au droit) | L221-28 |
| **Reconduction tacite** | information de la faculté de non-reconduction due au client | L215-1 |
| **Préavis de résiliation** | plafonné à **30 jours** *(choix produit protecteur, à arrêter)* | L215-1 s. |
| **Engagement** | durée fixée par la formule ; après reconduction, résiliation possible à tout moment sous préavis | — |

## Régime professionnel / collectivité (personne morale)

Pas de protection consommateur : durée d'engagement, préavis et reconduction relèvent du **marché** / contrat. Aucun plafond de préavis imposé côté produit.

## Ce que fixe le lot 1 (livré)

- `App\Membership\Regime\SubscriberRegime` (enum Consumer / Professional) : le profil et ses règles, **consultable par tout le code** — c'est le régime « fixé » que l'audit demandait. Testé à nu.
- Validation `ConsumerNoticeCap` sur `Membership` : un abonné consommateur ne peut avoir un préavis de résiliation supérieur au plafond ; un professionnel n'est pas plafonné.

## Ce qui reste (lot 2, à ouvrir)

- **Le flux de rétractation** lui-même : fenêtre de 14 jours à la souscription boutique, renoncement explicite en cas d'exécution immédiate, remboursement. Touche Boutique + remboursements — vrai chantier.
- **La notification de non-reconduction tacite** (L215-1) : rappel au client avant l'échéance de reconduction.
- **Les deux jeux de CGV rédigés** eux-mêmes (texte contractuel), portés par le profil.

## À valider par Maxime (juriste)

- Le **plafond de préavis conso à 30 jours** (valeur protectrice usuelle, pas un texte unique).
- Le **délai de rétractation** (14 j) et les modalités de renoncement en exécution immédiate.
- Faut-il un profil **intermédiaire** (association, micro-entreprise) ou physique/morale suffit-il ?

---

*Spec du régime par profil (#96, D113). Lot 1 : le régime est fixé et consultable (`SubscriberRegime`) + un plafond de préavis conso. Les flux (rétractation, notification) suivent en lot 2.*
