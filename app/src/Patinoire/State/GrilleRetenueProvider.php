<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Caution\Entity\GrilleRetenue as GrilleRetenueEntity;
use App\Patinoire\ApiResource\GrilleRetenue;
use App\Patinoire\Enum\ModeRetenue;
use App\Patinoire\Enum\MotifRetenue;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture de `App\Patinoire\ApiResource\GrilleRetenue` (fine délégation, refactor caution générique) :
 * source unique `App\Caution\Entity\GrilleRetenue` filtrée sur la cible `patinoire.patins`.
 * Cloisonnement (RG-SOCLE-05) reproduit manuellement (ressource non-Doctrine).
 *
 * @implements ProviderInterface<GrilleRetenue>
 */
final class GrilleRetenueProvider implements ProviderInterface
{
    public const TYPE_CIBLE = 'patinoire.patins';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map($this->versDto(...), $this->grilles());
        }

        $id = $uriVariables['id'] ?? null;
        $grille = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(GrilleRetenueEntity::class)->find($id) : null;
        if (!$grille instanceof GrilleRetenueEntity || $grille->getTypeCible() !== self::TYPE_CIBLE) {
            throw new NotFoundHttpException('Grille de retenue introuvable.');
        }

        return $this->versDto($grille);
    }

    /** @return list<GrilleRetenueEntity> */
    private function grilles(): array
    {
        $qb = $this->em->getRepository(GrilleRetenueEntity::class)->createQueryBuilder('g')
            ->andWhere('g.typeCible = :type')
            ->setParameter('type', self::TYPE_CIBLE);

        // Meme correction que les alertes de stock : l'axe passe a l'etablissement actif, et le
        // filtre cesse d'etre facultatif. Sans utilisateur ou sans etablissement actif, la grille
        // rendait celles de tous les sites.
        $utilisateur = $this->security->getUser();
        $actif = $this->contexte->idActif();
        if (!$utilisateur instanceof Utilisateur || $actif === null) {
            return [];
        }

        $qb->andWhere('IDENTITY(g.etablissement) = :patinoire_grille_actif')
            ->setParameter('patinoire_grille_actif', $actif, 'uuid')
            ->distinct();

        /** @var list<GrilleRetenueEntity> $resultat */
        $resultat = $qb->getQuery()->getResult();

        return $resultat;
    }

    public function versDto(GrilleRetenueEntity $grille): GrilleRetenue
    {
        $dto = new GrilleRetenue();
        $dto->id = (string) $grille->getId();
        $dto->etablissement = '/api/etablissements/' . $grille->getEtablissement()?->getId();
        $dto->motif = MotifRetenue::tryFrom($grille->getMotif());
        $dto->mode = ModeRetenue::tryFrom($grille->getMode()->value) ?? ModeRetenue::Forfait;
        $dto->montantOuTaux = $grille->getMontantDecimal();
        $dto->parcPatins = $grille->getSousCible() !== null ? '/api/patinoire_parc_patins/' . $grille->getSousCible() : null;
        $dto->actif = $grille->isActif();

        return $dto;
    }
}
