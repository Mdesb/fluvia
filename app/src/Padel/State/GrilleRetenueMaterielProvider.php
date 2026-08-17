<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueEntity;
use App\Padel\ApiResource\GrilleRetenueMateriel;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture de `App\Padel\ApiResource\GrilleRetenueMateriel` (fine délégation, refactor caution
 * générique) : source unique `App\Caution\Entity\GrilleRetenue` filtrée sur la cible
 * `padel.materiel`. Cloisonnement (RG-SOCLE-05) reproduit manuellement (ressource non-Doctrine, hors
 * pipeline des extensions ORM `QueryCollectionExtensionInterface`).
 *
 * @implements ProviderInterface<GrilleRetenueMateriel>
 */
final class GrilleRetenueMaterielProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map($this->versDto(...), $this->grilles());
        }

        $id = $uriVariables['id'] ?? null;
        $grille = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(GrilleRetenueEntity::class)->find($id) : null;
        if (!$grille instanceof GrilleRetenueEntity || $grille->getTypeCible() !== LouerMaterielProcessor::TYPE_CIBLE) {
            throw new NotFoundHttpException('Grille de retenue introuvable.');
        }

        return $this->versDto($grille);
    }

    /** @return list<GrilleRetenueEntity> */
    private function grilles(): array
    {
        $qb = $this->em->getRepository(GrilleRetenueEntity::class)->createQueryBuilder('g')
            ->andWhere('g.typeCible = :type')
            ->setParameter('type', LouerMaterielProcessor::TYPE_CIBLE);

        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $qb->innerJoin(
                Affectation::class,
                'aff_padel_grille',
                Join::WITH,
                'IDENTITY(aff_padel_grille.etablissement) = IDENTITY(g.etablissement) AND IDENTITY(aff_padel_grille.utilisateur) = :aff_padel_grille_utilisateur',
            )->setParameter('aff_padel_grille_utilisateur', $utilisateur->getId(), 'uuid')->distinct();
        }

        /** @var list<GrilleRetenueEntity> $resultat */
        $resultat = $qb->getQuery()->getResult();

        return $resultat;
    }

    private function versDto(GrilleRetenueEntity $grille): GrilleRetenueMateriel
    {
        $dto = new GrilleRetenueMateriel();
        $dto->id = (string) $grille->getId();
        $dto->etablissement = '/api/etablissements/' . $grille->getEtablissement()?->getId();
        $dto->typeArticle = $grille->getSousCible() ?? '';
        $dto->motif = $grille->getMotif();
        $dto->montantRetenue = $grille->getMontantDecimal();

        return $dto;
    }
}
