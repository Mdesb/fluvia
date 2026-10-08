<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Enum\StatutDemandeRemboursement;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/demandes-remboursement (US-L8-12, RG-M3-15, CA-17). Aucun remboursement
 * automatique : la demande est déposée par le client depuis son espace, motivée. Corps :
 * { "vente": iri|uuid, "ligne"?: iri|uuid, "motif", "piecesJustificatives"?: string[] }.
 *
 * @implements ProcessorInterface<mixed, DemandeRemboursement>
 */
final class DeposerDemandeRemboursementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DemandeRemboursement
    {
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $corps = $this->lecteur->corps();
        $venteId = PanierProprietaireGuard::estUuid($corps['vente'] ?? null);
        $vente = $venteId !== null ? $this->em->getRepository(Vente::class)->find($venteId) : null;
        if (!$vente instanceof Vente) {
            throw new UnprocessableEntityHttpException('« vente » est requise et doit référencer une commande existante.');
        }

        $clientLie = $utilisateur->getClientLie();
        if ($clientLie === null || $vente->getClient() === null || (string) $clientLie !== (string) $vente->getClient()) {
            throw new AccessDeniedHttpException('Cette commande n\'appartient pas au client connecté.');
        }

        $motif = \is_string($corps['motif'] ?? null) ? trim($corps['motif']) : '';
        if ($motif === '') {
            throw new UnprocessableEntityHttpException('Un motif est requis (RG-M3-15).');
        }

        $ligneId = PanierProprietaireGuard::estUuid($corps['ligne'] ?? null);
        $ligne = $ligneId !== null ? $this->em->getRepository(LigneVente::class)->find($ligneId) : null;
        // D8 : la ligne doit être une ligne de CETTE commande. Le `find()` seul rattachait à la demande
        // la ligne de la commande d'un autre client ; d'ailleurs ou inexistante, même réponse (D3).
        if (isset($corps['ligne']) && $ligne?->getVente()?->getId()->equals($vente->getId()) !== true) {
            throw new NotFoundHttpException('Ligne de commande introuvable.');
        }

        $demande = new DemandeRemboursement();
        $demande->setVente($vente)
            ->setLigne($ligne)
            ->setMotif($motif)
            ->setPiecesJustificatives(\is_array($corps['piecesJustificatives'] ?? null) ? $corps['piecesJustificatives'] : null)
            ->setStatut(StatutDemandeRemboursement::Recue)
            ->setEtablissement($vente->getEtablissement());
        $this->em->persist($demande);
        $this->em->flush();

        return $demande;
    }
}
