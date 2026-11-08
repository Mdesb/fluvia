<?php

declare(strict_types=1);

namespace App\Kiosk\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Kiosk\Entity\Kiosk;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Désappaire une borne (`POST /kiosks/{id}/unpair`) : l'empreinte du jeton d'appareil est effacée, et
 * le jeton cesse d'ouvrir quoi que ce soit dès la requête suivante (`KioskAuthenticator` ne trouve plus
 * rien). C'est le geste d'un appareil volé, remplacé ou mis au rebut. Rejoué, il rend le même état.
 *
 * @implements ProcessorInterface<Kiosk, JsonResponse>
 */
final class KioskUnpairProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Kiosk);
        $data->unpair();
        $this->em->flush();

        return new JsonResponse(['kiosk' => (string) $data->getId(), 'paired' => false], JsonResponse::HTTP_OK);
    }
}
