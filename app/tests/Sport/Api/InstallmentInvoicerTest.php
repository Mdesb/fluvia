<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\InstallmentInvoice;
use App\Facturation\Entity\ReglementFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Service\InstallmentInvoicer;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Event\EcheancesCollecteesEvent;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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

    /**
     * LA COLLECTE SOLDE LA FACTURE ET FAIT ENTRER L'ARGENT AU GRAND LIVRE (chaîne-encaissement).
     *
     * ⚠ LE TROU QUE CE TÉMOIN FERME. La facture d'échéance naît « en attente de paiement » (créance
     * 411 débitée au journal FAC). Jusqu'ici, un prélèvement réussi ne produisait NI règlement NI
     * écriture d'encaissement : la créance restait débitrice alors que l'argent était rentré. On
     * simule ici la collecte par l'événement que `GenerationRemiseHandler` émet en vrai après une
     * remise transmise, et on prouve que la facture passe à `Payee`, solde nul, avec un règlement
     * portant son écriture d'encaissement (journal ENC).
     */
    public function testUneEcheanceCollecteeSoldeSaFactureEtEcritLEncaissement(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');
        $echeance = $this->echeance($em, 12000);
        $reservation = $invoicer->facturer($echeance, $etab, $auteur);
        $factureId = $reservation->getInvoiceId();
        self::assertNotNull($factureId);
        self::assertSame(StatutFacture::EnAttentePaiement, $em->getRepository(Facture::class)->find($factureId)?->getStatut());

        // La collecte réussit : on émet l'événement (mêmes arguments que le handler réel).
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new EcheancesCollecteesEvent([$echeance->referenceOrigine], 'SIMULATION-TEST'));

        $em->clear();
        $facture = $em->getRepository(Facture::class)->find($factureId);
        self::assertInstanceOf(Facture::class, $facture);
        self::assertSame(StatutFacture::Payee, $facture->getStatut(), 'La collecte doit solder la facture.');
        self::assertSame('0.00', $facture->getSoldeDu(), 'La créance doit revenir à zéro au grand livre.');

        $reglements = $em->getRepository(ReglementFacture::class)->findBy(['facture' => $factureId]);
        self::assertCount(1, $reglements, 'Un règlement, et un seul, pour la collecte.');
        self::assertNotNull($reglements[0]->getEcritureEncaissement(), 'L\'écriture d\'encaissement (journal ENC) doit exister.');

        // ⚠ IDEMPOTENCE : une collecte rejouée ne double NI le règlement NI l'écriture, et ne lève
        // pas (la facture n'est plus « en attente », on la saute avant d'appeler le handler).
        $dispatcher->dispatch(new EcheancesCollecteesEvent([$echeance->referenceOrigine], 'SIMULATION-TEST'));
        self::assertCount(
            1,
            $em->getRepository(ReglementFacture::class)->findBy(['facture' => $factureId]),
            'Un rejeu de collecte reste un no-op : toujours un seul règlement.',
        );
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

    /**
     * LE MODE À BLANC REFUSE EXACTEMENT CE QUE L'ÉMISSION REFUSE, MOT POUR MOT.
     *
     * ⚠ CE TEST EXISTE PARCE QUE LE CONTRAIRE EST ARRIVÉ. Le 14/09, `sepa:echeances:facturer
     * --dry-run --depuis=2026-09-01` a annoncé « 5 à facturer, 0 refus » ; le passage réel, lancé
     * dans la foulée, a refusé les cinq — aucune n'avait de taux de TVA. Le mode à blanc sortait
     * après le seul plancher de date, sans rien résoudre.
     *
     * ⚠ ET L'ASSERTION PORTE SUR L'ÉGALITÉ DES DEUX MESSAGES, PAS SUR « ÇA REFUSE ». Vérifier
     * seulement qu'une exception part laisserait passer une vérification qui refuse pour une AUTRE
     * raison que l'émission — un mode à blanc pessimiste, qui ferait renoncer à des facturations
     * légitimes. Ce qu'on veut n'est pas qu'il refuse, c'est qu'il dise la même chose.
     */
    public function testLaVerificationRefuseMotPourMotCeQueLEmissionRefuse(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        // Aucun taux nulle part, ni sur l'échéance ni en défaut : les deux modes doivent refuser.
        $this->poserTauxDefaut($em, $etab, null);
        $echeance = $this->echeance($em, 2990);

        $refusAblanc = null;
        try {
            $invoicer->verifier($echeance, $etab);
        } catch (\Throwable $echec) {
            $refusAblanc = $echec->getMessage();
        }

        $refusReel = null;
        try {
            $invoicer->facturer($echeance, $etab, $auteur);
        } catch (\Throwable $echec) {
            $refusReel = $echec->getMessage();
        }

        self::assertNotNull($refusAblanc, 'le mode à blanc doit refuser, pas annoncer une émission qui n\'aura pas lieu');
        self::assertNotNull($refusReel, 'précondition du test : l\'émission doit bien refuser ici');
        self::assertSame($refusReel, $refusAblanc, 'le même refus, mot pour mot — c\'est la seule garantie qui tienne');
    }

    /**
     * LA VÉRIFICATION N'ÉCRIT RIEN — Y COMPRIS PAS DE RÉSERVATION.
     *
     * Un mode à blanc qui laisserait une réservation derrière lui rendrait l'échéance « déjà connue »
     * au passage suivant, et le vrai passage la sauterait. Le mode à blanc aurait alors empêché la
     * facturation qu'il servait à préparer.
     */
    public function testLaVerificationNecritRien(): void
    {
        [$em, $invoicer, $etab] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');
        $echeance = $this->echeance($em, 2990);

        $invoicer->verifier($echeance, $etab);

        self::assertSame(
            0,
            (int) $em->getRepository(InstallmentInvoice::class)->createQueryBuilder('r')
                ->select('COUNT(r.id)')->getQuery()->getSingleScalarResult(),
            'une vérification à blanc ne doit laisser aucune réservation',
        );
    }

    /**
     * UN DESTINATAIRE SANS ADRESSE EST REFUSÉ **AVANT** TOUTE ÉCRITURE.
     *
     * ⚠ CE TEST VIENT D'UN SECOND PASSAGE RATÉ, LE 14/09, APRÈS LE PREMIER CORRECTIF. Le mode à
     * blanc — rendu honnête sur le taux la veille — annonçait « 5 à facturer, 0 refus » ; l'émission
     * a refusé les cinq pour « l'adresse du destinataire est requise » (RG-FACT-08). Les vingt
     * clients de la préproduction sont sans adresse. Le contrôle existait, il était simplement de
     * l'autre côté du mur — alors qu'il ne demande aucune écriture.
     *
     * La leçon n'est pas « il manquait ce contrôle-ci » : c'est qu'un mode à blanc se juge sur ce
     * qu'il NE traverse pas, et que cette liste doit être mesurée, pas supposée.
     */
    public function testUnDestinataireSansAdresseEstRefuseAvantToutEcriture(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');
        $echeance = $this->echeance($em, 2990);

        $mandat = $em->getRepository(MandatSepa::class)->find($echeance->mandatId);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        $client = $mandat->getClient();
        self::assertNotNull($client);
        $client->setAdresse(null);
        $em->flush();

        $avant = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM facturation_facture');

        $refusAblanc = null;
        try {
            $invoicer->verifier($echeance, $etab);
        } catch (\Throwable $echec) {
            $refusAblanc = $echec->getMessage();
        }

        $refusReel = null;
        try {
            $invoicer->facturer($echeance, $etab, $auteur);
        } catch (\Throwable $echec) {
            $refusReel = $echec->getMessage();
        }

        self::assertNotNull($refusAblanc, 'le mode à blanc doit voir l\'adresse manquante : elle se lit sans rien écrire');
        self::assertSame($refusReel, $refusAblanc, 'le même refus, mot pour mot');
        self::assertStringContainsString('adresse', (string) $refusAblanc);

        self::assertSame(
            $avant,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM facturation_facture'),
            'refusée sur le destinataire, la facture ne doit même pas avoir été ébauchée',
        );
    }

    /**
     * UNE ÉMISSION REFUSÉE PAR LE SCELLEUR NE LAISSE PAS SON BROUILLON.
     *
     * ⚠ MESURÉ SUR LA PRÉPRODUCTION LE 14/09 : cinq refus ont laissé cinq `facturation_facture` à
     * l'état brouillon, avec leurs lignes et leurs destinataires. Ni numéro ni écriture comptable —
     * donc rien de scellé — mais elles apparaissent sur la fiche du client, et chaque nouveau
     * passage en aurait ajouté cinq. `retirerReservation()` ne les emportait pas : elle ne connaît
     * que la réservation.
     *
     * Le refus employé ici vient du SCELLEUR, pas de la résolution : sans compte de produit,
     * `EmettreFactureDirecteHandler` refuse — donc après que le brouillon a été écrit. C'est la
     * seule façon d'éprouver le retrait.
     */
    public function testUneEmissionRefuseeParLeScelleurNeLaissePasDeBrouillon(): void
    {
        [$em, $invoicer, $etab, $auteur] = $this->contexte();
        $this->poserTauxDefaut($em, $etab, '20.00');

        $profil = $this->profil($em);
        $parametre = $em->getRepository(\App\Facturation\Entity\ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $profil->getId()]);
        self::assertNotNull($parametre);
        $parametre->setCompteProduitDefaut(null);
        $em->flush();

        $avant = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM facturation_facture');

        try {
            $invoicer->facturer($this->echeance($em, 2990), $etab, $auteur);
            self::fail('Sans compte de produit, le scelleur devait refuser.');
        } catch (\Throwable $echec) {
            self::assertStringContainsString('produit', $echec->getMessage());
        }

        self::assertSame(
            $avant,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM facturation_facture'),
            'le brouillon de la facture refusée doit avoir été retiré',
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
