<?php

declare(strict_types=1);

namespace App\Sepa\Validator;

use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Service\DebitPreNotifier;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipPeriodicity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Les deux côtés de la règle, dans UN SEUL endroit — et c'est délibéré.
 *
 * ⚠ La comparaison `délai > période` est **stricte**, et la dupliquer dans deux validateurs
 * garantirait qu'un jour l'un des deux devienne `>=` sans que l'autre bouge. Le contrôle qui
 * refuserait alors une configuration valide passerait tous ses tests de refus.
 */
final class NoticeDelayCoversPeriodValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoticeDelayCoversPeriod) {
            throw new UnexpectedTypeException($constraint, NoticeDelayCoversPeriod::class);
        }

        if ($value instanceof ConfigCreancierSepa) {
            $this->validerCreancier($value, $constraint);

            return;
        }

        if ($value instanceof Membership) {
            $this->validerAbonnement($value, $constraint);
        }
    }

    private function validerCreancier(ConfigCreancierSepa $config, NoticeDelayCoversPeriod $c): void
    {
        // D115 : un délai sous le minimum SEPA (14 j) exige une clause de préavis réduit déclarée sur le
        // créancier. Indépendant des abonnements du site — une config à 10 jours sans clause est invalide
        // même avant la première vente, donc AVANT la logique de période ci-dessous (qui, elle, sort tôt
        // quand le site n'a aucun abonnement).
        if ($config->getPreNotificationDelayDays() < DebitPreNotifier::DEFAULT_DELAY_DAYS
            && !$config->isPreavisReduitContractuel()) {
            $this->context->buildViolation($c->messagePreavisReduit)
                ->setParameter('{{ delai }}', (string) $config->getPreNotificationDelayDays())
                ->setParameter('{{ minimum }}', (string) DebitPreNotifier::DEFAULT_DELAY_DAYS)
                ->atPath('preNotificationDelayDays')
                ->addViolation();
        }

        $etablissement = $config->getEtablissement();
        if ($etablissement === null) {
            return;
        }

        $periode = $this->plusCourtePeriodeDuSite($etablissement->getId());
        if ($periode === null) {
            // ⚠ AUCUN ABONNEMENT : IL N'Y A RIEN À COUVRIR. Refuser ici interdirait de paramétrer un
            // créancier avant de vendre le premier abonnement — l'ordre naturel des choses.
            return;
        }

        $this->comparer($config->getPreNotificationDelayDays(), $periode, $c->messageCreditor, 'preNotificationDelayDays');
    }

    private function validerAbonnement(Membership $abonnement, NoticeDelayCoversPeriod $c): void
    {
        $etablissement = $abonnement->getEtablissement();
        if ($etablissement === null) {
            return;
        }

        $config = $this->em->getRepository(ConfigCreancierSepa::class)
            ->findOneBy(['etablissement' => $etablissement->getId()]);
        if ($config === null) {
            // Aucun créancier SEPA : cet abonnement ne se prélève pas, rien à vérifier.
            return;
        }

        $this->comparer($config->getPreNotificationDelayDays(), self::enJours($abonnement->getPeriodicite()), $c->messageSubscription, 'periodicite');
    }

    /**
     * ⚠ LE SEUL ENDROIT OÙ LA COMPARAISON EST ÉCRITE, ET ELLE EST STRICTE.
     *
     * `reasonNotCovered` refuse quand `sentAt > executionDate - délai` : un préavis envoyé
     * **exactement** `délai` jours avant est COUVERT. Un `>=` ici refuserait une configuration qui
     * marche, et tous ses tests de refus resteraient verts.
     */
    private function comparer(int $delai, int $periodeEnJours, string $message, string $chemin): void
    {
        if ($delai <= $periodeEnJours) {
            return;
        }

        $this->context->buildViolation($message)
            ->setParameter('{{ delai }}', (string) $delai)
            ->setParameter('{{ periode }}', (string) $periodeEnJours)
            ->atPath($chemin)
            ->addViolation();
    }

    /** La plus courte période d'abonnement du site, en jours, ou `null` s'il n'y en a aucun. */
    private function plusCourtePeriodeDuSite(mixed $etablissementId): ?int
    {
        /** @var list<Membership> $abonnements */
        $abonnements = $this->em->getRepository(Membership::class)
            ->findBy(['etablissement' => $etablissementId]);

        $plusCourte = null;
        foreach ($abonnements as $abonnement) {
            $jours = self::enJours($abonnement->getPeriodicite());
            if ($plusCourte === null || $jours < $plusCourte) {
                $plusCourte = $jours;
            }
        }

        return $plusCourte;
    }

    /**
     * ⚠ UN MOIS VAUT 28 JOURS ICI, PAS 30.
     *
     * Un mois dure de 28 à 31 jours. Prendre 30 laisserait passer un délai de 29, qui casserait en
     * février — un défaut d'un cycle par an, que personne ne relierait au paramétrage. On prend le
     * mois le plus court : ce qui passe ici passe toute l'année.
     */
    private static function enJours(MembershipPeriodicity $periodicite): int
    {
        return match ($periodicite) {
            MembershipPeriodicity::Hebdomadaire => 7,
            MembershipPeriodicity::Mensuel => 28,
            // 365, pas 366 : même raison que le 28 ci-dessus. On prend l'année la plus courte, pour
            // que ce qui passe ici passe aussi une année bissextile.
            MembershipPeriodicity::Annuel => 365,
        };
    }
}
