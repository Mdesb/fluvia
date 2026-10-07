<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Offre\Entity\Formule;
use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Membership\Service\SubscriptionTermHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RECONDUCTION AU TARIF EN VIGUEUR — arbitrage de Maxime, 03/09.
 *
 * Son cas : « ils font une augmentation de tarifs, ils doivent pouvoir mettre la date à laquelle le
 * nouveau tarif va s'appliquer ». Le mécanisme de date existait déjà — une saison neuve et une
 * grille dessus — mais il n'atteignait jamais un abonnement en cours : la reconduction recopiait le
 * montant de la dernière échéance.
 *
 * ── ⚠ LE TEST QUI COMPTE N'EST PAS CELUI DU PRIX ───────────────────────────────────────────────
 *
 * C'est celui du refus. `SubscriptionPriceResolver` LÈVE quand rien ne résout — formule sans produit
 * porteur, produit sans facette SEPA, aucune saison couvrant la date. Or ce handler tourne dans
 * `sport:abonnements:traiter-terme`, qui traite TOUS les abonnements arrivant à terme.
 *
 * **Une exception non rattrapée sur un seul abonnement mal tarifé arrêterait le lot entier.** Les
 * suivants ne seraient pas reconduits, et personne ne le saurait avant le relevé bancaire. Le défaut
 * ne se verrait pas : la commande dirait simplement avoir échoué, une fois, dans un journal.
 *
 * Signalé par `allaccess-c0` avant que la ligne soit écrite — c'est ce signalement qui a fait
 * écrire le `try/catch` plutôt que de le découvrir en production.
 */
final class ReconductionTarifTest extends SportApiTestCase
{
    /**
     * **Le cas qui protège la tâche planifiée : un tarif introuvable produit une LIGNE DE RAPPORT.**
     *
     * On casse le lien formule → produit, ce qui est la première des deux levées du résolveur. Un
     * abonné dont le produit a été retiré de la vente est un cas réel, pas une hypothèse.
     */
    public function testUnTarifIntrouvableRendUnVerdictEtNePlantePas(): void
    {
        $em = $this->em();
        $abonnement = $this->abonnementDemo();

        // Une formule que ne porte aucun produit : `forFormula` lève dessus.
        //
        // ⚠ LA PÉRIODICITÉ EST OBLIGATOIRE EN BASE ALORS QUE LA PROPRIÉTÉ EST NULLABLE EN PHP.
        // Sans elle : `Column 'periodicite' cannot be null`. C'est la dette que le garde-fou
        // « nullable sur colonne non nulle » gèle, rencontrée ici par hasard.
        $orpheline = (new Formule())->setPeriodicite(\App\Offre\Enum\PeriodiciteFormule::cases()[0]);
        $em->persist($orpheline);
        $abonnement->setFormule($orpheline);
        $this->auTerme($abonnement);
        $em->flush();

        $apres = $abonnement->getDateFinEngagement()->modify('+1 day');
        $verdict = $this->handler()->process($abonnement, $apres);

        self::assertSame('sans-tarif', $verdict, 'Le handler doit NOMMER le cas, pas laisser filer une exception.');
    }

    /**
     * ⚠ **LE TÉMOIN : sur un abonnement correctement tarifé, la reconduction aboutit.**
     *
     * Sans lui, un handler qui rendrait `'sans-tarif'` pour tout le monde passerait le test
     * ci-dessus — et plus aucun abonnement ne serait jamais reconduit.
     */
    /**
     * ⚠ **LE TÉMOIN : sur un abonnement correctement tarifé, la reconduction aboutit.**
     *
     * Sans lui, un handler qui rendrait `'sans-tarif'` pour tout le monde passerait le test
     * ci-dessus — et plus aucun abonnement ne serait jamais reconduit.
     *
     * ⚠ IL FABRIQUE SON ÉCHÉANCIER PLUTÔT QUE D'EMPRUNTER CELUI DE LA DÉMONSTRATION, et la raison
     * vaut d'être écrite : la démonstration porte des échéances jusqu'en août 2027. Reconduire
     * depuis là résout le tarif à une date qu'AUCUNE SAISON ne couvre, et le handler rend
     * légitimement « sans-tarif ». J'ai lu ce refus comme un échec du test pendant deux minutes.
     *
     * C'est aussi une conséquence produit réelle : un exploitant qui n'a pas créé la saison
     * suivante voit ses reconductions s'arrêter — proprement nommées au rapport, mais arrêtées.
     */
    public function testUnAbonnementCorrectementTarifeEstBienReconduit(): void
    {
        $em = $this->em();
        $demo = $this->abonnementDemo();

        $abonnement = (new Membership())
            ->setAdherent($demo->getAdherent())
            ->setPayeur($demo->getPayeur())
            ->setFormule($demo->getFormule())
            ->setMandatSepa($demo->getMandatSepa())
            ->setEtablissement($demo->getEtablissement())
            ->setMontantCentimes(3990)
            ->setDateSouscription(new \DateTimeImmutable('2026-06-01'))
            ->setDateDebutEngagement(new \DateTimeImmutable('2026-06-01'))
            ->setDateFinEngagement(new \DateTimeImmutable('2026-09-01'));
        $em->persist($abonnement);

        $em->persist((new EcheanceSepa())
            ->setAbonnement($abonnement)
            ->setDateProgrammee(new \DateTimeImmutable('2026-07-01'))
            ->setMontantCentimes(3990)
            ->setStatut(StatutEcheanceSepa::AVenir));
        $em->flush();

        $avant = $this->compteEcheances($abonnement);

        $verdict = $this->handler()->process($abonnement, new \DateTimeImmutable('2026-09-02'));

        self::assertNotSame('sans-tarif', $verdict, 'Aucun tarif ne résout à cette date : le témoin ne mesure rien.');
        self::assertNotSame('sans-montant', $verdict);
        self::assertGreaterThan($avant, $this->compteEcheances($abonnement), 'Aucune échéance créée : rien n a été reconduit.');
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    /**
     * Amène l'abonnement à son terme, ET assez loin après sa dernière échéance pour que la
     * reconduction ait une fenêtre à remplir.
     *
     * ⚠ CE DÉTAIL M'A FAIT LIRE UN FAUX ÉCHEC. Poser une fin d'engagement arbitraire ne suffit
     * pas : la garde d'idempotence repart de la DERNIÈRE échéance existante, et si celle-ci est
     * déjà au-delà de la nouvelle fin, rien n'est créé — ce qui est le comportement voulu. Mon
     * premier jet lisait « 12 est-il supérieur à 12 » et accusait le correctif.
     */
    private function auTerme(Membership $abonnement): void
    {
        $derniere = $this->em()->getRepository(EcheanceSepa::class)
            ->createQueryBuilder('e')
            ->andWhere('e.abonnement = :a')
            ->setParameter('a', $abonnement->getId(), 'uuid')
            ->orderBy('e.dateProgrammee', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertNotNull($derniere, 'L abonnement de démonstration n a aucune échéance : ce test ne mesure rien.');

        $abonnement->setDateFinEngagement($derniere->getDateProgrammee()->modify('+2 months'));
    }

    private function compteEcheances(Membership $abonnement): int
    {
        return (int) $this->em()->getRepository(EcheanceSepa::class)
            ->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.abonnement = :a')
            ->setParameter('a', $abonnement->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function handler(): SubscriptionTermHandler
    {
        /** @var SubscriptionTermHandler $h */
        $h = static::getContainer()->get(SubscriptionTermHandler::class);

        return $h;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
