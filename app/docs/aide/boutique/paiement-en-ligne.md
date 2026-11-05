---
titre: "Paiement en ligne : PayFiP pour les régies, carte bancaire pour les exploitants privés"
categorie: boutique
publicCible: agent
portee: global
moduleLie: boutique
statut: publie
resume: "Comment la boutique encaisse en ligne, ce que voit l'acheteur, et ce qu'il faut fournir pour raccorder PayFiP ou le prestataire carte."
motsCles: [paiement en ligne, payfip, dgfip, regie, numcli, carte bancaire, psp, stripe, rapprochement, remise]
---
## Quel moyen de paiement, pour quel établissement

Le moyen de paiement dépend du **profil d'exploitant** de l'établissement (Comptabilité, profil exploitant) :

- **Régie directe (collectivité)** : l'acheteur paie sur **PayFiP**, le service de paiement de la
  Direction générale des Finances publiques. L'argent arrive sur le compte de dépôt de fonds au Trésor
  du régisseur.
- **Délégation de service public ou groupe privé** : l'acheteur paie par carte sur la page sécurisée du
  **prestataire bancaire** de l'exploitant.

Changer le type du profil change le moyen de paiement des commandes suivantes, sans rien ressaisir.

## Ce que voit l'acheteur

1. Il valide « Vos billets », puis clique sur **Payer** : il part sur la page de paiement sécurisée
   (PayFiP ou la banque). Aucune donnée de carte ne passe par la boutique.
2. Il paie, puis revient sur la boutique. La boutique **demande l'issue au prestataire** avant d'afficher
   quoi que ce soit :
   - **payé** : la commande est confirmée, les billets s'affichent et partent par courriel ;
   - **refusé ou abandonné** : rien n'est débité, le panier est conservé, il peut réessayer ;
   - **réponse pas encore arrivée** : la boutique le dit et **ne propose pas de repayer**. La confirmation
     arrivera par courriel dès que le prestataire aura répondu.
3. S'il ferme son navigateur après avoir payé, la commande est quand même confirmée : le prestataire
   prévient la boutique de son côté (PayFiP le fait dans les deux heures, ou la nuit suivante).

Une commande n'est jamais encaissée deux fois, même si le retour de l'acheteur et l'avis du prestataire
arrivent en même temps.

## PayFiP : ce qu'il faut demander à la DGFiP

Le raccordement se fait avec le **correspondant moyens de paiement** de la direction départementale des
Finances publiques (DDFiP), qui fait signer la convention d'adhésion de la régie.

| Ce qu'on vous donne ou vous demande | À quoi ça sert |
| --- | --- |
| Le **numéro de client PayFiP** (6 chiffres) | Identifie la régie à chaque paiement. |
| Le **nom de domaine** de la boutique, à déclarer | PayFiP n'avertit que les domaines déclarés (prise en compte sous 24 heures). |
| Un **paiement de test**, puis un **paiement d'activation** | Obligatoires avant l'ouverture. En test et en activation, PayFiP n'accepte que des **montants en euros ronds** (centimes à 00), et un montant différent à chaque essai. |

Ces valeurs sont saisies par l'équipe technique dans la configuration du serveur (voir la note
d'exploitation `infra/README.md`, « Paiement en ligne ») : le mode **T** sert aux paiements de test, **X**
au paiement d'activation, **W** aux paiements réels, à partir du lendemain de l'activation.

⚠ En mode test ou activation, PayFiP ne débite rien : ne laissez jamais ces modes en service une fois la
boutique ouverte au public.

## Rapprocher les paiements PayFiP

Chaque paiement PayFiP laisse un **bordereau** (Comptabilité, PayFiP) qui porte :

- la **référence de la dette** envoyée à PayFiP : c'est le **numéro de la vente**, sans ponctuation. C'est
  celle que le régisseur retrouve dans le **fichier de remise** quotidien que PayFiP lui envoie ;
- le montant, le résultat rendu par PayFiP (P ou V : payé ; R ou Z : refusé ; A : abandonné), le numéro
  d'autorisation et la date du paiement.

Une vente payée par PayFiP porte un règlement de moyen « PayFiP » dont la référence est l'identifiant de
l'opération PayFiP.

## Prestataire carte des exploitants privés

Le prestataire carte est choisi par l'exploitant et raccordé avec **ses** identifiants (clé de l'espace
marchand du prestataire). Tant qu'aucun prestataire n'est raccordé, la boutique en ligne d'un exploitant
privé ne peut pas encaisser en production.

## En préproduction

Sans raccordement, la préproduction utilise un **prestataire de démonstration** : l'écran de paiement
affiche « Environnement de démonstration » et deux boutons, **Payer** et **Simuler un échec**. Ce mode est
refusé en production.
