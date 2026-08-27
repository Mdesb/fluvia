<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\SouscriptionAbonnementEnLigneHandler;
use App\Boutique\Service\VitrineResolver;
use App\Offre\Entity\Produit;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/abonnements/souscrire (US-L8-09, RG-M3-12/17, CA-13). Corps :
 * { "produit": iri|uuid, "iban", "bicDebiteur", "debiteurNom", "dateSignature"? }.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class SouscrireAbonnementEnLigneProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly SouscriptionAbonnementEnLigneHandler $handler,
        private readonly VitrineResolver $resolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $produitId = PanierProprietaireGuard::estUuid($corps['produit'] ?? null);
        $produit = $produitId !== null ? $this->em->getRepository(Produit::class)->find($produitId) : null;
        if (!$produit instanceof Produit) {
            throw new UnprocessableEntityHttpException('« produit » est requis et doit référencer un produit existant.');
        }

        $compteClient = null;
        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $compteClient = $this->em->getRepository(CompteClient::class)->findOneBy(['utilisateur' => $utilisateur]);
        }

        // LA BOUTIQUE OU L'ACHAT A LIEU, ET NON CELLE OU LE COMPTE EST NE.
        //
        // Sans ce parametre, le gestionnaire retombait sur `compteClient->getVitrineCreation()` : un
        // client inscrit chez Piscine A qui s'abonne chez Patinoire B faisait entrer l'abonnement, le
        // mandat SEPA et le panier DANS LES COMPTES DE PISCINE A. Un compte global est une identite,
        // pas une appartenance commerciale -- et l'argent, lui, appartient au vendeur.
        //
        // Facultatif pour ne rien casser : les appelants qui ne l'envoient pas conservent l'ancien
        // comportement, et l'ecran de la boutique le transmet desormais.
        $vitrineAchat = $this->resolver->resoudre($corps['vitrine'] ?? null);

        $vente = $this->handler->souscrire($compteClient, $produit, $corps, $vitrineAchat);

        return new JsonResponse([
            'vente' => (string) $vente->getId(),
            'numero' => $vente->getNumero(),
            'statut' => $vente->getStatut()->value,
        ], JsonResponse::HTTP_CREATED);
    }
}
