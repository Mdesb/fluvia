<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Service\GestionCaution;
use App\Patinoire\Entity\CautionLocationPatins;
use App\Patinoire\Entity\LocationPatins;
use App\Patinoire\Entity\RetenueCaution;
use App\Patinoire\Enum\EtatRetourPatins;
use App\Patinoire\Enum\MotifRetenue;
use App\Patinoire\Enum\StatutCaution as StatutCautionLocation;
use App\Patinoire\Enum\StatutLocationPatins;
use App\Patinoire\Service\PromotionListeAttenteHandler;
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
 * validée séparément par `ValiderRetenueProcessor`). Résolution de grille et calcul du montant
 * proposé délégués à `App\Caution\Service\GestionCaution::proposerRetenue()` (refactor caution
 * générique, cible `patinoire.patins`, sous-cible l'UUID du `ParcPatins`) ; `RetenueCaution` reste
 * l'entité locale exposée par l'API, miroir du mouvement générique (`mouvementGeneriqueRef`).
 *
 * @implements ProcessorInterface<LocationPatins, LocationPatins>
 */
final class RetournerPatinsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PromotionListeAttenteHandler $promotion,
        private readonly GestionCaution $gestionCaution,
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

        // @cloisonnement-verifie : les deux résolutions ci-dessous sont clefées par $data
        // (LocationPatins), lui-même chargé par l'opération `read: true` /patinoire/locations/{id}/retour
        // et donc DÉJÀ confronté au périmètre par PerimetrePatinoireExtension::applyToItem — un {id}
        // d'un autre établissement renvoie 404 au read, avant ce processor. La CautionLocationPatins et
        // la Caution générique liées à $data sont transitivement dans le périmètre.
        $caution = $this->em->getRepository(CautionLocationPatins::class)->findOneBy([
            'location' => $data,
            'locationActive' => $data->getId(),
        ]);
        $cautionGenerique = $this->gestionCaution->cautionActivePour(SortirPatinsProcessor::TYPE_CIBLE, $data->getId());

        if ($etat === EtatRetourPatins::Bon) {
            $data->setStatut(StatutLocationPatins::Retournee);
            if ($cautionGenerique instanceof Caution) {
                $this->gestionCaution->restituer($cautionGenerique);
            }
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
        $texteMotif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : $motif->value;

        $grilleAppliquee = null;
        $montantPropose = $caution?->getMontant() ?? '0.00';
        $mouvementGeneriqueRef = null;

        if ($cautionGenerique instanceof Caution) {
            $mouvement = $this->gestionCaution->proposerRetenue($cautionGenerique, $motif->value, (string) $parcPatins->getId());
            $mouvement->setMotif($texteMotif);
            $montantPropose = $mouvement->getMontantDecimal() ?? $montantPropose;
            $grilleAppliquee = $mouvement->getGrilleAppliquee();
            $mouvementGeneriqueRef = $mouvement->getId();
        }

        $retenue = new RetenueCaution();
        $retenue->setLocation($data)
            ->setGrilleAppliquee($grilleAppliquee)
            ->setMontantRetenu($montantPropose)
            ->setMotif($texteMotif)
            ->setMouvementGeneriqueRef($mouvementGeneriqueRef);
        $this->em->persist($retenue);

        $this->em->flush();

        return $data;
    }
}
