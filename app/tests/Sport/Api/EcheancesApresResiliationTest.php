<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\Resiliation;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Membership\Enum\StatutResiliation;
use App\Membership\Service\DemanderResiliationHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QUI RESTE À PRÉLEVER QUAND QUELQU'UN PART — et ce qui reste dû malgré tout.
 *
 * ── LE DÉFAUT, ET LES TROIS SESSIONS QUI L'ONT TOUCHÉ PAR UN BOUT ──────────────────────────────
 *
 * `claude-A` a corrigé le 02/09 le prélèvement sur mandat révoqué : un adhérent qui résiliait était
 * encore débité, parce que la génération de remise ne lisait pas le statut du mandat. Plus rien ne
 * part — mais rien ne touchait aux échéances.
 *
 * J'ai ajouté le même soir l'état `Annulee`, posé jusqu'ici par un geste MANUEL uniquement.
 *
 * Résultat : les échéances d'un adhérent parti restaient `AVenir` pour toujours, et l'écran
 * continuait de les présenter comme dues. Mesure du 03/09 en préproduction : **38 échéances dans
 * cet état, la plus ancienne de septembre 2025**. L'exploitant croyait avoir de l'argent à
 * encaisser sur des gens partis depuis un an.
 *
 * ── ⚠ ET LA MOITIÉ QU'ON RATE : CE QUI EST AVANT L'EFFET RESTE DÛ ─────────────────────────────
 *
 * Une résiliation porte un préavis — l'effet arrive des semaines après la demande. Une échéance
 * datée AVANT cet effet correspond à une période réellement utilisée : c'est une créance, pas un
 * reliquat. L'annuler effacerait de l'argent dû sans erreur, sans trace, et sans que personne ne
 * le voie.
 *
 * Les deux tests de ce fichier ne valent que l'un par l'autre.
 */
final class EcheancesApresResiliationTest extends SportApiTestCase
{
    public function testLesEcheancesPosterieuresALEffetSontAnnuleesAvecLeurMotif(): void
    {
        $em = $this->em();
        $abonnement = $this->abonnementDemo();
        $resiliation = $this->resiliationEnPreavis($abonnement);
        $effet = $resiliation->getDateEffet();

        $apres = $this->echeance($abonnement, $effet->modify('+40 days'));

        $this->handler()->executerEffet($resiliation);
        $em->clear();

        $relue = $em->getRepository(EcheanceSepa::class)->find($apres->getId());
        self::assertNotNull($relue);
        self::assertSame(StatutEcheanceSepa::Annulee, $relue->getStatut());
        self::assertNotNull($relue->getCancelledAt());
        self::assertStringContainsString('Résiliation effective du', (string) $relue->getCancellationReason());
        self::assertStringContainsString($effet->format('d/m/Y'), (string) $relue->getCancellationReason());
    }

    /**
     * ⚠ **LE TÉMOIN QUI EMPÊCHE LE PRÉCÉDENT D'EFFACER UNE CRÉANCE.**
     *
     * Une annulation qui ratisserait tout satisferait le premier test aussi bien. Celui-ci est le
     * seul à dire que l'argent dû pour une période utilisée reste réclamable.
     */
    public function testUneEcheanceAnterieureALEffetResteDue(): void
    {
        $em = $this->em();
        $abonnement = $this->abonnementDemo();
        $resiliation = $this->resiliationEnPreavis($abonnement);
        $effet = $resiliation->getDateEffet();

        $avant = $this->echeance($abonnement, $effet->modify('-5 days'));
        $apres = $this->echeance($abonnement, $effet->modify('+40 days'));

        $this->handler()->executerEffet($resiliation);
        $em->clear();

        $relueAvant = $em->getRepository(EcheanceSepa::class)->find($avant->getId());
        $relueApres = $em->getRepository(EcheanceSepa::class)->find($apres->getId());

        self::assertSame(StatutEcheanceSepa::AVenir, $relueAvant->getStatut(), 'Une période utilisée avant l effet reste due.');
        self::assertNull($relueAvant->getCancellationReason());

        // Le témoin dans l'autre sens : sans lui, un handler qui n'annulerait RIEN passerait ce test.
        self::assertSame(StatutEcheanceSepa::Annulee, $relueApres->getStatut());
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    private function handler(): DemanderResiliationHandler
    {
        /** @var DemanderResiliationHandler $h */
        $h = static::getContainer()->get(DemanderResiliationHandler::class);

        return $h;
    }

    /** Une résiliation déjà en préavis, prête à recevoir son effet. */
    private function resiliationEnPreavis(Membership $abonnement): Resiliation
    {
        $em = $this->em();
        $demande = new \DateTimeImmutable('2026-09-01');

        $resiliation = (new Resiliation())
            ->setAbonnement($abonnement)
            ->setDateDemande($demande)
            ->setMotif('Déménagement')
            ->setMotifLegitime(true)
            ->setPreavisAppliqueJours($abonnement->getPreavisResiliationJours())
            ->setDateEffet($demande->modify(sprintf('+%d days', $abonnement->getPreavisResiliationJours())))
            ->setStatut(StatutResiliation::EnPreavis);

        $em->persist($resiliation);
        $em->flush();

        return $resiliation;
    }

    private function echeance(Membership $abonnement, \DateTimeImmutable $le): EcheanceSepa
    {
        $em = $this->em();
        $echeance = (new EcheanceSepa())
            ->setAbonnement($abonnement)
            ->setDateProgrammee($le)
            ->setMontantCentimes(3990)
            ->setStatut(StatutEcheanceSepa::AVenir);

        $em->persist($echeance);
        $em->flush();

        return $echeance;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
