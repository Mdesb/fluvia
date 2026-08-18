<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ReglementFacture;
use App\Facturation\Service\ReglementFactureHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /factures/{id}/reglements (US-FACT-04, RG-FACT-06, CA-5). Corps :
 *   { "montant": "150.00", "moyen": "virement", "reference"?: "…" }
 *
 * @implements ProcessorInterface<Facture, ReglementFacture>
 */
final class EnregistrerReglementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ReglementFactureHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReglementFacture
    {
        \assert($data instanceof Facture);

        $corps = $this->lecteur->corps();
        $montant = $corps['montant'] ?? null;
        if (!\is_string($montant) && !\is_numeric($montant)) {
            throw new UnprocessableEntityHttpException('Montant du règlement obligatoire.');
        }
        $moyen = \is_string($corps['moyen'] ?? null) ? $corps['moyen'] : null;
        if ($moyen === null || $moyen === '') {
            throw new UnprocessableEntityHttpException('Moyen de règlement obligatoire.');
        }
        $reference = \is_string($corps['reference'] ?? null) ? $corps['reference'] : null;

        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        return $this->handler->enregistrer($data, (string) $montant, $moyen, $reference, $auteur);
    }
}
