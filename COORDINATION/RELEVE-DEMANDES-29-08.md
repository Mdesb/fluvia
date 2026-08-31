# Relevé des demandes de Maxime du 29/08 — ce qui existe, ce qui manque

Établi par claude-A avant toute écriture de code, pour la raison qui revient depuis trois jours :
**la moitié de ce qui « manque » existe déjà et n'est simplement atteignable par aucun écran.**
Écrire un mécanisme qui existe est la seule chose plus coûteuse que de ne pas l'écrire.

---

## 1. Caisse — le ticket en fin de vente

> « À la fin de la vente, on doit avoir la possibilité d'imprimer un ticket ou non. Souhaitez-vous un
> ticket, oui ou non ? Et en plus un bouton envoyé par SMS et un autre bouton envoyé par mail. »

**Le serveur sait déjà tout faire.** `POST /ventes/{id}/ticket` accepte :

    { "mode": "imprimer" | "renvoyer" | "duplicata", "canal": "email" | "sms" }

et rend le contenu complet du ticket, `impressionAutomatique`, `renvoiPropose`, `duplicata`.

**Ce qui manque est l'écran**, et un détail révélateur : `Caisse.jsx` appelle
`api.ticket(vente.id, 'imprimer')` après validation **sans lire `impressionAutomatique`**. Le serveur
calcule la règle depuis toujours et l'écran ne l'écoute pas.

⚠ **Le renvoi par courriel et par SMS ne fonctionnera pas aujourd'hui**, quoi qu'affiche l'écran :
`MAILER_DSN=null://null` avale les courriels, et aucun prestataire SMS n'est configuré. Un bouton qui
« envoie » sans que rien ne parte est exactement ce qu'on passe la semaine à retirer.

### Vente entièrement gratuite → aucun ticket ✅ FAIT le 29/08

Le seuil d'impression par défaut vaut 0,00 et la règle était `total >= seuil` : une vente à 0 €
donnait `0 >= 0`, donc impression. Corrigé, avec `venteGratuite` dans la réponse pour que l'écran
sache **pourquoi** il ne doit rien proposer. La vente reste enregistrée et comptabilisée.

⚠ « Vendus **seuls** » est le critère : un produit gratuit accompagné d'un payant imprime
normalement. C'est le total qui décide, jamais la présence d'une ligne à zéro.

### Favoris et rayonnage

`PointDeVente::$favoris` **existe déjà** (tableau JSON). Le rayonnage aussi. Ce qui manque est
l'étoile à l'écran et le tri qui la suit.

### Historique d'achat du client connecté

`GET /mes-factures` existe, avec le cloisonnement `_soi`. À vérifier côté écran client.

---

## 2. Devis et factures

| Demande | État |
|---|---|
| Choisir les produits du catalogue | `CommercialDocument` : devis → commande → livraison → facture |
| Ligne libre : intitulé, montant, TVA, catégorie comptable | **Tout existe** sur `LigneFacture` : `designation`, `prixUnitaireHT`, `tauxTva`, `categorieComptable`. `ligneVenteOrigine` est nullable — une ligne sans origine EST une ligne libre |
| Ajouter un moyen de paiement | `POST /factures/{id}/reglements` |
| **Enlever** un moyen de paiement | ⚠ **N'existe pas** — voir la question ci-dessous |
| Factures d'avoir | `POST /factures/{id}/avoir` + `AvoirFactureHandler` |
| **Factures d'acompte** | ⚠ **Rien, nulle part.** Aucune occurrence dans tout le dépôt |

### ⚠ La question sur le retrait d'un règlement

`Facture::addReglement()` existe, il n'y a **ni retrait ni suppression**. Mais avant d'en écrire un :
le dépôt a déjà tranché ce point pour les ventes (D45). Une correction de règlement y est **une
écriture compensatoire datée du jour du geste, rattachée et scellée — jamais une suppression**.

La raison est comptable : un règlement encaissé puis effacé laisse une caisse qui ne tombe plus
juste, et rien n'explique l'écart. Une contre-passation, elle, se lit.

**Décision attendue de Maxime** : « enlever » veut-il dire *effacer* (simple, et faux dès qu'un
journal est généré) ou *contre-passer* (conforme à D45, et lisible six mois plus tard) ? Tant que ce
n'est pas tranché, rien n'est écrit.

---

## 3. Achats et trésorerie

### Connecteurs bancaires — la réponse est : par un prestataire, obligatoirement

> « Je ne sais pas si c'est nous ou si on doit passer par un prestataire externe. »

**Ce n'est pas un choix technique, c'est un choix réglementaire.** Accéder aux comptes bancaires d'un
client relève de la DSP2 et exige l'agrément de **prestataire de services d'information sur les
comptes (AISP)**, délivré par l'ACPR. Sans cet agrément, aucune banque n'ouvrira ses interfaces.

Deux voies, et une seule est réaliste :

1. **Devenir agrégateur soi-même** — agrément ACPR, capital réglementaire, contrôle interne,
   assurance responsabilité civile professionnelle. Des mois, et un métier qui n'est pas le nôtre.
2. **Passer par un agrégateur agréé** — Powens (ex-Budget Insight), Bridge, Tink, Salt Edge. On
   consomme leur interface, ils portent l'agrément et les connexions bancaires.

C'est un **bloqueur externe** au même titre que le prestataire de paiement et l'envoi de courriel :
il faut un contrat, donc une immatriculation. À consigner dans `BLOQUEURS-EXTERNES.md`.

### Réception des factures fournisseurs par courriel

> « Est-ce qu'ils peuvent paramétrer une adresse mail où les factures arrivent directement ? »

⚠ **Rien n'existe** : aucune boîte de réception, aucun IMAP, aucun point d'entrée de courriel.

C'est une bonne idée et c'est un vrai chantier : une adresse par établissement, une boîte relevée,
l'extraction de la pièce jointe, le rattachement au bon fournisseur, et surtout la question de
confiance — **une adresse publique reçoit ce que n'importe qui lui envoie**. Il faut une liste
d'expéditeurs autorisés ou une adresse à jeton non devinable, sinon on ouvre un dépôt de fichiers
anonyme dans la comptabilité d'un client.

Dépend aussi du prestataire de courriel entrant, donc du même bloqueur externe.

### Dépôt par glisser-déposer

Écran. Le téléversement serveur existe (voir le visuel produit, `DocumentStore`).

---

## 4. Navigation, tableau de bord, notifications — tout est écran

- **Boutons retour partout.** Le constat de Maxime est juste et la cause est structurelle : c'est une
  application à écran unique, l'historique du navigateur ne suit pas les écrans internes. Deux
  réponses possibles — des boutons retour explicites, ou de vraies URL par écran. La seconde est plus
  lourde et règle aussi le partage de lien et le rafraîchissement.
- **Widgets de tableau de bord configurables.** Écran, plus une préférence à stocker par utilisateur —
  petit ajout serveur.
- **Centre de notification (cloche).** Écran, plus une source d'alertes côté serveur. ⚠ Plusieurs
  sources existent déjà et personne ne les regarde : alertes d'écart de caisse, incidents impayés,
  ruptures de stock, journées non closes. La cloche a de quoi se remplir dès le premier jour.

---

## 5. Typologies de produits

Maxime enverra la liste. Chacune aura sa spec — il a raison de dire qu'il faut voir les implications
derrière : le type de produit commande la projection d'accès, la règle de PCA, la jauge, la
comptabilisation.

---

## Ce qui reste à décider par Maxime

1. **Retrait d'un règlement** : effacement ou contre-passation (D45) ?
2. **Agrégateur bancaire** : quel prestataire, et quand ? C'est un contrat avant d'être du code.
3. **Réception des factures par courriel** : adresse à jeton ou liste d'expéditeurs autorisés ?
4. **Boutons retour ou vraies URL** : le second est plus coûteux et règle davantage.

---

## Addendum — un filtre qui rend toujours zéro, et ce qu'il ouvre

Mesuré le 29/08 sur `DemandeRGPD`, sur une collection contenant bien deux demandes du même client :

    ?statut=recue   →  200, total 2      la colonne scalaire répond
    ?client=<IRI>   →  200, total 0      l'association ne répond pas

Les deux propriétés étaient déclarées dans **la même annotation** `#[ApiFilter(SearchFilter…)]`.

⚠ **Sur cet écran-là, le faux est le pire possible.** Ouvert depuis la fiche de quelqu'un, il
affichait « cette personne n'a jamais demandé l'effacement de ses données » alors qu'elle en avait
deux en cours — sur le seul écran de l'application qui porte un délai légal d'un mois. Une réponse
rassurante, sans erreur, sur un sujet où l'on ne repasse pas.

### Ce qui a été écarté, et pourquoi c'est important

L'hypothèse transmise était la jointure du cloisonnement : `PerimetreCrmExtension` joint déjà
`o.client` sous un alias fixe, et deux jointures sur la même association auraient pu déplacer la
condition. Elle était plausible et elle expliquait même pourquoi `statut` marchait.

**Testée en neutralisant la restriction de périmètre : le filtre rendait toujours zéro.** L'hypothèse
était fausse. Sans cette vérification, j'aurais « corrigé » une jointure qui n'y était pour rien —
et le filtre serait resté muet.

La cause qui reste : l'identifiant de `Client` est un type Doctrine personnalisé stocké en
`BINARY(16)`. Comparer une colonne binaire à une chaîne de 36 caractères ne trouve rien, et ne lève
rien. Un filtre dédié qui lie le paramètre **avec** le type `uuid` répare — même famille que
`SaleCustomerFilter` et `ProductRefFilter`, à ceci près que le champ est ici une vraie association.

### Ce que ça ouvre : 149 propriétés à vérifier

Balayage du dépôt, après avoir écarté les scalaires et les énumérations :

| | |
|---|---|
| Propriétés de `SearchFilter` scalaires ou énumérations | 133 |
| **Sur une association ou une référence libre** | **149** |
| Tests du dépôt qui exercent un tel filtre | **0** |

⚠ **Je n'affirme pas que les 149 sont cassées.** J'ai un cas confirmé, pas cent quarante-neuf, et un
second cas n'a pas pu être mesuré faute de données (`Consentement` est en lecture seule, aucune
fixture n'en crée). Annoncer 149 défauts serait exactement l'erreur qu'on évite depuis trois jours.

Ce qui est certain : **aucun test du dépôt n'exerce un filtre par association**, ce qui est cohérent
avec l'idée qu'ils pourraient tous être muets sans que personne l'ait jamais vu.

C'est le balayage le plus rentable qui reste. Il se fait avec des mesures, une ressource à la fois,
pas avec une correction recopiée 149 fois.

### Le point général, qui dépasse ce filtre

Un filtre inconnu ou inopérant rend **200 avec une liste vide, jamais une erreur**.
`?client=nimportequoi` devrait être un 400. Tant que ce n'est pas le cas, aucun écran ne peut faire
confiance à une liste filtrée : il ne peut pas distinguer « personne ne correspond » de « ce filtre
ne marche pas ».

C'est la même famille que le 200 qui n'écrit rien, du côté lecture cette fois, et elle mérite le même
traitement global. Le filtre posé ici ferme au moins la collection sur une valeur illisible, plutôt
que de rendre les demandes de tout le monde à qui se trompe de paramètre.
