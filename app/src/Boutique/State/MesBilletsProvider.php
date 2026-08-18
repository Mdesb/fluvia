<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Service\BilletsCommandeHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/comptes/me/billets (US-L8-10/11, RG-M3-04, CA-14/CA-15) : billets QR/wallet du
 * titulaire connecté, disponibles immédiatement après paiement.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class MesBilletsProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly BilletsCommandeHandler $billetsHandler,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new NotFoundHttpException('Aucun compte client.');
        }
        $compte = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$compte instanceof CompteClient) {
            throw new NotFoundHttpException('Aucun compte client boutique.');
        }

        $suivis = $this->em->getRepository(SuiviCommandeEnLigne::class)->findBy(['compteClient' => $compte]);
        $billets = [];
        foreach ($suivis as $suivi) {
            \assert($suivi instanceof SuiviCommandeEnLigne);
            $vente = $suivi->getVente();
            if ($vente === null) {
                continue;
            }
            array_push($billets, ...$this->billetsHandler->listerPourVente($vente));
        }

        return new JsonResponse(['billets' => $billets]);
    }
}
