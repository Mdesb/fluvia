<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\GenerateurEcrituresHandler;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/ecritures/generer (RG-COMPTA-04, §2 du plan) : déclenche la génération d'écritures
 * pour le profil exploitant demandé. Idempotent (même handler que la commande CLI planifiée).
 * Corps : { "profilExploitant": iri|uuid }
 *
 * Correctif sécurité (FIN-1, §0.5 du plan `plan-comptabilite-generale.md`) : `profilExploitant` était
 * résolu **uniquement** depuis l'id fourni dans le corps, sans aucune vérification qu'il appartient à
 * l'établissement actif (IDOR cross-tenant réel — un utilisateur autorisé sur son propre établissement
 * pouvait déclencher la génération d'écritures pour le profil comptable d'un **autre** établissement).
 * Corrigé selon le même patron que `SaisirEcritureManuelleProcessor` : `ContexteEtablissement` résolu
 * **côté serveur**, `ProfilExploitant::couvre()` revérifié, échec fermé -> 404 (pas 403 : ne révèle pas
 * l'existence d'un profil hors périmètre).
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class GenererEcrituresProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurEcrituresHandler $handler,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $reference = $corps['profilExploitant'] ?? null;
        $id = \is_string($reference) ? (str_contains($reference, '/') ? basename($reference) : $reference) : null;
        if ($id === null || !Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('Référence de profil exploitant obligatoire.');
        }

        $profil = $this->em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($id));
        $etablissementActif = $this->contexte->etablissementActif();

        // Échec fermé (cloisonnement, correctif IDOR) : profil inexistant OU hors périmètre de
        // l'établissement actif -> 404 uniforme, jamais de repli « premier profil trouvé ».
        if ($profil === null || $etablissementActif === null || !$profil->couvre($etablissementActif)) {
            throw new NotFoundHttpException('Profil exploitant introuvable.');
        }

        return new JsonResponse($this->handler->generer($profil));
    }
}
