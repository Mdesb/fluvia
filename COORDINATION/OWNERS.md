# OWNERS — carte de propriété des modules

Chaque module a **un** propriétaire (l'instance qui écrit dedans). Les autres le **consomment**
uniquement via le CONTRACT (événements + API). Pour toucher un module qu'on ne possède pas :
ouvrir une entrée dans [TASKS.md](TASKS.md) et se coordonner, ne pas éditer en douce.

> Renseigner la colonne « Instance » avec un identifiant stable (ex. `claude-A`, `claude-B`, `claude-C`).
> Provisoire tant que la répartition entre les 3 plans n'est pas fixée.

## Noyau commun (core) — `app/src/{Securite,Etablissement,Audit,...}`
| Domaine | Chemin | Instance |
|---|---|---|
| Identité / Auth / Permissions | `app/src/Securite/**` | *à assigner (intégrateur)* |
| Organisations / Établissements / Contexte | `app/src/Etablissement/**` | *intégrateur* |
| Capacités & Features | `app/src/*Capacite*`, profil d'établissement | *intégrateur* |
| Audit | subscribers d'audit | *intégrateur* |
| **Bus d'événements + Manifeste de module** *(à créer)* | `app/src/Platform/**` | *intégrateur* |

## Modules existants (billetterie) — propriété actuelle = nous
Offre, Vente/Caisse, Acces, Reservation, Facturation, Compta/Régie, SEPA, Caution, Autorisation,
OptionProduit, Stock, Personnel, Boutique, Support, Reporting, verticales (Piscine/Padel/Patinoire/Musée/Sport).

## Modules à venir
| Module | Chemin cible | Instance | Statut |
|---|---|---|---|
| **Suite Finance** (Factures fourn., Compta/FEC, Trésorerie, Notes de frais) | `app/src/Finance/**` | **claude-B** | spec faite → plan |
| **OCR** (service transverse) | `app/src/Ocr/**` | **claude-B** | spec faite → plan |
| _extension additive_ `App\Compta` (lot FIN-1) | `app/src/Compta/**` | **claude-B** (coord. dossier partagé) | spec faite → plan |
| **Notes de frais** | `app/src/NoteDeFrais/**` | *à assigner* | à spécifier |
| **Smart Flow** | `app/src/SmartFlow/**` | *à assigner* | contrat d'abord |
| **Revenue Recovery** | `app/src/RevenueRecovery/**` | *à assigner* | contrat d'abord |

## Autres dépôts (fédérés, stacks distinctes — hors coquille pour l'instant)
| Projet | Stack | Rôle dans la plateforme |
|---|---|---|
| **Vespera** | Next.js / Prisma / PostgreSQL | Domaine créatrices — à re-loger en modules ou fédérer par API/événements |
| **OFS Global** | PHP 8.1 sans framework / MariaDB | Domaine vending/salles — idem, gros & mature, retrofit prudent |
