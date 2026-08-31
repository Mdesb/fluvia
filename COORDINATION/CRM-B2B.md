# CRM B2B façon Sellsy : ce qui existe déjà, et les trois briques qui manquent

*27/08/2026. Demandé par Maxime : « il faut une partie CRM plus B2B un peu comme Sellsy fait (ou hubspot) ».*

## Ce qui est déjà là — et c'est la moitié du produit

| Brique Sellsy | Dans le dépôt | Écran |
|---|---|---|
| Société cliente | `Client` avec `TypeClient::Morale`, `raisonSociale`, `siret` (+ validation) | Clients |
| **Devis** | `CommercialDocument` / `DocumentNature::Quote` | Facturation |
| Bon de commande | `DocumentNature::SalesOrder` | Facturation |
| Bon de livraison | `DocumentNature::DeliveryNote` | Facturation |
| Accepter / refuser un devis | `POST /billing/documents/{id}/accept` · `/reject` | Facturation |
| Transformer en facture | `POST /billing/documents/{id}/invoice` · `/derive` | Facturation |
| Facture, règlement, relance | `Facture`, `ReglementFacture`, `App\Recouvrement` | Facturation, Comptabilité |
| Fiche client consolidée | `FicheClient360Provider` | Clients |

**La chaîne devis → commande → facture → relance est écrite, exposée et branchée.** C'est le cœur d'un
CRM B2B, et il n'y a rien à refaire.

## Les trois briques qui manquent réellement

### 1. Les contacts d'une société

`Beneficiaire` porte `RoleBeneficiaire` = *payeur* / *bénéficiaire*. C'est une sémantique de
**famille**, pas d'entreprise : elle ne sait pas dire « Mme X, directrice », « M. Y, comptabilité »,
« M. Z, celui qui signe ».

Une société avec un seul interlocuteur nommé dans un champ texte est ce qui fait perdre un client le
jour où cette personne part.

**Coût : faible.** Une entité `ContactClient` (client, nom, fonction, courriel, téléphone, principal
oui/non), un CRUD, un bloc sur la fiche client.

### 2. Le pipeline d'opportunités

Rien n'existe entre « prospect » et « devis émis ». Or c'est là que vit un commercial : une
opportunité qualifiée, un montant estimé, une probabilité, une échéance, une étape.

**Coût : moyen — et il demande une décision qui n'est pas technique.**

> **Les étapes d'un pipeline ne sont pas génériques : ce sont celles de l'entreprise qui vend.**

Livrer « Prospection / Qualification / Proposition / Négociation / Gagné / Perdu » parce que c'est ce
que fait tout le monde produirait un écran que personne n'utilise. **Il faut les étapes de Maxime**,
et savoir si elles doivent être paramétrables par exploitant (ce qui double le travail, et qui est
probablement nécessaire si le produit est vendu à des collectivités *et* à des salons).

### 3. Les activités commerciales

Appels, courriels, rendez-vous, **relances programmées**. C'est ce qui fait qu'un devis émis ne meurt
pas de silence.

⚠ **Attention au doublon.** `App\Support` gère déjà des tickets avec fil de messages et affectation.
Un « module d'activités commerciales » écrit à côté ferait deux boîtes de suivi dans le même produit,
et personne ne saurait où écrire. À trancher avant d'écrire une ligne : **étend-on `Support`, ou
sépare-t-on l'assistance du suivi commercial ?**

## Ce que je recommande

Livrer **(1) les contacts** — sans ambiguïté, utile immédiatement, faible coût.

**Ne pas écrire (2) et (3) sans réponse**, parce que les deux se trompent silencieusement : un
pipeline aux mauvaises étapes s'abandonne au bout d'une semaine, et un module d'activités en doublon
de `Support` coûte deux fois pendant des années.
