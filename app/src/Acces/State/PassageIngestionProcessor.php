<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Passage;
use App\Acces\Enum\SensPassage;
use App\Acces\Service\ValidationPassageHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ingestion d'un passage (POST /acces/passages, RG-ACC-01/02, CA-3). Endpoint pivot appelé par le
 * matériel/ITBOX : exécute l'algorithme §4.3 et répond `{resultat, codeMotif}` en < 1 s. Corps :
 *   { "equipement": iri|uuid, "identifiantSupport"?: string, "sens"?: "entree"|"sortie",
 *     "horodatage"?: iso8601, "cleIdempotence"?: uuid }
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class PassageIngestionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ValidationPassageHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();

        $equipementId = $this->uuid($corps['equipement'] ?? null);
        if ($equipementId === null) {
            throw new UnprocessableEntityHttpException('Référence d\'équipement obligatoire.');
        }

        $evt = new EvenementPassageDto(
            equipementId: $equipementId,
            identifiantSupport: isset($corps['identifiantSupport']) ? (string) $corps['identifiantSupport'] : null,
            sens: isset($corps['sens']) ? SensPassage::tryFrom((string) $corps['sens']) : null,
            horodatage: isset($corps['horodatage']) ? new \DateTimeImmutable((string) $corps['horodatage']) : new \DateTimeImmutable(),
            cleIdempotence: $this->uuid($corps['cleIdempotence'] ?? null) ?? Uuid::v4(),
        );

        $passage = $this->handler->valider($evt);

        return $this->reponse($passage);
    }

    private function reponse(Passage $passage): JsonResponse
    {
        return new JsonResponse([
            'id' => (string) $passage->getId(),
            'resultat' => $passage->getResultat()->value,
            'codeMotif' => $passage->getCodeMotif()?->value,
            'motif' => $passage->getMotif(),
            'horodatage' => $passage->getHorodatage()->format(DATE_ATOM),
            'propositionRecharge' => $passage->getCodeMotif()?->value === 'credit_epuise' ? ['caisse', 'borne', 'app'] : null,
        ], JsonResponse::HTTP_OK);
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
