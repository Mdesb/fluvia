<?php

declare(strict_types=1);

namespace App\Calendar\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Calendar\ApiResource\CalendarFeed;
use App\Calendar\Service\CalendarAggregator;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<CalendarFeed>
 */
final readonly class CalendarFeedProvider implements ProviderInterface
{
    /**
     * Une vue mois demande 31 jours ; on tolère un trimestre pour un export. Au-delà, ce n'est plus
     * un agenda, et une borne haute évite qu'un paramètre malheureux ne balaie cinq ans de créneaux.
     */
    private const JOURS_MAX = 92;

    public function __construct(
        private ContexteEtablissement $contexte,
        private Security $security,
        private CalendarAggregator $agregateur,
        private RequestStack $requetes,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CalendarFeed
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Aucun établissement actif.');
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Aucun utilisateur authentifié.');
        }

        $requete = $this->requetes->getCurrentRequest();
        $fuseau = new \DateTimeZone($etablissement->getFuseauHoraire());

        $du = $this->date($requete?->query->get('du'), $fuseau)
            ?? (new \DateTimeImmutable('now', $fuseau))->modify('monday this week')->setTime(0, 0);
        $au = $this->date($requete?->query->get('au'), $fuseau) ?? $du->modify('+7 days');
        // La borne haute est EXCLUSIVE côté agrégateur ; on la pousse à la fin du jour demandé pour
        // que « du 1er au 7 » contienne bien le 7, comme le lit n'importe quel humain.
        $au = $au->setTime(0, 0)->modify('+1 day');

        if ($au <= $du) {
            throw new UnprocessableEntityHttpException('La date de fin précède la date de début.');
        }
        if ($du->diff($au)->days > self::JOURS_MAX) {
            throw new UnprocessableEntityHttpException(sprintf('Intervalle trop large : %d jours au maximum.', self::JOURS_MAX));
        }

        // Toute valeur inconnue retombe sur « site ». On ne lève pas : un paramètre mal orthographié
        // dans une URL ne doit pas produire un écran en erreur, il doit produire l'écran par défaut.
        $portee = $requete?->query->get('scope') === 'mine' ? 'mine' : 'site';

        $journal = new CalendarFeed();
        $journal->scope = $portee;
        $journal->timezone = $etablissement->getFuseauHoraire();
        $journal->events = $this->agregateur->evenements($etablissement, $utilisateur, $du, $au, $portee);

        return $journal;
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
