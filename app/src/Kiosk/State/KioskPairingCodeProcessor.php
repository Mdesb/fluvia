<?php

declare(strict_types=1);

namespace App\Kiosk\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Service\KioskSecrets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Émet le code d'appairage d'une borne (`POST /kiosks/{id}/pairing-code`). La borne a déjà été trouvée
 * dans le périmètre de l'appelant (`KioskScopeExtension`) : une borne d'un autre établissement rend 404.
 *
 * Le code n'est rendu qu'ICI, une fois ; seule son empreinte est gardée. En émettre un nouveau annule
 * le précédent. Une borne déjà appairée le reste jusqu'à ce que la borne saisisse le nouveau code :
 * l'appareil est alors remplacé (un seul appareil par borne).
 *
 * ⚠ REFUSÉ TANT QU'AUCUN CODE DE SORTIE N'EST POSÉ. Sans lui, personne ne pourrait quitter l'écran de
 * la borne pour la maintenance — sinon en la désappairant depuis le back-office, à distance de
 * l'appareil. On ne met pas en service un écran verrouillé dont on n'a pas la clé.
 *
 * @implements ProcessorInterface<Kiosk, JsonResponse>
 */
final class KioskPairingCodeProcessor implements ProcessorInterface
{
    public const VALIDITY_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KioskSecrets $secrets,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Kiosk);
        if (!$data->hasExitCode()) {
            throw new ConflictHttpException('Posez d\'abord le code de sortie du personnel : sans lui, personne ne pourrait quitter l\'écran de la borne.');
        }
        if (!$data->isActive()) {
            throw new ConflictHttpException('Cette borne est désactivée : réactivez-la avant de l\'appairer.');
        }

        $code = $this->secrets->newPairingCode();
        $expiresAt = new \DateTimeImmutable(sprintf('+%d minutes', self::VALIDITY_MINUTES));
        $data->setPairingCode($this->secrets->hashPairingCode($code), $expiresAt);
        $this->em->flush();

        return new JsonResponse([
            'kiosk' => (string) $data->getId(),
            'code' => $code,
            'expiresAt' => $expiresAt->format(\DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }
}
