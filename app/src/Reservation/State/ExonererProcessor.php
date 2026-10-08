<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Service\NoShowBillingLock;
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
        private readonly NoShowBillingLock $verrou,
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
        // Le statut lu plus haut l'a été avant : un débit en cours peut le changer. Relu sous le
        // verrou, il dit l'issue de ce débit, et l'exonération ne l'écrase jamais (lot 4).
        $this->em->getConnection()->transactional(function () use ($data, $motif, $utilisateur): void {
            if ($this->verrou->lock($data) !== StatutFacturationNoShow::AFacturer) {
                throw new ConflictHttpException('Seule une facturation « à facturer » peut être exonérée : un autre geste vient de la traiter.');
            }
            $data->setStatut(StatutFacturationNoShow::Exoneree);
            $data->setMotifExoneration($motif);
            if ($utilisateur instanceof Utilisateur) {
                $data->setExonerePar($utilisateur);
            }

            $this->em->flush();
        });

        return $data;
    }
}
