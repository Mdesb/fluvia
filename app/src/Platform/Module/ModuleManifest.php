<?php

declare(strict_types=1);

namespace App\Platform\Module;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Fiche d'identité d'un module (CONTRACT/manifeste-module.md). C'est la **seule** surface qu'un autre
 * module — ou un autre Claude — doit lire pour intégrer : ce que le module expose, écoute, exige.
 *
 * Le tag est porté par l'interface : toute implémentation est enregistrée au registre sans une ligne de
 * configuration. Ajouter un module ne demande donc jamais de modifier un fichier partagé — ce qui évite
 * le conflit de merge systématique entre instances qui travaillent en parallèle (PLAYBOOK §8).
 *
 * **Contrainte d'instanciation :** un manifeste est une déclaration, pas un service métier — il doit être
 * constructible sans argument. `ManifestCatalogueTest` le vérifie, parce qu'un manifeste qui dépendrait
 * de la base ou d'une requête ne pourrait plus être lu au démarrage ni en ligne de commande.
 *
 * Identifiants **en anglais** (D5) : `capability`, permissions (`finance.read`), événements
 * (`supplier_invoice.recorded`), features (`bank_reconciliation`). Seuls les `id` des modules
 * billetterie historiques restent en français jusqu'au retrofit D5.
 */
#[AutoconfigureTag('platform.module')]
interface ModuleManifest
{
    /** Identifiant unique et stable du module (ex. « finance »). */
    public function id(): string;

    /** Version SemVer du module (ex. « 0.1.0 »). */
    public function version(): string;

    /**
     * Capacité activable qui porte le module — code du catalogue `App\Fonctionnalite`.
     *
     * **`null` désigne un service transverse** : une brique partagée que les autres modules consomment
     * en PHP (OCR, GED, signature, i18n), qui n'est ni vendue ni activable par établissement.
     *
     * La distinction n'est pas cosmétique. Renvoyer un code inventé pour « satisfaire le type » créerait
     * une capacité absente du catalogue : `ModuleAccess::hasModule()` répondrait toujours `false` et le
     * catalogue d'offres refuserait de la vendre — le module serait présent et inaccessible, sans que
     * rien ne le signale.
     */
    public function capability(): ?string;

    /**
     * `id` des modules requis. Le registre refuse de démarrer si l'un d'eux est inconnu (RG-PLAT-07).
     *
     * @return list<string>
     */
    public function dependencies(): array;

    /**
     * Permissions exposées, au format `module.action` (ex. « finance.read »).
     *
     * @return list<string>
     */
    public function permissions(): array;

    /**
     * Événements publiés. Chacun doit figurer au CONTRACT/catalogue-evenements.md (RG-PLAT-06).
     *
     * @return list<string>
     */
    public function eventsEmitted(): array;

    /**
     * Événements écoutés. Déclarer ici ce qu'on écoute rend le graphe des réactions lisible sans
     * parcourir le code — c'est ce qui permet de juger l'impact d'un changement d'événement.
     *
     * @return list<string>
     */
    public function eventsConsumed(): array;

    /**
     * Sous-fonctionnalités activables indépendamment du module (ex. « bank_reconciliation »).
     *
     * @return list<string>
     */
    public function features(): array;

    /**
     * Écrans / points de navigation contribués (ex. « /finance/treasury »).
     *
     * @return list<string>
     */
    public function routes(): array;

    /**
     * Schéma de configuration par tenant, sous forme de clés attendues (ex. « currency »).
     *
     * @return array<string, mixed>
     */
    public function settingsSchema(): array;
}
