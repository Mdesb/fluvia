<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Personnel\ApiResource\DeclarationIncidentBadge;
use App\Personnel\Entity\BadgeStaff;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /personnel/declarations-incident[/{id}] (décision n°8 du plan) : lit
 * `App\Acces\Entity\DeclarationPerteVol` filtrée sur les `Support` des `BadgeStaff` — vue, aucune
 * table propre.
 *
 * @implements ProviderInterface<DeclarationIncidentBadge>
 */
final class DeclarationIncidentBadgeProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeclarationIncidentBadge|array
    {
        $id = $uriVariables['id'] ?? null;
        if (\is_string($id) && Uuid::isValid($id)) {
            $declaration = $this->em->getRepository(DeclarationPerteVol::class)->find($id);
            if (!$declaration instanceof DeclarationPerteVol) {
                throw new NotFoundHttpException('Déclaration introuvable.');
            }

            return $this->versVue($declaration);
        }

        /** @var list<DeclarationPerteVol> $declarations */
        $declarations = $this->em->getRepository(DeclarationPerteVol::class)->createQueryBuilder('d')
            ->innerJoin(BadgeStaff::class, 'b', 'WITH', 'b.support = d.support')
            ->getQuery()->getResult();

        return array_map($this->versVue(...), $declarations);
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
