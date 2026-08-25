<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\MouvementPca;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /compta/pca/{id}/rapprochement (US-L4-05) : `resteAServirCentimes` vs somme des `MouvementPca`
 * liés — rapprochable à tout instant.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class RapprochementPcaProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $uuid = match (true) {
            $id instanceof Uuid => $id,
            \is_string($id) && Uuid::isValid($id) => Uuid::fromString($id),
            default => null,
        };
        if ($uuid === null) {
            throw new NotFoundHttpException('Étalement PCA introuvable.');
        }

        $etalement = $this->em->getRepository(EtalementPca::class)->find($uuid);
        if ($etalement === null) {
            throw new NotFoundHttpException('Étalement PCA introuvable.');
        }

        // Cloisonnement (D3/D8) — cette opération custom résout son étalement par un `find()` direct,
        // hors des extensions Doctrine de lecture (AccountingScopeExtension ne s'applique qu'aux
        // providers standards). On recalcule donc explicitement l'autorité (`compta.lire`, permission de
        // l'opération) contre l'établissement de l'étalement (profil -> établissement principal, même
        // critère que l'extension), jamais contre l'en-tête X-Etablissement. Sans ce contrôle, un agent
        // `compta.lire` sur A pouvait lire le rapprochement PCA (produits constatés d'avance, données
        // financières) d'un exploitant d'un autre groupe. Échec fermé en 404 (anti-oracle).
        $this->assertEtalementDansLePerimetre($etalement);

        /** @var list<MouvementPca> $mouvements */
        $mouvements = $this->em->getRepository(MouvementPca::class)->findBy(['etalement' => $etalement->getId()]);

        $dotations = 0;
        $reprises = 0;
        foreach ($mouvements as $mouvement) {
            if ($mouvement->getType() === \App\Compta\Enum\TypeMouvementPca::Dotation) {
                $dotations += $mouvement->getMontantCentimes();
            } else {
                $reprises += $mouvement->getMontantCentimes();
            }
        }

        return new JsonResponse([
            'etalement' => (string) $etalement->getId(),
            'montantReporteCentimes' => $etalement->getMontantReporteCentimes(),
            'resteAServirCentimes' => $etalement->getResteAServirCentimes(),
            'dotationsCentimes' => $dotations,
            'reprisesCentimes' => $reprises,
            'coherent' => $dotations - $reprises === $etalement->getResteAServirCentimes(),
        ]);
    }

    private function assertEtalementDansLePerimetre(EtalementPca $etalement): void
    {
        $utilisateur = $this->security->getUser();
        $etablissement = $etalement->getEtablissement();

        $codes = $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];

        if (!$this->calculateur->autorise($codes, 'compta', 'lire')) {
            throw new NotFoundHttpException('Étalement PCA introuvable.');
        }
    }
}
