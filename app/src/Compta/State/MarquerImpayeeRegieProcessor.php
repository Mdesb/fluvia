<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/ventes/{id}/marquer-impayee-regie (RG-M6-09, §7.4 du plan). Corps : { "motif": string }
 *
 * @implements ProcessorInterface<mixed, VenteImpayeeRegie>
 */
final class MarquerImpayeeRegieProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VenteImpayeeRegie
    {
        // Le paramètre d'URI {id} référence une Vente (M2), pas la ressource VenteImpayeeRegie ;
        // API Platform peut le livrer déjà typé (Uuid) ou en chaîne selon le contexte de résolution.
        $venteId = $uriVariables['id'] ?? null;
        $uuid = match (true) {
            $venteId instanceof Uuid => $venteId,
            \is_string($venteId) && Uuid::isValid($venteId) => Uuid::fromString($venteId),
            default => null,
        };
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Identifiant de vente invalide.');
        }

        $existant = $this->em->getRepository(VenteImpayeeRegie::class)->findOneBy(['venteOrigine' => $uuid]);
        if ($existant !== null) {
            throw new ConflictHttpException('Cette vente est déjà marquée « ImpayeRegie ».');
        }

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : 'Recette de régie';

        $marquage = new VenteImpayeeRegie();
        $marquage->setVenteOrigine($uuid);
        $marquage->setMotif($motif);

        $this->em->persist($marquage);
        $this->em->flush();

        return $marquage;
    }
}
