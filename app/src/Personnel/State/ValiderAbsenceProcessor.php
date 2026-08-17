<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\Absence;
use App\Personnel\Enum\StatutAbsence;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Service\RecalculFenetreBadgeHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Valide/refuse une Absence (POST /personnel/absences/{id}/valider | /refuser, RG-PERSO-05, CA-7) :
 * une validation qui chevauche une AffectationTravail déjà confirmée lève une **alerte de
 * couverture** (pas d'annulation automatique, §4.6 spec) — exposée via `Absence.alerteCouverture`
 * (champ transitoire de la réponse).
 *
 * @implements ProcessorInterface<Absence, Absence>
 */
final class ValiderAbsenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RecalculFenetreBadgeHandler $recalculFenetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Absence
    {
        \assert($data instanceof Absence);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $uriTemplate = $operation instanceof HttpOperation ? $operation->getUriTemplate() : null;
        $refus = str_contains((string) $uriTemplate, 'refuser');

        $data->setValideePar($agent);

        if ($refus) {
            $data->setStatut(StatutAbsence::Refusee);
            $this->em->flush();

            return $data;
        }

        $data->setStatut(StatutAbsence::Validee);

        $employe = $data->getEmploye();
        if ($employe !== null) {
            $chevauchement = $this->em->getRepository(AffectationTravail::class)->createQueryBuilder('a')
                ->innerJoin('a.creneauTravail', 'c')
                ->andWhere('a.employe = :employe')
                ->andWhere('a.statut = :confirmee')
                ->andWhere('c.debut < :fin')
                ->andWhere('c.fin > :debut')
                ->setParameter('employe', $employe->getId(), 'uuid')
                ->setParameter('confirmee', StatutAffectationTravail::Confirmee->value)
                ->setParameter('debut', $data->getDebut(), 'datetime_immutable')
                ->setParameter('fin', $data->getFin(), 'datetime_immutable')
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            $data->setAlerteCouverture($chevauchement instanceof AffectationTravail);
        }

        $this->em->flush();

        if ($employe !== null) {
            $badges = $this->em->getRepository(\App\Personnel\Entity\BadgeStaff::class)->findBy(['employe' => $employe]);
            foreach ($badges as $badge) {
                $this->recalculFenetre->recalculer($badge);
            }
        }

        return $data;
    }
}
