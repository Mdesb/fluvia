<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Exonération manuelle d'un no-show (CA-12, `reservation.exonerer`) : toujours tracée (auteur,
 * motif, RG-SOCLE-07). Aucun encaissement.
 *
 * @implements ProcessorInterface<mixed, FacturationNoShow>
 */
final class ExonererProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FacturationNoShow
    {
        \assert($data instanceof FacturationNoShow);

        if ($data->getStatut() !== StatutFacturationNoShow::AFacturer) {
            throw new ConflictHttpException('Seule une facturation « à facturer » peut être exonérée.');
        }

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? trim((string) $corps['motif']) : '';
        if ($motif === '') {
            throw new UnprocessableEntityHttpException('Le motif d\'exonération est obligatoire (traçabilité, RG-SOCLE-07).');
        }

        $utilisateur = $this->security->getUser();
        $data->setStatut(StatutFacturationNoShow::Exoneree);
        $data->setMotifExoneration($motif);
        if ($utilisateur instanceof Utilisateur) {
            $data->setExonerePar($utilisateur);
        }

        $this->em->flush();

        return $data;
    }
}
