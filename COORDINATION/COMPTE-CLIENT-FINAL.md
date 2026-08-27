# Un compte par client Fluvia, ou un compte global ? — et pourquoi le code a déjà à moitié tranché

*Question posée par Maxime le 27/08/2026. **Non tranchée** : elle touche au schéma et au RGPD.*

## L'état réel, qui n'est ni l'un ni l'autre

| Fait | Où |
|---|---|
| `Utilisateur.email` est **unique sur toute la plateforme** | `uniq_utilisateur_email` |
| `CompteClient` est **unique par utilisateur** — un compte par personne | `uniq_compte_client_utilisateur` |
| Le compte est créé **depuis une vitrine** et porte un `?Etablissement` | `CreerCompteClientProcessor` |

C'est donc **déjà un compte global**, estampillé de l'établissement qui l'a créé en premier.

**Le pire des deux mondes.** Une identité mondiale portant une étiquette locale qui cesse d'être vraie
à la deuxième boutique. Conséquence immédiate, vérifiable : une personne qui a acheté à la Piscine A
**ne peut pas** créer de compte à la Patinoire B avec la même adresse — l'inscription heurte
l'unicité, sans que rien n'explique au client pourquoi.

## Recommandation : compte global, appartenance par établissement

1. **L'unicité de l'e-mail l'impose déjà.** L'autre voie demande de cloisonner l'unicité par
   établissement — donc **la même personne dupliquée N fois**, avec N mots de passe et N vérifications
   d'adresse.
2. **RGPD.** Une demande d'effacement s'exécuterait N fois, et le droit d'accès rendrait N réponses
   partielles. Le module `Crm\DemandeRGPD` suppose déjà une personne, pas N copies.
3. **Attente du client final.** Une connexion, et ses boutiques dedans. C'est ce que fait tout le
   monde, et l'inverse s'explique mal.

## La condition non négociable

**L'établissement ne voit que les transactions faites chez lui.** Le cloisonnement se porte sur les
commandes, jamais sur le compte.

Sans cette règle, un compte global fait apparaître un client de la Piscine A dans le CRM de la
Patinoire B — **exactement la fuite trouvée le 27/08 sur les produits** (`CLOISONNEMENT-PRODUIT.md`),
transposée aux personnes, où elle devient une violation de données et plus seulement un défaut.

> Un identifiant partagé n'est pas une donnée partagée. C'est la confusion des deux qui fabrique les
> fuites, et elle se lit toujours comme une simplification.

## Ce qui reste à décider

- Le champ `CompteClient.etablissement` : le **retirer** (l'appartenance se déduit des commandes) ou
  le **garder** comme « établissement d'origine », purement informatif ? Le garder sans le dire
  clairement est ce qui a produit l'ambiguïté actuelle.
- La vitrine d'inscription doit-elle **rattacher** le compte, ou seulement l'**avoir vu naître** ?
