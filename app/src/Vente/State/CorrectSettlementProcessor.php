<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\SettlementCorrection;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\SettlementCorrectionHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `POST /ventes/{id}/corriger-reglement` (D45).
 *
 * Le droit `vente.corriger_reglement` est **distinct** de `caisse.gerer`, et c'est délibéré : qui
 * peut déplacer des espèces vers la carte peut masquer un manquant. Le geste est donc réservé et
 * tracé — motif obligatoire, auteur signé, écriture scellée dans la chaîne.
 *
 * @implements ProcessorInterface<Vente, SettlementCorrection>
 */
final class CorrectSettlementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly SettlementCorrectionHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SettlementCorrection
    {
        \assert($data instanceof Vente);

        $utilisateur = $this->security->getUser();
        $correction = $this->handler->corriger(
            $data,
            $this->lecteur->corps(),
            $utilisateur instanceof Utilisateur ? $utilisateur : null,
        );
        $this->em->flush();

        return $correction;
    }
}
