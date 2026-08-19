# Catalogue des modules de la plateforme — v0

Vue d'ensemble de tout ce que la plateforme héberge, rangé en **familles**. Un module = une capacité
activable (voir [manifeste-module.md](manifeste-module.md)). `✓` existant · `~` en cours · `◆` à venir.

## A. Noyau commun (toujours actif)
Identité · Organisations/Tenant · Cloisonnement · Permissions (module×action) · Audit ·
Billing & Features · Communication · Automation/Scheduler · **Bus d'événements** · **Registre de modules**.
→ voir [noyau-commun.md](noyau-commun.md).

## B. Services transverses partagés (consommés par plusieurs modules, jamais métier)
| Service | Rôle | Consommé par | État |
|---|---|---|---|
| **OCR** | Extraction structurée depuis image/PDF | Factures fourn., Notes de frais, Appels d'offres | ◆ |
| **GED interne** | Stockage / versioning / droits / recherche de documents | Intervention (PV, photos), Appels d'offres, Finance, Formation | ◆ |
| **Signature électronique** | Signature client/interne horodatée sur un document | Intervention (PV réception), Devis, Contrats | ◆ |
| **Communication** | Email / SMS / WhatsApp / push + templates | tous | ~ (mailer existant à étendre) |
| **i18n / Traduction** | Clés de traduction + catalogues par langue (FR défaut) + **agent de traduction** (IA) | tout libellé visible | ◆ |

## C. Vente & Offre
Offre/Catalogue ✓ · Vente/Caisse ✓ · Boutique en ligne ✓ · Options produit ✓ ·
Réservation & no-show ✓ · Contrôle d'accès ✓ · Stock ✓.

## D. Finance & Compta *(spec en cours — voir specs/finance)*
Factures client+fournisseur ~ · Comptabilité générale + FEC ~ · Trésorerie/rapprochement ◆ ·
Notes de frais ◆ · SEPA / abonnements / anti-impayés ✓.

## E. CRM, Avant-vente & Commercial
| Module | Rôle | Se branche sur | Événements | État |
|---|---|---|---|---|
| CRM noyau | Clients, segments, historique | — | `client.inactif` | ✓ |
| Devis | Devis → commande | Offre, Facturation | `devis.envoye/expire/accepte` | ✓ |
| **Check-list faisabilité prospect** | Grilles paramétrables sur un RDV prospect (contraintes techniques, go/no-go, éléments de chiffrage) | CRM, Réservation (RDV), Devis | `faisabilite.evaluee` | ◆ |
| **Analyse d'appels d'offres + pré-réponse** | Ingestion des pièces AO (via OCR/GED), extraction des exigences, matrice de conformité, **pré-réponse générée** (IA) — relecture humaine obligatoire | Commercial, Devis, OCR, GED, Communication | `ao.analysee`, `ao.pre_reponse_generee` | ◆ |
| Revenue Recovery | Relances/dunning/win-back pilotés par événements | Paiement, Facturation, Réservation, CRM | consomme `paiement.echoue`, `facture.echue`, `rdv.no_show`, `devis.expire`, `client.inactif` | ◆ |

## F. Terrain & Intervention
| Module | Rôle | Se branche sur | Événements | État |
|---|---|---|---|---|
| **Validation d'intervention sur site** | PV de réception (livré / posé / fonctionnel), **checklist d'installation paramétrable**, **signature client**, **photos** horodatées/géoloc | Personnel (technicien), Réservation (RDV site), GED, Signature | `intervention.planifiee`, `intervention.validee` | ◆ |
> Directement réutilisable par **OFS** (pose de machines) → bon premier candidat de module partagé inter-projets.

## G. RH & Interne
| Module | Rôle | Se branche sur | État |
|---|---|---|---|
| Personnel & planning | Employés, planning, accès staff | Contrôle d'accès | ✓ |
| Notes de frais | Soumission + justificatif + validation graduée + remboursement | Personnel, Autorisation, Compta, OCR | ◆ |
| **Formation / enablement** | Parcours de formation **paramétrables par audience** (commerciaux, techniciens…), quiz, attestation — s'appuie sur le moteur doc de Support | Support, Personnel, GED | `formation.terminee` | ◆ |

## H. Opérations temps réel
**Smart Flow** ◆ — gestion des retards, créneaux libérés (slot recovery), liste d'attente, optimisation
du planning. Consomme `reservation.annulee`, `rdv.no_show`, `acces.enregistre` ; émet `creneau.libere`.

## I. Pilotage & Support
Reporting multi-niveaux ✓ · Base de connaissance / Support ✓ (moteur doc réutilisé par Formation).

## J. Autorisations transverses
Autorisations graduées ✓ (plafonds/périmètre/escalade) — consommée par Notes de frais, Finance,
annulations/remboursements. Caution ✓.

---
### Note de conception
Beaucoup de ces « modules » partagent 3 briques transverses : **GED**, **OCR**, **Signature**. Les
poser tôt (services partagés) évite que chaque module réinvente le stockage de documents, l'extraction
et la signature. → candidats prioritaires après le bus d'événements.
