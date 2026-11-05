<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Nf525\Entity\FiscalArchive;
use App\Vente\Nf525\FiscalArchiveBuilder;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /fiscal_archives` — archive l'année `{ "year": AAAA }` (par défaut l'année précédente) de
 * l'établissement actif (#342, #343).
 *
 * D41 — l'établissement vient de la session, jamais du corps : on n'archive pas l'année d'un autre.
 *
 * @implements ProcessorInterface<mixed, FiscalArchive>
 */
final class CreateFiscalArchiveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly FiscalArchiveBuilder $builder,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $securite,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FiscalArchive
    {
        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Aucun établissement actif : impossible d\'archiver (D41).');
        }

        $brut = $this->lecteur->corps()['year'] ?? null;
        if ($brut === null) {
            $annee = (int) (new \DateTimeImmutable('first day of january last year'))->format('Y');
        } elseif (\is_int($brut) || (\is_string($brut) && preg_match('/^\d{4}$/', $brut) === 1)) {
            $annee = (int) $brut;
        } else {
            throw new UnprocessableEntityHttpException('Année attendue au format AAAA.');
        }
        if ($annee < 2000 || $annee > 2999) {
            throw new UnprocessableEntityHttpException('Année attendue au format AAAA.');
        }

        $auteur = $this->securite->getUser();

        return $this->builder->build($etablissement, $annee, $auteur instanceof Utilisateur ? $auteur : null);
    }
}
