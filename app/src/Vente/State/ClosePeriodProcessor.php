<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\PointDeVente;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Nf525\Entity\PeriodClosure;
use App\Vente\Nf525\PeriodClosureHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Clôture mensuelle (`{ "month": "AAAA-MM" }`, par défaut le mois précédent) ou annuelle
 * (`{ "year": AAAA }`, par défaut l'année précédente) d'un point de vente (#342).
 *
 * Par défaut la période **précédente**, pour la même raison que l'arrêté de journée prend la veille :
 * le défaut ne peut pas clore une période en cours.
 *
 * @implements ProcessorInterface<PointDeVente, PeriodClosure>
 */
final class ClosePeriodProcessor implements ProcessorInterface
{
    public const ANNUAL_URI_SUFFIX = '/annual-closure';

    public function __construct(
        private readonly PeriodClosureHandler $handler,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $securite,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PeriodClosure
    {
        if (!$data instanceof PointDeVente) {
            throw new NotFoundHttpException('Point de vente introuvable.');
        }

        // D8 — contrôle sur l'entité résolue, pas sur l'en-tête ; 404 anti-oracle (comme CloseDayProcessor).
        $actif = $this->contexte->etablissementActif();
        if ($actif === null || (string) $data->getEtablissement()?->getId() !== (string) $actif->getId()) {
            throw new NotFoundHttpException('Point de vente introuvable.');
        }

        $corps = $this->lecteur->corps();
        $auteur = $this->securite->getUser();
        $auteur = $auteur instanceof Utilisateur ? $auteur : null;

        if (str_ends_with((string) $operation->getUriTemplate(), self::ANNUAL_URI_SUFFIX)) {
            return $this->handler->closeYear($data, $this->annee($corps['year'] ?? null), $auteur);
        }

        [$annee, $mois] = $this->mois($corps['month'] ?? null);

        return $this->handler->closeMonth($data, $annee, $mois, $auteur);
    }

    private function annee(mixed $brut): int
    {
        if ($brut === null) {
            return (int) (new \DateTimeImmutable('first day of january last year'))->format('Y');
        }
        if (\is_string($brut) && preg_match('/^\d{4}$/', $brut) === 1) {
            $brut = (int) $brut;
        }
        if (!\is_int($brut) || $brut < 2000 || $brut > 2999) {
            throw new UnprocessableEntityHttpException('Année attendue au format AAAA.');
        }

        return $brut;
    }

    /** @return array{0: int, 1: int} */
    private function mois(mixed $brut): array
    {
        if ($brut === null) {
            $precedent = new \DateTimeImmutable('first day of last month');

            return [(int) $precedent->format('Y'), (int) $precedent->format('n')];
        }
        // Refuser plutôt qu'interpréter : « 2026-13 » ne doit pas devenir janvier 2027.
        if (!\is_string($brut) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $brut, $m) !== 1) {
            throw new UnprocessableEntityHttpException('Mois attendu au format AAAA-MM.');
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
