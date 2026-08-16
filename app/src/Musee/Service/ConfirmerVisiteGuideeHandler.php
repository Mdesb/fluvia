<?php

declare(strict_types=1);

namespace App\Musee\Service;

use App\Musee\Entity\QualificationLangueGuide;
use App\Musee\Entity\VisiteGuidee;
use App\Musee\Enum\StatutVisiteGuidee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Confirmation d'une visite guidée (RG-MUS-02, CA-3/CA-4) : exige un `guide` affecté **et** une
 * `QualificationLangueGuide` valide dans la langue de la visite, sinon refus explicite « aucun guide
 * qualifié dans cette langue » — déclenche côté client la proposition liste d'attente/bascule
 * audioguide (§4.4).
 */
final class ConfirmerVisiteGuideeHandler
{
    public const MESSAGE_AUCUN_GUIDE_QUALIFIE = 'Aucun guide qualifié dans cette langue n\'est disponible sur ce créneau (RG-MUS-02).';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function confirmer(VisiteGuidee $visite): VisiteGuidee
    {
        $guide = $visite->getGuide();
        if ($guide === null) {
            throw new UnprocessableEntityHttpException(self::MESSAGE_AUCUN_GUIDE_QUALIFIE);
        }

        $qualification = $this->em->getRepository(QualificationLangueGuide::class)->findOneBy([
            'guide' => $guide,
            'langue' => $visite->getLangue(),
        ]);
        if ($qualification === null) {
            throw new UnprocessableEntityHttpException(self::MESSAGE_AUCUN_GUIDE_QUALIFIE);
        }

        $visite->setStatut(StatutVisiteGuidee::Confirmee);
        $this->em->flush();

        return $visite;
    }
}
