<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\DeclarationPerteVol;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Service\RevocationBadgeHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Déclare un badge staff perdu/volé (POST /personnel/badges/{id}/declarer-incident, RG-PERSO-08,
 * CA-11) : délègue à `App\Acces\Service\BlocageSupportHandler::bloquer()` (via
 * `RevocationBadgeHandler::suspendre()`, réversible — même mécanisme que la suspension, décision n°7
 * du plan), zéro duplication de RG-ACC-07. Corps : { "motif": "…" }.
 *
 * @implements ProcessorInterface<BadgeStaff, JsonResponse>
 */
final class DeclarerIncidentBadgeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly RevocationBadgeHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof BadgeStaff);

        $agent = $this->security->getUser();
        if (!$agent instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Agent authentifié requis.');
        }

        $motif = (string) ($this->lecteur->corps()['motif'] ?? '');
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour une déclaration de perte/vol.');
        }

        $this->handler->suspendre($data, $motif, $agent);

        // @cloisonnement-verifie : le findOneBy ci-dessous ne résout PAS un identifiant client — il
        // relit la déclaration à partir du support du badge `$data`, lequel est confronté au périmètre
        // par `read: true` + PerimetrePersonnelExtension. Le seul champ lu au corps est `motif` (chaîne,
        // pas une entité). Aucune résolution hors périmètre. — claude-B, 26/08.
        $support = $data->getSupport();
        $declaration = $support !== null
            ? $this->em->getRepository(DeclarationPerteVol::class)->findOneBy(['support' => $support, 'annulee' => false], ['horodatage' => 'DESC'])
            : null;

        return new JsonResponse([
            'id' => $declaration instanceof DeclarationPerteVol ? (string) $declaration->getId() : null,
            'badgeStaff' => (string) $data->getId(),
            'motif' => $motif,
            'agent' => (string) $agent->getId(),
            'horodatage' => $declaration instanceof DeclarationPerteVol ? $declaration->getHorodatage()->format(DATE_ATOM) : null,
            'annulee' => false,
        ], JsonResponse::HTTP_CREATED);
    }
}
