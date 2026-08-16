<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/comptes/me/commandes (US-L8-10, CA-14) : historique des commandes du titulaire
 * connecté — chaque commande payée est immédiatement visible.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class MesCommandesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $compte = $this->compteConnecte();

        $suivis = $this->em->getRepository(SuiviCommandeEnLigne::class)->findBy(['compteClient' => $compte]);
        $commandes = [];
        foreach ($suivis as $suivi) {
            \assert($suivi instanceof SuiviCommandeEnLigne);
            $vente = $suivi->getVente();
            if ($vente === null) {
                continue;
            }
            $commandes[] = [
                'vente' => (string) $vente->getId(),
                'numero' => $vente->getNumero(),
                'date' => $vente->getDate()->format(DATE_ATOM),
                'total' => $vente->getTotal(),
                'statut' => $vente->getStatut()->value,
                'statutTunnel' => $suivi->getStatutTunnel()->value,
            ];
        }

        return new JsonResponse(['commandes' => $commandes]);
    }

    private function compteConnecte(): CompteClient
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Aucun compte client.');
        }
        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$compte instanceof CompteClient) {
            throw new NotFoundHttpException('Aucun compte client boutique.');
        }

        return $compte;
    }
}
