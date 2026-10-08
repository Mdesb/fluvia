<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Enum\StatutDemandeRemboursement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Service\ContrePassationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Traitement d'une demande de remboursement en ligne (RG-M3-15, §0 décision n°6 du plan) : accepter
 * délègue **directement** à `App\Vente\Service\ContrePassationHandler::rembourser()` (code réel,
 * identique au guichet M2) — aucune ligne de calcul dupliquée en Boutique.
 */
final class TraiterDemandeRemboursementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContrePassationHandler $contrePassation,
    ) {
    }

    public function accepter(DemandeRemboursement $demande, ?string $montant, Utilisateur $operateur): Avoir
    {
        $this->exigerRecevable($demande);
        $vente = $demande->getVente();
        \assert($vente !== null);

        $avoir = $this->contrePassation->rembourser($vente, $montant, 'Demande en ligne : ' . $demande->getMotif(), $operateur);
        $this->em->persist($avoir);

        $demande->setStatut(StatutDemandeRemboursement::Acceptee)
            ->setAvoirRattache($avoir)
            ->setDateTraitement(new \DateTimeImmutable())
            ->setTraitePar($operateur);
        $this->em->flush();
        // Après la demande close : un échec ici ne la laisse pas ouverte, prête à rembourser deux fois.
        $this->contrePassation->resilierSiRembourseeEnTotalite($vente, 'Demande en ligne : ' . $demande->getMotif());

        return $avoir;
    }

    public function refuser(DemandeRemboursement $demande, string $motifRefus, Utilisateur $operateur): void
    {
        $this->exigerRecevable($demande);
        if (trim($motifRefus) === '') {
            throw new UnprocessableEntityHttpException('Un motif de refus est requis.');
        }

        $demande->setStatut(StatutDemandeRemboursement::Refusee)
            ->setMotifRefus($motifRefus)
            ->setDateTraitement(new \DateTimeImmutable())
            ->setTraitePar($operateur);
        $this->em->flush();
    }

    private function exigerRecevable(DemandeRemboursement $demande): void
    {
        if (!\in_array($demande->getStatut(), [StatutDemandeRemboursement::Recue, StatutDemandeRemboursement::EnCours], true)) {
            throw new ConflictHttpException('Cette demande de remboursement a déjà été traitée.');
        }
    }
}
