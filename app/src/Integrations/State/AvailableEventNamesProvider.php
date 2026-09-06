<?php

declare(strict_types=1);

namespace App\Integrations\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Integrations\ApiResource\AvailableEventName;
use App\Platform\Module\ModuleRegistry;

/**
 * L'union de ce que les manifestes déclarent émettre.
 *
 * ⚠ **ON NE SERT QUE `eventsEmitted()`, PAS `eventsConsumed()`.** Un événement qu'un module CONSOMME
 * n'est pas forcément émis quelque part : proposer de s'abonner à un fait que personne ne produit
 * donnerait une destination silencieuse et une explication introuvable.
 *
 * ⚠ **UNE LISTE VIDE EST UNE RÉPONSE, PAS UNE PANNE**, et l'écran doit la distinguer d'un échec de
 * lecture. Si aucun manifeste ne déclare d'émission — c'est le cas de `social`, délibérément —
 * il n'y a rien à proposer, et le dire vaut mieux qu'afficher une liste qu'on aurait inventée.
 *
 * @implements ProviderInterface<AvailableEventName>
 */
final class AvailableEventNamesProvider implements ProviderInterface
{
    public function __construct(
        private readonly ModuleRegistry $registre,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<AvailableEventName>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        /** @var array<string, AvailableEventName> $parNom */
        $parNom = [];

        foreach ($this->registre->all() as $manifeste) {
            foreach ($manifeste->eventsEmitted() as $nom) {
                if (!\is_string($nom) || $nom === '') {
                    continue;
                }
                // Le premier module déclarant l'emporte : un même fait peut être émis par deux
                // modules, et afficher deux lignes identiques n'aiderait personne à choisir.
                if (isset($parNom[$nom])) {
                    continue;
                }

                $ressource = new AvailableEventName();
                $ressource->nom = $nom;
                $ressource->domaine = str_contains($nom, '.') ? explode('.', $nom, 2)[0] : $nom;
                $ressource->module = $manifeste->id();
                $parNom[$nom] = $ressource;
            }
        }

        ksort($parNom);

        return array_values($parNom);
    }
}
