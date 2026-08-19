# Catalogue d'événements métier — v0

Les événements de **premier rang** que le core et les modules publient sur le bus. Un événement est
un **fait passé** (nommé au passé). Les modules s'y abonnent ; personne n'appelle personne directement.

> ⚠️ **À trancher (D-ouverte) :** langue canonique des noms. Le doc SmartFlow utilise l'anglais
> (`payment.failed`), notre code est en français. **Proposition v0 :** noms canoniques **français**
> `domaine.fait` (cohérent avec le code billetterie), un alias EN documenté pour l'interop OFS/Vespera.

## Enveloppe commune (tout événement)
```
{
  "name": "paiement.echoue",
  "occurredAt": "2026-08-19T10:00:00Z",
  "tenant":  { "etablissementId": "…" },     // périmètre — toujours présent
  "actor":   { "userId": "…" | null },        // qui a déclenché (null = système)
  "subject": { "type": "Paiement", "id": "…" },// l'entité concernée
  "payload": { … }                             // données spécifiques à l'événement
}
```

## Événements v0 par domaine
| Événement | Émis par | Charge utile clé | Consommateurs probables |
|---|---|---|---|
| `vente.validee` | Vente/Caisse | montant, lignes, client? | Reporting, Revenue Recovery |
| `vente.annulee` | Vente/Caisse | motif, montant | Compta, Autorisation |
| `panier.abandonne` | Boutique | montant, client | Revenue Recovery |
| `paiement.reussi` | Paiement | montant, moyen | Compta, Facturation |
| `paiement.echoue` | Paiement / SEPA | montant, cause | **Revenue Recovery**, anti-impayés |
| `remboursement.emis` | Caisse/Facturation | montant, avoir? | Compta |
| `facture.emise` | Facturation | numéro, montant TTC | Compta, Communication |
| `facture.echue` | Facturation | montant, retard | **Revenue Recovery** |
| `facture.payee` | Facturation | montant, date | Compta |
| `avoir.emis` | Facturation | montant | Compta |
| `reservation.creee` | Reservation | créneau, ressource | Smart Flow |
| `reservation.annulee` | Reservation | créneau, délai | **Smart Flow**, Revenue Recovery |
| `rdv.no_show` | Reservation | client, montant à risque | **Revenue Recovery**, Smart Flow |
| `rdv.termine` | Reservation | durée | Reporting |
| `creneau.libere` | Smart Flow | créneau, ressource | **Smart Flow** (slot recovery), liste d'attente |
| `abonnement.cree` | SEPA/Abonnement | montant récurrent | Compta |
| `abonnement.suspendu` | SEPA/Abonnement | motif | Revenue Recovery |
| `acces.enregistre` | Contrôle d'accès | porte, support | Reporting, Smart Flow (affluence) |
| `acces.refuse` | Contrôle d'accès | motif | Supervision |
| `devis.envoye` | Devis | montant, échéance | Revenue Recovery |
| `devis.expire` | Devis | montant | **Revenue Recovery** (quote recovery) |
| `client.inactif` | CRM | dernier contact | Revenue Recovery (win-back) |
| `lead.sans_reponse` | CRM/Commercial | canal, délai | Revenue Recovery (lead recovery) |
| `facture_fournisseur.enregistree` | Finance | fournisseur, montant, OCR? | Compta, Trésorerie |
| `note_de_frais.soumise` | Notes de frais | salarié, montant | Autorisation, Compta |

## Règles de nommage
- `domaine.fait_au_passe`, minuscules, `snake_case` pour le fait.
- Un événement ne porte **jamais** de secret ni de PII inutile ; il porte des **références** (ids) + le minimum.
- Ajouter un événement = 1 ligne ici + le déclarer dans le manifeste du module émetteur.
