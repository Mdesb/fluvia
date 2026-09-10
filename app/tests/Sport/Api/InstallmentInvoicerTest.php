<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\InstallmentInvoice;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Service\InstallmentInvoicer;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\MandatSepa;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * G-1 / G-1bis (chaine-encaissement) — la facture d'une échéance d'abonnement.
 *
 * Le DTO d'échéance est construit à la main : ce qui est éprouvé ici est le COMPOSEUR, pas la chaîne
 * SEPA qui le nourrit. La faire traverser rendrait le témoin dépendant de dix mécanismes dont aucun
 * n'est en cause, et un échec ne dirait plus lequel.
 */
final class InstallmentInvoicerTest extends SportApiTestCase
{
    public function testUneEcheanceDonneUneFactureNumeroteeEtScellee(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');

        $reservation = $invoicer->facturer($this->echeance($em, 12000), $etab, $auteur);

        self::assertNotNull($reservation->getInvoiceId());
        $facture = $em->getRepository(Facture::class)->find($reservation->getInvoiceId());
        self::assertInstanceOf(Facture::class, $facture);
        self::assertNotNull($facture->getNumero(), 'La facture doit être numérotée.');
        self::assertSame(StatutFacture::EnAttentePaiement, $facture->getStatut());
        self::assertNotNull($facture->getEcritureGeneree(), 'L\'écriture au journal FAC doit exister.');

        // ⚠ LE MONTANT PRÉLEVÉ EST TTC. Une ligne à 120,00 HT réclamerait 144,00 quand la banque
        //    débite 120,00 : les deux chiffres ne se rejoindraient jamais, et personne ne saurait
        //    lequel croire.
        self::assertSame('120.00', $facture->getTotalTTC(), 'Le total TTC doit égaler ce que la banque prélève.');
        self::assertSame('100.00', $facture->getTotalHT());
    }

    /** Rejouer la tâche ne fabrique pas un second document scellé — c'est la contrainte qui le garantit. */
    public function testRejouerNEmetPasUneSecondeFacture(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');
        $echeance = $this->echeance($em, 12000);

        $premier = $invoicer->facturer($echeance, $etab, $auteur);
        $second = $invoicer->facturer($echeance, $etab, $auteur);

        self::assertSame($premier->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertSame(
            1,
            (int) $em->getRepository(Facture::class)->createQueryBuilder('f')->select('COUNT(f.id)')->getQuery()->getSingleScalarResult(),
            'Une seule facture, quel que soit le nombre de passages.',
        );
    }

    /**
     * ⚠ LE TÉMOIN QUI SÉPARE « DÉJÀ FACTURÉ » DE « LA MACHINE A TOUSSÉ ». Sans le retrait de la
     * réservation, une panne technique verrouillerait l'échéance pour toujours, et le message que
     * lirait l'exploitant le mois suivant serait « déjà facturée » — le pire des diagnostics, parce
     * qu'il est rassurant.
     */
    public function testUneEmissionQuiEchoueLaisseLEcheanceRejouable(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        // Aucun taux nulle part : l'émission refuse.
        $this->poserTauxDefaut($em, $etab, null);
        $echeance = $this->echeance($em, 12000);

        try {
            $invoicer->facturer($echeance, $etab, $auteur);
            self::fail('L\'émission devait refuser faute de taux.');
        } catch (UnprocessableEntityHttpException $refus) {
            self::assertStringContainsString($echeance->referenceOrigine, $refus->getMessage(), 'Le refus doit nommer l\'échéance.');
        }

        self::assertSame(
            0,
            (int) $em->getRepository(InstallmentInvoice::class)->createQueryBuilder('r')->select('COUNT(r.id)')->getQuery()->getSingleScalarResult(),
            'La réservation doit être retirée : l\'échéance reste rejouable.',
        );
    }

    /** Le taux porté par l'échéance prime sur le défaut de l'établissement. */
    public function testLeTauxDeLEcheancePrimeSurLeDefaut(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');

        $reservation = $invoicer->facturer($this->echeance($em, 10550, '5.50'), $etab, $auteur);

        $facture = $em->getRepository(Facture::class)->find($reservation->getInvoiceId());
        self::assertInstanceOf(Facture::class, $facture);
        self::assertSame('100.00', $facture->getTotalHT(), 'Avec 5,5 %, 105,50 TTC vaut 100,00 HT — pas avec 20 %.');
    }

    /**
     * ⚠ DEUX TAUX À LA MÊME VALEUR EXISTENT VRAIMENT : les fixtures en portent deux à 5,50 (dont un
     * inactif). Choisir le premier reviendrait à décider d'une catégorie fiscale par hasard.
     */
    public function testUnTauxAmbiguRefuseAuLieuDeChoisir(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $profil = $this->profil($em);

        // On rend ACTIF le second taux à 5,50 : deux candidats actifs, donc ambiguïté réelle.
        foreach ($em->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil->getId(), 'taux' => '5.50']) as $taux) {
            $taux->setActif(true);
        }
        $em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/5\.50/');

        $invoicer->facturer($this->echeance($em, 10550, '5.50'), $etab, $auteur);
    }

    /** @return array{0: EntityManagerInterface, 1: InstallmentInvoicer, 2: Etablissement, 3: Utilisateur} */
    private function contexte(): array
    {
        self::bootKernel();
        $conteneur = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $conteneur->get('doctrine')->getManager();

        /** @var InstallmentInvoicer $invoicer */
        $invoicer = $conteneur->get(InstallmentInvoicer::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        $auteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $auteur);

        // ⚠ L'ÉMISSION DATE LA FACTURE AU JOUR OÙ ELLE TOURNE, pas à la date d'échéance : seule
        //    l'échéance de PAIEMENT dérive de cette dernière. Il faut donc une période comptable
        //    ouverte couvrant AUJOURD'HUI, sans quoi `periodePour()` refuse — ce qui est exactement
        //    ce qui se passera en production si l'exploitant n'a pas ouvert son exercice courant.
        $this->periodeOuvranteAujourdHui($em);

        return [$em, $invoicer, $etab, $auteur];
    }

    private function periodeOuvranteAujourdHui(EntityManagerInterface $em): void
    {
        $profil = $this->profil($em);
        $aujourdHui = new \DateTimeImmutable('today');

        foreach ($em->getRepository(\App\Compta\Entity\PeriodeComptable::class)->findBy(['profilExploitant' => $profil->getId()]) as $existante) {
            if ($existante->couvre($aujourdHui)) {
                return;
            }
        }

        $periode = new \App\Compta\Entity\PeriodeComptable();
        $periode->setProfilExploitant($profil);
        $periode->setDateDebut($aujourdHui->modify('first day of January'));
        $periode->setDateFin($aujourdHui->modify('last day of December'));
        $periode->setStatut(\App\Compta\Enum\StatutPeriode::Ouverte);
        $em->persist($periode);
        $em->flush();
    }

    private function profil(EntityManagerInterface $em): ProfilExploitant
    {
        $profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => \App\Compta\DataFixtures\ComptaFixtures::PROFIL_SIREN]);
        self::assertInstanceOf(ProfilExploitant::class, $profil);

        return $profil;
    }

    private function poserTauxDefaut(EntityManagerInterface $em, Etablissement $etab, ?string $valeur): void
    {
        $profil = $this->profil($em);
        $parametre = $em->getRepository(\App\Facturation\Entity\ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profil->getId()]);

        if ($parametre === null) {
            $parametre = new \App\Facturation\Entity\ParametreFacturationEtablissement();
            $parametre->setProfilExploitant($profil);
            $em->persist($parametre);
        }

        $taux = $valeur === null
            ? null
            : $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $profil->getId(), 'taux' => $valeur, 'actif' => true]);
        $parametre->setTauxTvaDefaut($taux);

        // ⚠ SECONDE PRECONDITION, DECOUVERTE EN FAISANT ECHOUER CE TEST : `EmettreFactureDirecteHandler`
        //    refuse aussi sans COMPTE DE PRODUIT. Il se resout par une categorie comptable mappee sur la
        //    ligne, ou par ce defaut d'etablissement. La preproduction le porte (1/1) ; les fixtures non.
        if ($parametre->getCompteProduitDefaut() === null) {
            $compte = $em->getRepository(\App\Compta\Entity\CompteComptable::class)
                ->findOneBy(['profilExploitant' => $profil->getId(), 'numero' => '706100']);
            self::assertInstanceOf(\App\Compta\Entity\CompteComptable::class, $compte);
            $parametre->setCompteProduitDefaut($compte);
        }

        $em->flush();
    }

    private function echeance(EntityManagerInterface $em, int $montantCentimes, ?string $taux = null): EcheanceSepaDue
    {
        $mandat = $em->getRepository(MandatSepa::class)->createQueryBuilder('m')
            ->where('m.client IS NOT NULL')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(MandatSepa::class, $mandat, 'Les fixtures doivent porter un mandat rattaché à un client.');

        return new EcheanceSepaDue(
            referenceOrigine: 'ECH-' . bin2hex(random_bytes(8)),
            mandatId: $mandat->getId(),
            montantCentimes: $montantCentimes,
            libelle: 'Abonnement fitness — mensualité',
            dateEcheance: new \DateTimeImmutable('2026-08-15'),
            tauxTvaValeur: $taux,
        );
    }
}
