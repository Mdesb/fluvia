# Manifeste de module — v0

Chaque module déclare sa fiche d'identité au core. Le registre lit ces manifestes pour : activer le
module par tenant, câbler les abonnements d'événements, exposer la navigation, vérifier les
dépendances et les droits. Aligné sur le Module Registry du doc SmartFlow.

## Champs
| Champ | Rôle | Exemple |
|---|---|---|
| `id` | Identifiant unique du module | `finance` |
| `version` | SemVer, gestion des évolutions | `0.1.0` |
| `capacite` | Capacité activable qui porte le module | `finance` |
| `dependencies` | Modules requis (par `id`) | `["facturation","compta"]` |
| `permissions` | Droits exposés (module × action) | `finance.lire`, `finance.rapprocher` |
| `events_emitted` | Événements publiés | `facture_fournisseur.enregistree` |
| `events_consumed` | Événements écoutés | `paiement.echoue`, `rdv.no_show` |
| `routes` | Écrans / navigation contribués | `/finance/tresorerie` |
| `settings` | Schéma de configuration par tenant | `{ devise, plan_comptable }` |
| `features` | Sous-fonctionnalités activables indépendamment | `no_show_recovery`, `quote_recovery` |

## Deux niveaux de test d'activation (SmartFlow)
Le core ne vérifie pas seulement `hasModule("finance")` mais aussi `hasFeature("rapprochement_bancaire")` :
un module peut être actif **sans** que toutes ses features le soient (upsell naturel).

## Forme cible (Symfony) — à implémenter (tâche C5)
```php
// app/src/Finance/FinanceModule.php
final class FinanceModule implements ModuleManifest
{
    public function id(): string { return 'finance'; }
    public function version(): string { return '0.1.0'; }
    public function capacite(): string { return 'finance'; }
    public function dependencies(): array { return ['facturation', 'compta']; }
    public function permissions(): array { return ['finance.lire', 'finance.ecrire', 'finance.rapprocher']; }
    public function eventsEmitted(): array { return ['facture_fournisseur.enregistree', 'reglement.effectue']; }
    public function eventsConsumed(): array { return ['paiement.echoue', 'facture.echue']; }
    public function features(): array { return ['rapprochement_bancaire', 'export_fec', 'ocr_factures']; }
    // routes(), settingsSchema()...
}
```

## Règles
- Un module **déclare** ce qu'il écoute ; le registre câble l'abonnement — pas d'accès direct entre modules.
- Activer/désactiver une capacité ou une feature **n'entraîne aucune migration destructive** ; les
  données restent, seule l'exposition change.
- Le manifeste est la **seule** surface qu'un autre module (ou un autre Claude) doit lire pour intégrer.
