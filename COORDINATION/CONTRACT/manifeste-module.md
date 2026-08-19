# Manifeste de module — v0

Chaque module déclare sa fiche d'identité au core. Le registre lit ces manifestes pour : activer le
module par tenant, câbler les abonnements d'événements, exposer la navigation, vérifier les
dépendances et les droits. Aligné sur le Module Registry du doc SmartFlow.

## Champs
| Champ | Rôle | Exemple |
|---|---|---|
| `id` | Identifiant unique du module | `finance` |
| `version` | SemVer, gestion des évolutions | `0.1.0` |
| `capability` | Capacité activable qui porte le module | `finance` |
| `dependencies` | Modules requis (par `id`) | `["invoicing","accounting"]` |
| `permissions` | Droits exposés (module × action) | `finance.read`, `finance.reconcile` |
| `events_emitted` | Événements publiés | `supplier_invoice.recorded` |
| `events_consumed` | Événements écoutés | `payment.failed`, `booking.no_show` |
| `routes` | Écrans / navigation contribués | `/finance/treasury` |
| `settings` | Schéma de configuration par tenant | `{ currency, chart_of_accounts }` |
| `features` | Sous-fonctionnalités activables indépendamment | `no_show_recovery`, `quote_recovery` |

## Deux niveaux de test d'activation (SmartFlow)
Le core ne vérifie pas seulement `hasModule("finance")` mais aussi `hasFeature("bank_reconciliation")` :
un module peut être actif **sans** que toutes ses features le soient (upsell naturel).

## Forme cible (Symfony) — à implémenter (tâche C5)
```php
// app/src/Finance/FinanceModule.php
final class FinanceModule implements ModuleManifest
{
    public function id(): string { return 'finance'; }
    public function version(): string { return '0.1.0'; }
    public function capability(): string { return 'finance'; }
    public function dependencies(): array { return ['invoicing', 'accounting']; }
    public function permissions(): array { return ['finance.read', 'finance.write', 'finance.reconcile']; }
    public function eventsEmitted(): array { return ['supplier_invoice.recorded', 'payment.succeeded']; }
    public function eventsConsumed(): array { return ['payment.failed', 'invoice.overdue']; }
    public function features(): array { return ['bank_reconciliation', 'fec_export', 'invoice_ocr']; }
    // routes(), settingsSchema()...
}
```

## Règles
- Un module **déclare** ce qu'il écoute ; le registre câble l'abonnement — pas d'accès direct entre modules.
- Activer/désactiver une capacité ou une feature **n'entraîne aucune migration destructive** ; les
  données restent, seule l'exposition change.
- Le manifeste est la **seule** surface qu'un autre module (ou un autre Claude) doit lire pour intégrer.
- **Identifiants en anglais (D5)** — `id`, `capability`, permissions, événements et features. Les `id`
  des modules billetterie existants restent en français (`facturation`, `compta`) jusqu'au retrofit D5 ;
  l'exemple ci-dessus vise la cible (`invoicing`, `accounting`). Tout **nouveau** module est en anglais.
