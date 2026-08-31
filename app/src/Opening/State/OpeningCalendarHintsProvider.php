<?php

declare(strict_types=1);

namespace App\Opening\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Opening\ApiResource\OpeningCalendarHints;
use App\Opening\Entity\OpeningException;
use App\Opening\Entity\OpeningSetting;
use App\Opening\Enum\OpeningExceptionType;
use App\Opening\Port\SchoolHolidaysInterface;
use App\Opening\Service\FrenchPublicHolidays;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `GET /opening/calendar-hints?from=&to=` — ce que le calendrier sait sans qu'on le saisisse.
 *
 * ⚠ `alreadyClosed` EST LA MOITIÉ UTILE DE LA RÉPONSE. Sans lui, l'écran proposerait de fermer
 * Noël à un exploitant qui l'a déjà fermé l'an dernier — et une case cochée qui ne fait rien
 * apprend à ne plus lire les cases.
 *
 * @implements ProviderInterface<OpeningCalendarHints>
 */
final readonly class OpeningCalendarHintsProvider implements ProviderInterface
{
    /** Deux ans : de quoi préparer l'année suivante, pas de quoi balayer une décennie. */
    private const JOURS_MAX = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private ContexteEtablissement $contexte,
        private FrenchPublicHolidays $feries,
        private SchoolHolidaysInterface $vacances,
        private RequestStack $requetes,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OpeningCalendarHints
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Aucun établissement actif.');
        }

        $fuseau = new \DateTimeZone($etablissement->getFuseauHoraire());
        $requete = $this->requetes->getCurrentRequest();

        $du = $this->date($requete?->query->get('from'), $fuseau)
            ?? (new \DateTimeImmutable('now', $fuseau))->setDate((int) date('Y'), 1, 1)->setTime(0, 0);
        $au = $this->date($requete?->query->get('to'), $fuseau) ?? $du->modify('+1 year');

        if ($au < $du) {
            throw new UnprocessableEntityHttpException('La date de fin précède la date de début.');
        }
        if ($du->diff($au)->days > self::JOURS_MAX) {
            throw new UnprocessableEntityHttpException(sprintf('Intervalle trop large : %d jours au maximum.', self::JOURS_MAX));
        }

        $reglage = $this->em->getRepository(OpeningSetting::class)->findOneBy(['establishment' => $etablissement]);

        $hints = new OpeningCalendarHints();
        $hints->alsaceMoselle = $reglage?->isAlsaceMoselle() ?? false;
        $hints->schoolZone = $reglage?->getSchoolZone()?->value;

        $dejaFermes = $this->datesDejaFermees($etablissement, $du, $au);
        foreach ($this->feries->entre($du, $au, $hints->alsaceMoselle) as $ferie) {
            $hints->publicHolidays[] = [
                'date' => $ferie['date'],
                'label' => $ferie['nom'],
                'local' => $ferie['local'],
                'alreadyClosed' => \in_array($ferie['date'], $dejaFermes, true),
            ];
        }

        // Pas de zone choisie : on ne devine pas. Une zone inventée afficherait les vacances d'une
        // académie qui n'est pas celle du site, et personne ne saurait d'où elles sortent.
        $zone = $reglage?->getSchoolZone();
        if ($zone === null) {
            $hints->schoolHolidaysAvailable = true;
            $hints->schoolHolidaysReason = 'Choisissez une zone scolaire pour voir les vacances en fond de calendrier.';

            return $hints;
        }

        $reponse = $this->vacances->periods($zone, $du, $au);
        $hints->schoolHolidaysAvailable = $reponse['available'];
        $hints->schoolHolidays = $reponse['periods'];
        $hints->schoolHolidaysReason = $reponse['reason'] ?? null;

        return $hints;
    }

    /**
     * Les dates déjà couvertes par une fermeture journée entière.
     *
     * @return list<string>
     */
    private function datesDejaFermees(Etablissement $etablissement, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        /** @var list<OpeningException> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('e')
            ->from(OpeningException::class, 'e')
            ->andWhere('IDENTITY(e.establishment) = :etab')
            ->andWhere('e.date BETWEEN :du AND :au')
            ->andWhere('e.type = :type')
            // Type `'uuid'` explicite (D58) : sans lui la requête rend zéro ligne sans lever, et
            // TOUS les fériés seraient proposés comme non encore fermés.
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('du', $du->setTime(0, 0))
            ->setParameter('au', $au->setTime(0, 0))
            ->setParameter('type', OpeningExceptionType::Closure->value)
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($lignes as $ligne) {
            if ($ligne->isAllDay() && $ligne->getDate() !== null) {
                $dates[] = $ligne->getDate()->format('Y-m-d');
            }
        }

        return $dates;
    }

    private function date(mixed $brut, \DateTimeZone $fuseau): ?\DateTimeImmutable
    {
        if (!\is_string($brut) || trim($brut) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($brut), $fuseau);

        return $date === false ? null : $date;
    }
}
