<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Controleur;
use App\Acces\Service\SynchroPassageHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Remontée d'un lot hors-ligne (POST /acces/synchro, US-L3-07/08, RG-ACC-05, CA-8/9). Rejeu
 * chronologique idempotent, crédits/FMI recalés « en marchant », conflits tracés. Corps :
 *   { "controleur": iri|uuid, "lot": [ { "identifiantSupport"?: string, "equipementId": uuid,
 *       "sens"?: "entree"|"sortie", "horodatage": iso8601, "cleIdempotence": uuid } ] }
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class SynchroProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly SynchroPassageHandler $handler,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();

        $controleurId = $this->uuid($corps['controleur'] ?? null);
        $controleur = $controleurId !== null ? $this->em->getRepository(Controleur::class)->find($controleurId) : null;
        if (!$controleur instanceof Controleur) {
            throw new UnprocessableEntityHttpException('Contrôleur introuvable.');
        }

        // Cloisonnement (D3/D8) — l'opération est `input: false` : le contrôleur est résolu depuis le
        // corps par un `find()` direct, hors des extensions. On recalcule l'autorité de l'agent
        // (sécurité de route : acces.ingestion) contre l'établissement du CONTRÔLEUR VISÉ, pas
        // l'en-tête X-Etablissement (D6). Sans quoi on remonte un lot hors-ligne sur un contrôleur d'un
        // autre établissement. Échec fermé en 404 (anti-oracle).
        $agent = $this->security->getUser();
        $codes = $agent instanceof Utilisateur
            ? $this->calculateur->codesEffectifs($agent, $controleur->getEtablissement()?->getId())
            : [];
        if (!$this->calculateur->autorise($codes, 'acces', 'ingestion')) {
            throw new NotFoundHttpException('Contrôleur introuvable.');
        }

        $lot = \is_array($corps['lot'] ?? null) ? $corps['lot'] : [];
        $resultat = $this->handler->synchroniser($controleur, $lot);

        return new JsonResponse([
            'controleur' => (string) $controleur->getId(),
            'recus' => \count($lot),
            'inseres' => $resultat['inseres'],
            'doublons' => $resultat['doublons'],
            'conflits' => $resultat['conflits'],
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
