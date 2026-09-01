<?php

declare(strict_types=1);

namespace App\Import;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Import` — la reprise initiale d'un client (T2).
 *
 * **`capability()` rend `null`, et ce n'est pas un contournement.** Un service transverse est une
 * brique que les autres consomment sans qu'elle soit vendue ni activable par établissement. La
 * reprise en est une : elle sert **une fois**, à l'accueil d'un client, et personne n'achète un
 * outil d'import — il vient avec le fait de signer. Lui inventer un code de capacité absent du
 * catalogue serait pire que de n'en pas mettre : `ModuleAccess::hasModule()` répondrait toujours
 * faux, et le module serait présent et définitivement inaccessible sans que rien ne le signale.
 *
 * **`eventsEmitted()` est vide, et c'est provisoire.** Un lot appliqué mériterait de publier un
 * fait — la reprise est le moment où un module découvre des clients qu'il n'a pas vu créer. Mais
 * aucun nom n'existe au `CONTRACT/catalogue-evenements.md`, et RG-PLAT-06 refuse à la poussée tout
 * événement hors catalogue. Le catalogue appartient à `claude-A` ; déclarer ici des noms qu'il n'a
 * pas arbitrés inverserait la règle contract-first de D2. La demande est posée dans
 * `RAPPORTS/claude-I.md`.
 *
 * **`routes()` est vide par conception (D13).** La spécification laisse ouvert « écran de reprise ou
 * ligne de commande d'abord » ; un écran posé sur un mécanisme qui n'a jamais tourné se refait.
 */
final class ImportModule implements ModuleManifest
{
    public function id(): string
    {
        return 'import';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     *
     * Vide alors que la reprise écrit dans `Crm` : le registre refuse de démarrer si une dépendance
     * nomme un module inconnu (RG-PLAT-07), et `App\Crm` n'a pas de manifeste à ce jour. Déclarer le
     * lien empêcherait la plateforme entière de démarrer pour documenter ce que le typage exprime
     * déjà — même prudence que `StayModule`.
     */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'import.read',
            'import.manage',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
