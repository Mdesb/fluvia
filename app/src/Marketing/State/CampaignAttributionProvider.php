<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Marketing\Entity\Campaign;
use App\Marketing\Service\AttributionMeasure;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /marketing/campagnes/{id}/attribution` — ce que la campagne a produit, et à quel prix.
 *
 * Le droit exigé est `campagne.lire_journal`, plus étroit que celui du résultat : cette réponse
 * expose du **chiffre d'affaires par groupe de clients**. Compter des envois et lire ce que dépense
 * une part du fichier client ne se donnent pas au même monde.
 *
 * L'autorité est recalculée contre l'établissement de la campagne, et le refus est un 404 :
 * l'en-tête `X-Etablissement` est un sélecteur, pas une preuve (D6). Le nom d'une campagne dit
 * souvent ce qu'un exploitant prépare — un 403 le confirmerait par simple essai d'identifiants.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final readonly class CampaignAttributionProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private CalculateurDroits $calculateur,
        private AttributionMeasure $mesure,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $campagne = $id !== null
            ? $this->entityManager->getRepository(Campaign::class)->find($id)
            : null;

        if (!$campagne instanceof Campaign) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $campagne->getEstablishment()?->getId());
        if (!$this->calculateur->autorise($codes, 'campagne', 'lire_journal')) {
            throw new NotFoundHttpException('Campagne introuvable.');
        }

        return new JsonResponse($this->mesure->mesurer($campagne));
    }
}
