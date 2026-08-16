<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Patinoire\Entity\CautionLocationPatins;
use App\Patinoire\Entity\GrilleRetenue;
use App\Patinoire\Entity\LocationPatins;
use App\Patinoire\Entity\RetenueCaution;
use App\Patinoire\Enum\EtatRetourPatins;
use App\Patinoire\Enum\MotifRetenue;
use App\Patinoire\Enum\StatutCaution as StatutCautionLocation;
use App\Patinoire\Enum\StatutLocationPatins;
use App\Patinoire\Service\PromotionListeAttenteHandler;
use App\Patinoire\Service\ResolveurGrilleRetenueHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Retour de patins loués (POST /patinoire/locations/{id}/retour, US-PATIN-03, RG-PAT-05, CA-3/CA-4).
 * Corps : { "etatRetour": "bon"|"casse"|"non_rendu", "motif"?: string }. Libère l'article de la
 * pointure (état bon) ou le fait sortir définitivement du parc (casse/non-rendu → hors service,
 * RG-PAT-06). Si bon, restitue intégralement la caution et promeut la liste d'attente (§4.5) ; sinon,
 * propose le montant par défaut de la grille de retenue (§4.4, `RetenueCaution` créée *proposée*,
 * validée séparément par `ValiderRetenueProcessor`).
 *
 * @implements ProcessorInterface<LocationPatins, LocationPatins>
 */
final class RetournerPatinsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ResolveurGrilleRetenueHandler $resolveurGrille,
        private readonly PromotionListeAttenteHandler $promotion,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LocationPatins
    {
        \assert($data instanceof LocationPatins);

        if ($data->getStatut() !== StatutLocationPatins::EnCours) {
            throw new ConflictHttpException('Cette location a déjà été clôturée.');
        }

        $corps = $this->lecteur->corps();
        $etat = EtatRetourPatins::tryFrom(\is_string($corps['etatRetour'] ?? null) ? $corps['etatRetour'] : '');
        if ($etat === null) {
            throw new UnprocessableEntityHttpException('Champ « etatRetour » obligatoire (bon|casse|non_rendu).');
        }

        $parcPatins = $data->getParcPatins();
        if ($parcPatins === null) {
            throw new UnprocessableEntityHttpException('Location sans parc de patins rattaché.');
        }

        $data->setDateRetour(new \DateTimeImmutable())->setEtatRetour($etat);
        $parcPatins->incrementerSortie(-1);

        $caution = $this->em->getRepository(CautionLocationPatins::class)->findOneBy([
            'location' => $data,
            'locationActive' => $data->getId(),
        ]);

        if ($etat === EtatRetourPatins::Bon) {
            $data->setStatut(StatutLocationPatins::Retournee);
            if ($caution instanceof CautionLocationPatins) {
                $caution->setStatut(StatutCautionLocation::Liberee)->setDateLiberation(new \DateTimeImmutable());
            }
            $this->em->flush();
            $this->promotion->promouvoir($parcPatins);

            return $data;
        }

        // Casse ou non-rendu : l'article sort définitivement du parc louable (RG-PAT-06).
        $parcPatins->incrementerHS(1);
        $data->setStatut($etat === EtatRetourPatins::Casse ? StatutLocationPatins::Retournee : StatutLocationPatins::NonRendue);

        // Restitution partielle d'une paire (un seul patin rendu) — ⚠ HYPOTHÈSE non tranchée par les
        // sources (spec §4.3/§7) : taux réduit de la grille de retenue applicable si le corps de la
        // requête le signale explicitement (motif dédié `restitution_partielle`).
        $restitutionPartielle = $etat === EtatRetourPatins::NonRendu && (bool) ($corps['restitutionPartielle'] ?? false);
        $motif = match (true) {
            $restitutionPartielle => MotifRetenue::RestitutionPartielle,
            $etat === EtatRetourPatins::Casse => MotifRetenue::Casse,
            default => MotifRetenue::NonRendu,
        };
        $etablissement = $data->getEtablissement();
        $grille = $etablissement !== null ? $this->resolveurGrille->resoudre($etablissement, $parcPatins, $motif) : null;
        $montantCautionDefaut = $caution?->getMontant() ?? '0.00';
        $montantPropose = $this->resolveurGrille->montantPropose($grille, $montantCautionDefaut);

        $retenue = new RetenueCaution();
        $retenue->setLocation($data)
            ->setGrilleAppliquee($grille)
            ->setMontantRetenu($montantPropose)
            ->setMotif(\is_string($corps['motif'] ?? null) ? $corps['motif'] : $motif->value);
        $this->em->persist($retenue);

        $this->em->flush();

        return $data;
    }
}
