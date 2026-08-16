<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\CompteClient;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/comptes/me (US-L8-10, écran M3-04) : espace client du titulaire connecté.
 *
 * @implements ProviderInterface<CompteClient>
 */
final class MeCompteClientProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompteClient
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Aucun compte client.');
        }
        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$compte instanceof CompteClient) {
            throw new NotFoundHttpException('Aucun compte client boutique pour cet utilisateur.');
        }

        return $compte;
    }
}
