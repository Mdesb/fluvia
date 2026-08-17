<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueEntity;
use App\Caution\Enum\ModeRetenue as ModeRetenueGenerique;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\ApiResource\GrilleRetenue;
use App\Patinoire\Enum\ModeRetenue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Création/modification de `App\Patinoire\ApiResource\GrilleRetenue` (fine délégation, refactor
 * caution générique) : persiste sur `App\Caution\Entity\GrilleRetenue` (cible `patinoire.patins`,
 * sous-cible l'UUID du `ParcPatins`).
 *
 * @implements ProcessorInterface<GrilleRetenue, GrilleRetenue>
 */
final class GrilleRetenueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GrilleRetenueProvider $provider,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GrilleRetenue
    {
        \assert($data instanceof GrilleRetenue);

        $id = $uriVariables['id'] ?? null;
        $entite = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(GrilleRetenueEntity::class)->find($id) : null;
        if ($id !== null && !$entite instanceof GrilleRetenueEntity) {
            throw new NotFoundHttpException('Grille de retenue introuvable.');
        }
        if (!$entite instanceof GrilleRetenueEntity) {
            $entite = new GrilleRetenueEntity();
            $entite->setTypeCible(GrilleRetenueProvider::TYPE_CIBLE);
        }

        if ($data->etablissement !== null) {
            $entite->setEtablissement($this->resoudreEtablissement($data->etablissement));
        }
        $entite->setMotif($data->motif?->value ?? '');
        $entite->setMode(ModeRetenueGenerique::from(($data->mode ?? ModeRetenue::Forfait)->value));
        $entite->setMontantCentimes(Caution::decimalVersCentimes($data->montantOuTaux));
        $entite->setSousCible($data->parcPatins !== null ? $this->uuidSegment($data->parcPatins) : null);
        $entite->setActif($data->actif);

        $this->em->persist($entite);
        $this->em->flush();

        return $this->provider->versDto($entite);
    }

    private function resoudreEtablissement(string $reference): Etablissement
    {
        $etablissement = Uuid::isValid($this->uuidSegment($reference)) ? $this->em->getRepository(Etablissement::class)->find($this->uuidSegment($reference)) : null;
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement introuvable.');
        }

        return $etablissement;
    }

    private function uuidSegment(string $reference): string
    {
        return str_contains($reference, '/') ? basename($reference) : $reference;
    }
}
