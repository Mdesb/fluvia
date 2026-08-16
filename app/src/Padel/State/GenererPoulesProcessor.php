<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Entity\Tournoi;
use App\Padel\Service\GenererPoulesEtBlocageHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /padel/tournois/{id}/generer-poules (US-PADEL-05, CA-6).
 * Corps : { "terrains": [iri|uuid,...], "debut": datetime ISO-8601, "dureeMinutes": int, "tailleMaxPoule"?: int }
 *
 * @implements ProcessorInterface<mixed, Tournoi>
 */
final class GenererPoulesProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenererPoulesEtBlocageHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Tournoi
    {
        \assert($data instanceof Tournoi);

        $corps = $this->lecteur->corps();

        $terrains = [];
        foreach ((array) ($corps['terrains'] ?? []) as $reference) {
            $uuid = $this->uuid($reference);
            if ($uuid === null) {
                throw new UnprocessableEntityHttpException('Référence terrain invalide.');
            }
            $terrain = $this->em->getRepository(TerrainPadel::class)->find($uuid);
            if ($terrain === null) {
                throw new UnprocessableEntityHttpException('Terrain introuvable.');
            }
            $terrains[] = $terrain;
        }

        $debut = $this->dateTime($corps['debut'] ?? null);
        $dureeMinutes = isset($corps['dureeMinutes']) ? (int) $corps['dureeMinutes'] : 90;
        $tailleMaxPoule = isset($corps['tailleMaxPoule']) ? (int) $corps['tailleMaxPoule'] : 4;

        $this->handler->generer($data, $terrains, $debut, $dureeMinutes, $tailleMaxPoule);

        return $data;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }

    private function dateTime(mixed $valeur): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException('Champ « debut » obligatoire (datetime ISO-8601).');
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException('Champ « debut » invalide (datetime ISO-8601 attendu).');
        }
    }
}
