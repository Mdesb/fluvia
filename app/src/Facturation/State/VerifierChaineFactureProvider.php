<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * GET /factures/verifier-chaine?profilExploitant=... (CA-7, intégrité NF525 propre à Facturation).
 * Recalcule la chaîne d'un exploitant et détecte les ruptures (trou de séquence, empreinte altérée,
 * signature invalide). Sans paramètre `profilExploitant`, résout le profil de l'établissement actif.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class VerifierChaineFactureProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementFactureHandler $scellement,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly ContexteEtablissement $contexte,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $profilId = $this->requestStack->getCurrentRequest()?->query->get('profilExploitant');
        $profil = null;
        if (\is_string($profilId) && Uuid::isValid($profilId)) {
            $profil = $this->em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($profilId));
        } elseif (($etablissement = $this->contexte->etablissementActif()) !== null) {
            $profil = $this->comptes->profilPour($etablissement);
        }

        if (!$profil instanceof ProfilExploitant) {
            return new JsonResponse(['intacte' => true, 'nbDocuments' => 0, 'anomalies' => []]);
        }

        $factures = $this->em->getRepository(Facture::class)->findBy(['profilExploitant' => $profil->getId()]);
        $rapport = $this->scellement->verifieChaine($factures);

        return new JsonResponse($rapport);
    }
}
