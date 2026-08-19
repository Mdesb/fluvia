<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Personnel\ApiResource\DeclarationIncidentBadge;
use App\Personnel\Entity\BadgeStaff;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /personnel/declarations-incident[/{id}] (décision n°8 du plan) : lit
 * `App\Acces\Entity\DeclarationPerteVol` filtrée sur les `Support` des `BadgeStaff` — vue, aucune
 * table propre. Requête EM brute (aucune `ApiFilter`/extension Doctrine sur cette vue) : le
 * cloisonnement (RG-SOCLE-05) est donc appliqué ici même, borné aux établissements où l'utilisateur
 * possède effectivement une `Affectation` — un badge d'un autre établissement n'est jamais visible.
 *
 * @implements ProviderInterface<DeclarationIncidentBadge>
 */
final class DeclarationIncidentBadgeProvider implements ProviderInterface
{
    private const UUID_IMPOSSIBLE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeclarationIncidentBadge|array
    {
        $autorises = $this->etablissementsAutorises();

        $id = $uriVariables['id'] ?? null;
        if (\is_string($id) && Uuid::isValid($id)) {
            $declaration = $this->em->getRepository(DeclarationPerteVol::class)->find($id);
            if (!$declaration instanceof DeclarationPerteVol) {
                throw new NotFoundHttpException('Déclaration introuvable.');
            }

            $badge = $this->em->getRepository(BadgeStaff::class)->findOneBy(['support' => $declaration->getSupport()]);
            $etablissementBadge = $badge instanceof BadgeStaff ? $badge->getEtablissement() : null;
            if ($etablissementBadge === null || !\in_array($etablissementBadge->getId()->toBinary(), $autorises, true)) {
                throw new NotFoundHttpException('Déclaration introuvable.');
            }

            return $this->versVue($declaration);
        }

        /** @var list<DeclarationPerteVol> $declarations */
        $declarations = $this->em->getRepository(DeclarationPerteVol::class)->createQueryBuilder('d')
            ->innerJoin(BadgeStaff::class, 'b', 'WITH', 'b.support = d.support')
            ->andWhere('b.etablissement IN (:etablissementsAutorises)')
            ->setParameter('etablissementsAutorises', $autorises, ArrayParameterType::BINARY)
            ->getQuery()->getResult();

        return array_map($this->versVue(...), $declarations);
    }

    /**
     * @return list<string>
     */
    private function etablissementsAutorises(): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return [Uuid::fromString(self::UUID_IMPOSSIBLE)->toBinary()];
        }

        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy(['utilisateur' => $utilisateur]);

        $ids = [];
        foreach ($affectations as $affectation) {
            $etablissement = $affectation->getEtablissement();
            if ($etablissement !== null) {
                $ids[(string) $etablissement->getId()] = $etablissement->getId()->toBinary();
            }
        }

        return $ids === [] ? [Uuid::fromString(self::UUID_IMPOSSIBLE)->toBinary()] : array_values($ids);
    }

    private function versVue(DeclarationPerteVol $declaration): DeclarationIncidentBadge
    {
        $badge = $this->em->getRepository(BadgeStaff::class)->findOneBy(['support' => $declaration->getSupport()]);

        $vue = new DeclarationIncidentBadge();
        $vue->id = (string) $declaration->getId();
        $vue->badgeStaff = $badge instanceof BadgeStaff ? (string) $badge->getId() : '';
        $vue->motif = $declaration->getMotif();
        $vue->agent = (string) $declaration->getAgent()?->getId();
        $vue->horodatage = $declaration->getHorodatage()->format(DATE_ATOM);
        $vue->annulee = $declaration->isAnnulee();

        return $vue;
    }
}
