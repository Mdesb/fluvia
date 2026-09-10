<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Securite\Entity\Utilisateur;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\Resiliation;
use App\Membership\Enum\MotifInactiviteAccesFitness;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Membership\Enum\StatutResiliation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Résiliation d'abonnement (US-SPORT-03, RG-SPORT-06/07, CA-3). En engagement, une demande sans motif
 * légitime est bloquée (`refusee`) ; avec motif légitime, elle reste `refusee` (en attente de
 * validation) jusqu'à `validerMotifLegitime()`. Hors engagement, la demande est acceptée directement
 * (`en_preavis`). Le mandat SEPA n'est révoqué qu'à la **date d'effet** (`executerEffet()`), jamais
 * avant (RG-SPORT-06).
 */
final class DemanderResiliationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PropagationAccesFitnessHandler $propagation,
    ) {
    }

    public function demander(Membership $abonnement, \DateTimeImmutable $dateDemande, string $motif, bool $motifLegitime, ?string $justificatif): Resiliation
    {
        $resiliation = new Resiliation();
        $resiliation->setAbonnement($abonnement)
            ->setDateDemande($dateDemande)
            ->setMotif($motif)
            ->setMotifLegitime($motifLegitime)
            ->setJustificatifChemin($justificatif);

        $preavis = $abonnement->getPreavisResiliationJours();
        $resiliation->setPreavisAppliqueJours($preavis);
        $resiliation->setDateEffet($dateDemande->modify(sprintf('+%d days', $preavis)));

        $enEngagement = $dateDemande < $abonnement->getDateFinEngagement();
        $resiliation->setStatut($enEngagement ? StatutResiliation::Refusee : StatutResiliation::EnPreavis);

        $this->em->persist($resiliation);
        $this->em->flush();

        return $resiliation;
    }

    /** Validation manuelle obligatoire du motif légitime par un rôle habilité (spec §4.3). */
    public function validerMotifLegitime(Resiliation $resiliation, Utilisateur $validateur): Resiliation
    {
        if (!$resiliation->isMotifLegitime()) {
            throw new UnprocessableEntityHttpException('Aucun motif légitime à valider sur cette résiliation.');
        }
        if ($resiliation->getStatut() !== StatutResiliation::Refusee) {
            throw new UnprocessableEntityHttpException('Résiliation non en attente de validation.');
        }

        $resiliation->setValideParUtilisateur($validateur)->setStatut(StatutResiliation::EnPreavis);
        $this->em->flush();

        return $resiliation;
    }

    /** Exécute l'effet de la résiliation à sa date d'effet : mandat révoqué, accès coupé (§4.3/§4.7). */
    public function executerEffet(Resiliation $resiliation): void
    {
        if ($resiliation->getStatut() !== StatutResiliation::EnPreavis) {
            throw new UnprocessableEntityHttpException('Résiliation non en préavis, effet non applicable.');
        }

        $resiliation->setStatut(StatutResiliation::Effective);

        $abonnement = $resiliation->getAbonnement();
        $abonnement->setStatut(MembershipStatus::Resilie);

        // ⚠ ON NE RÉVOQUE QUE SI PLUS AUCUN AUTRE ABONNEMENT N'EN A BESOIN.
        //
        // Depuis que `mandatSepa` est un `ManyToOne`, un mandat peut porter plusieurs abonnements.
        // La révocation inconditionnelle qui vivait ici arrêterait les prélèvements du second sans
        // erreur et sans message : l'adhérent garderait son accès, puisque SON abonnement reste
        // actif, et cesserait simplement d'être facturé. Personne ne le verrait avant le
        // rapprochement bancaire.
        $mandat = $abonnement->getMandatSepa();
        if ($mandat !== null && !$this->autreAbonnementVivantSur($mandat, $abonnement)) {
            $mandat->setStatut(StatutMandatSepa::Revoque);
        }

        $this->annulerEcheancesRestantes($resiliation, $abonnement);

        $this->em->flush();

        $this->propagation->desactiver($abonnement, MotifInactiviteAccesFitness::Resiliation);
    }

    /**
     * LES ÉCHÉANCES POSTÉRIEURES À L'EFFET S'ANNULENT — sinon elles restent « à venir » pour toujours.
     *
     * ── ⚠ CE QUE LA RÉSILIATION NE FAISAIT PAS, ET QUI SE VOYAIT À L'ÉCRAN ─────────────────────
     *
     * `claude-A` a corrigé le 02/09 le prélèvement sur mandat révoqué : un adhérent qui résiliait
     * était encore débité, parce que la génération de remise ne regardait pas le statut du mandat.
     * Le filtre pose le bon état — plus rien ne part — mais il ne touche pas aux échéances.
     *
     * Elles restaient donc `AVenir` indéfiniment, et l'écran continuait de les présenter comme dues.
     * L'exploitant croyait avoir de l'argent à encaisser sur quelqu'un qui était parti. Mesure du
     * 03/09 : 38 échéances dans cet état en préproduction, la plus ancienne de septembre 2025.
     *
     * L'état `Annulee` existe depuis le 02/09 ; il n'était posé que par un geste MANUEL
     * (`POST /sport/echeances/{id}/annuler`). C'est ce geste qui manquait ici.
     *
     * ── ⚠ SEULEMENT CE QUI EST APRÈS LA DATE D'EFFET, ET C'EST TOUT LE PIÈGE ──────────────────
     *
     * Une résiliation porte un préavis : l'effet arrive des semaines après la demande. Une échéance
     * datée AVANT cet effet correspond à une période que l'adhérent a réellement utilisée — c'est
     * une somme due, pas un reliquat. L'annuler effacerait une créance légitime, et personne ne le
     * verrait : ni erreur, ni trace, juste de l'argent qui cesse d'être réclamé.
     *
     * On ne touche donc qu'à ce qui vient APRÈS. Ce qui reste dû reste dû, et se recouvre par les
     * chemins habituels.
     */
    private function annulerEcheancesRestantes(Resiliation $resiliation, Membership $abonnement): void
    {
        $effet = $resiliation->getDateEffet();
        $motif = sprintf('Résiliation effective du %s', $effet->format('d/m/Y'));
        $maintenant = new \DateTimeImmutable();

        /** @var list<EcheanceSepa> $restantes */
        $restantes = $this->em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->andWhere('e.abonnement = :abonnement')
            ->andWhere('e.statut = :aVenir')
            ->andWhere('e.dateProgrammee > :effet')
            ->setParameter('abonnement', $abonnement->getId(), 'uuid')
            ->setParameter('aVenir', StatutEcheanceSepa::AVenir)
            ->setParameter('effet', $effet, 'date_immutable')
            ->getQuery()
            ->getResult();

        foreach ($restantes as $echeance) {
            $echeance->setStatut(StatutEcheanceSepa::Annulee)
                ->setCancellationReason($motif)
                ->setCancelledAt($maintenant);
        }
    }

    /**
     * Un autre abonnement s'appuie-t-il encore sur ce mandat ?
     *
     * ⚠ `Impaye` et `Pause` COMPTENT COMME VIVANTS, et c'est le point le plus facile à rater. Un
     * abonnement impayé est exactement celui dont on veut continuer à prélever ; révoquer son
     * mandat effacerait le moyen de recouvrer la créance. Seul `Resilie` libère le mandat.
     *
     * ⚠ L'UUID EST LIÉ AVEC SON TYPE (`'uuid'`), pas passé en objet. Doctrine lie alors
     * l'identifiant SANS son type : la requête reste valide et **compte zéro** — donc on
     * révoquerait toujours, et le garde-fou n°16 existe précisément pour cette forme-là.
     */
    private function autreAbonnementVivantSur(MandatSepa $mandat, Membership $exclu): bool
    {
        $nombre = (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Membership::class, 'a')
            ->where('a.mandatSepa = :mandat')
            ->andWhere('a.id != :exclu')
            ->andWhere('a.statut != :resilie')
            ->setParameter('mandat', $mandat->getId(), 'uuid')
            ->setParameter('exclu', $exclu->getId(), 'uuid')
            ->setParameter('resilie', MembershipStatus::Resilie->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $nombre > 0;
    }
}
