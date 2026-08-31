<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Sport\Entity\EvenementSOS;
use App\Sport\Security\SosRateLimiter;
use App\Sport\Service\DeclencherSosHandler;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sport/espaces/{id}/sos (US-SPORT-09, CA-11). Déclenchement physique — `PUBLIC_ACCESS` (Risque
 * n°7 du plan, ⚠ jeton d'appareil non spécifié). Corps optionnel : { "support"?: iri|uuid }.
 *
 * @implements ProcessorInterface<mixed, EvenementSOS>
 */
final class DeclencherSosProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly DeclencherSosHandler $handler,
        private readonly SosRateLimiter $frein,
        private readonly RequestStack $requetes,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvenementSOS
    {
        $espaceId = $this->uuid($uriVariables['id'] ?? null);
        $espace = $espaceId !== null ? $this->em->getRepository(EspaceAcces::class)->find($espaceId) : null;
        if (!$espace instanceof EspaceAcces) {
            throw new NotFoundHttpException('Espace d\'accès introuvable.');
        }

        // ⚠ LE FREIN VIENT ICI : APRES L'ESPACE, AVANT LE DECLENCHEMENT.
        //
        // Avant, on ne saurait pas sur quel espace freiner ; apres, l'alerte serait deja creee et le
        // frein ne freinerait rien.
        //
        // Il ne perd jamais un premier appel — voir `SosRateLimiter` : un espace silencieux depuis
        // un quart d'heure passe quoi qu'il arrive. Ce qu'on jette est le vingtieme appel en trois
        // minutes, jamais le premier.
        $this->frein->assertNotExceeded(
            $espace,
            $this->requetes->getCurrentRequest()?->getClientIp(),
        );

        $corps = $this->lecteur->corps();
        $supportId = $this->uuid($corps['support'] ?? null);
        $support = $supportId !== null ? $this->em->getRepository(Support::class)->find($supportId) : null;

        return $this->handler->declencher($espace, $support instanceof Support ? $support : null);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        // Le `{id}` du chemin est auto-casté par API Platform vers le type de l'identifiant de la
        // ressource hôte (Uuid) même quand il référence en réalité une autre entité (EspaceAcces) —
        // cf. `uriTemplate` de cette opération `read:false`. On accepte donc directement un objet Uuid.
        if ($reference instanceof Uuid) {
            return $reference;
        }
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
