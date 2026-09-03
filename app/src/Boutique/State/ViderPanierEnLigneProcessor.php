<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /boutique/paniers/{id}/vider (§4.3 spec) : vide le panier avant paiement.
 *
 * @implements ProcessorInterface<mixed, PanierEnLigne>
 */
final class ViderPanierEnLigneProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierTarificationHandler $tarification,
        private readonly PanierProprietaireGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        foreach ($data->getLignes()->toArray() as $ligne) {
            $data->removeLigne($ligne);
            $this->em->remove($ligne);
        }
        $this->em->flush();

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($data);

        return $data;
    }
}
