<?php

declare(strict_types=1);

namespace App\Opening\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Opening\ApiResource\OpeningSchedule;
use App\Opening\Entity\OpeningException;
use App\Opening\Service\OpeningCalendar;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /ouverture/planning?du=&au=&espace=` — les fenêtres réelles, calculées par
 * `OpeningCalendar` et par personne d'autre.
 *
 * @implements ProviderInterface<OpeningSchedule>
 */
final readonly class OpeningScheduleProvider implements ProviderInterface
{
    /** Un agenda demande une semaine ou un mois. Au-delà, c'est un export, pas un écran. */
    private const JOURS_MAX = 92;

    public function __construct(
        private EntityManagerInterface $em,
        private ContexteEtablissement $contexte,
        private OpeningCalendar $calendrier,
        private RequestStack $requetes,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OpeningSchedule
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Aucun établissement actif.');
        }

        $requete = $this->requetes->getCurrentRequest();
        $fuseau = new \DateTimeZone($etablissement->getFuseauHoraire());

        $du = $this->date($requete?->query->get('du'), $fuseau)
            ?? (new \DateTimeImmutable('now', $fuseau))->modify('monday this week')->setTime(0, 0);
        $au = $this->date($requete?->query->get('au'), $fuseau) ?? $du->modify('+6 days');

        if ($au < $du) {
            throw new UnprocessableEntityHttpException('La date de fin précède la date de début.');
        }
        if ($du->diff($au)->days > self::JOURS_MAX) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Intervalle trop large : %d jours au maximum.',
                self::JOURS_MAX,
            ));
        }

        $espace = $this->espace($requete?->query->get('espace'), $etablissement);

        $resolu = new OpeningSchedule();
        $resolu->enforced = $this->calendrier->isEnforced($etablissement);
        $resolu->timezone = $etablissement->getFuseauHoraire();

        foreach ($this->calendrier->fenetres($etablissement, $du, $au, $espace) as $fenetre) {
            $resolu->windows[] = [
                'day' => $fenetre['day'],
                // ATOM et non 'H:i' : une fenêtre qui traverse minuit finit un AUTRE jour, et une
                // heure nue ne sait pas le dire. L'écran reçoit des instants, pas des étiquettes.
                'start' => $fenetre['start']->format(\DateTimeInterface::ATOM),
                'end' => $fenetre['end']->format(\DateTimeInterface::ATOM),
                'label' => $fenetre['label'],
            ];
        }

        foreach ($this->exceptions($etablissement, $du, $au) as $exception) {
            $resolu->exceptions[] = [
                'date' => $exception->getDate()?->format('Y-m-d') ?? '',
                'type' => $exception->getType()->value,
                'reason' => $exception->getReason(),
                'allDay' => $exception->isAllDay(),
            ];
        }

        return $resolu;
    }

    private function date(mixed $brut, \DateTimeZone $fuseau): ?\DateTimeImmutable
    {
        if (!\is_string($brut) || trim($brut) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($brut), $fuseau);

        return $date === false ? null : $date;
    }

    /**
     * ⚠ L'espace arrive du client : il est RECONFRONTÉ à l'établissement actif, jamais résolu seul.
     * Les extensions Doctrine ne filtrent pas les résolutions faites à la main dans un provider
     * (D8) ; sans cette vérification, on lirait le planning d'un espace du voisin.
     */
    private function espace(mixed $brut, Etablissement $etablissement): ?EspaceAcces
    {
        if (!\is_string($brut) || trim($brut) === '' || !Uuid::isValid(trim($brut))) {
            return null;
        }

        $espace = $this->em->getRepository(EspaceAcces::class)->find(Uuid::fromString(trim($brut)));
        if (!$espace instanceof EspaceAcces) {
            return null;
        }

        return $espace->getEtablissement()?->getId()?->equals($etablissement->getId()) === true ? $espace : null;
    }

    /**
     * @return list<OpeningException>
     */
    private function exceptions(Etablissement $etablissement, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        /** @var list<OpeningException> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('e')
            ->from(OpeningException::class, 'e')
            // `IDENTITY(...)` + type `'uuid'` explicite (D58). Sans le troisieme argument, Doctrine
            // lie l'identifiant sans son type : la requete rend zero ligne, sans exception ni
            // avertissement. Le 28/08, deux modules en sont morts en silence — un solde de fidelite
            // qui ne bougeait jamais, et une file d'attente qui donnait le rang 1 a tout le monde.
            ->andWhere('IDENTITY(e.establishment) = :etab')
            ->andWhere('e.date BETWEEN :du AND :au')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('du', $du->setTime(0, 0))
            ->setParameter('au', $au->setTime(0, 0))
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();

        return $lignes;
    }
}
