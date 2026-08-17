<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueEntity;
use App\Organisation\Entity\Etablissement;
use App\Padel\ApiResource\GrilleRetenueMateriel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Création/modification de `App\Padel\ApiResource\GrilleRetenueMateriel` (fine délégation, refactor
 * caution générique) : persiste sur `App\Caution\Entity\GrilleRetenue` (cible `padel.materiel`).
 *
 * @implements ProcessorInterface<GrilleRetenueMateriel, GrilleRetenueMateriel>
 */
final class GrilleRetenueMaterielProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GrilleRetenueMateriel
    {
        \assert($data instanceof GrilleRetenueMateriel);

        $id = $uriVariables['id'] ?? null;
        $entite = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(GrilleRetenueEntity::class)->find($id) : null;
        if ($id !== null && !$entite instanceof GrilleRetenueEntity) {
            throw new NotFoundHttpException('Grille de retenue introuvable.');
        }
        if (!$entite instanceof GrilleRetenueEntity) {
            $entite = new GrilleRetenueEntity();
            $entite->setTypeCible(LouerMaterielProcessor::TYPE_CIBLE);
        }

        if ($data->etablissement !== null) {
            $etablissement = $this->resoudreEtablissement($data->etablissement);
            $entite->setEtablissement($etablissement);
        }
        $entite->setSousCible($data->typeArticle !== '' ? $data->typeArticle : null);
        $entite->setMotif($data->motif);
        $entite->setMontantCentimes(Caution::decimalVersCentimes($data->montantRetenue));

        $this->em->persist($entite);
        $this->em->flush();

        $dto = new GrilleRetenueMateriel();
        $dto->id = (string) $entite->getId();
        $dto->etablissement = '/api/etablissements/' . $entite->getEtablissement()?->getId();
        $dto->typeArticle = $entite->getSousCible() ?? '';
        $dto->motif = $entite->getMotif();
        $dto->montantRetenue = $entite->getMontantDecimal();

        return $dto;
    }

    private function resoudreEtablissement(string $reference): Etablissement
    {
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        $etablissement = Uuid::isValid($segment) ? $this->em->getRepository(Etablissement::class)->find($segment) : null;
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement introuvable.');
        }

        return $etablissement;
    }
}
