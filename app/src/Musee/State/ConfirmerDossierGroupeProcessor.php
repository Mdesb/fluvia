<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Musee\Entity\ContingentGratuite;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Service\ConfirmerDossierGroupeHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /musee/dossiers-groupe/{id}/confirmer (CA-5/CA-6). Corps : { "responsable": iri|uuid,
 * "nbGratuitesEleve"?: int, "nbGratuitesAccompagnateur"?: int, "contingent"?: iri|uuid }.
 *
 * @implements ProcessorInterface<DossierGroupeScolaire, DossierGroupeScolaire>
 */
final class ConfirmerDossierGroupeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ConfirmerDossierGroupeHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DossierGroupeScolaire
    {
        \assert($data instanceof DossierGroupeScolaire);
        $corps = $this->lecteur->corps();

        $responsable = $this->resoudre(Beneficiaire::class, $corps['responsable'] ?? null, 'responsable');
        \assert($responsable instanceof Beneficiaire);

        $contingent = null;
        if (isset($corps['contingent'])) {
            $contingent = $this->resoudre(ContingentGratuite::class, $corps['contingent'], 'contingent');
            \assert($contingent instanceof ContingentGratuite);
        }

        return $this->handler->confirmer(
            $data,
            $responsable,
            isset($corps['nbGratuitesEleve']) ? (int) $corps['nbGratuitesEleve'] : 0,
            isset($corps['nbGratuitesAccompagnateur']) ? (int) $corps['nbGratuitesAccompagnateur'] : 0,
            $contingent,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
