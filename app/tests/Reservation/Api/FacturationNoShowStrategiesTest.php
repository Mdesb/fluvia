<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\DataFixtures\SocleFixtures;
use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Sepa\ReservationEcheanceSepaSource;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Tests\Reservation\Support\NoShowBillingScenarios;
use App\Vente\DataFixtures\VenteFixtures;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stratégies de facturation no-show (décision structurante n°4 du plan, CA-11/12).
 * Réellement branchées : vente_differee_agent, debit_pmv. Squelettes documentés : prelevement_differe,
 * facture_a_encaisser. §7 cas limite : aucune RegleAnnulation active -> aucune facturation.
 */
final class FacturationNoShowStrategiesTest extends ReservationApiTestCase
{
    use NoShowBillingScenarios;

    public function testCa11VenteDiffereeAgentEmiseParAgent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::VenteDiffereeAgent);

        // Une session est déjà ouverte (créée par `creerFacturationNoShow` pour la vente à l'unité de
        // la réservation initiale, RG-M2-01 : une seule session active par point de vente) : réutilisée
        // par l'agent pour émettre la vente no-show.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $sessionOuverte = $em->getRepository(SessionCaisse::class)->findOneBy(['pointDeVente' => $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => VenteFixtures::PDV_LIBELLE])]);
        self::assertNotNull($sessionOuverte);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/emettre-vente', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionOuverte->getId()],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('facturee', $donnees['statut'], 'CA-11 : la FacturationNoShow passe à facturée.');
        self::assertNotNull($donnees['venteRattachee'] ?? null);

        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation->getVenteRattachee(), 'CA-11 : une vente M2 est traçable jusqu\'à la réservation d\'origine.');
        self::assertInstanceOf(Vente::class, $em->getRepository(Vente::class)->find($facturation->getVenteRattachee()->getId()));
    }

    /**
     * Non-régression de l'IDOR n°5 (D8, corrigé le 22/08), trouvé par claude-C en auditant la ligne de
     * base du garde-fou de cloisonnement.
     *
     * **Le défaut.** La session de caisse arrive dans le **corps de la requête** et était résolue par un
     * `find()` direct. `security: "is_granted('PERM', 'reservation.facturer')"` couvre l'opération, et
     * `read: true` protège bien la `FacturationNoShow` — mais la session n'en dépend pas et échappait
     * donc à tout contrôle. La suite du traitement vérifiait que la session existe et qu'elle est
     * **ouverte** (RG-M2-01), jamais **à qui elle appartient**.
     *
     * **La conséquence.** Un agent portant `reservation.facturer` sur A, connaissant l'UUID d'une
     * session ouverte de B, encaissait une vente no-show dans la caisse de B.
     *
     * **Le montage.** On réutilise une session réellement ouverte, puis on ne change **que son
     * établissement**. Tout le reste — route, permissions, état de la session — est identique au cas
     * légitime testé plus haut : ce qui isole exactement la propriété vérifiée.
     *
     * **Deux assertions, pas une.** Le 404 (et non 403, qui ferait de la route un oracle
     * d'énumération), et surtout **l'absence de vente** : le refus doit être total, pas partiel.
     */
    public function testCa11SessionDunAutreEtablissementEstIntrouvableEtRienNestEncaisse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::VenteDiffereeAgent);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $sessionOuverte = $em->getRepository(SessionCaisse::class)->findOneBy([
            'pointDeVente' => $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => VenteFixtures::PDV_LIBELLE]),
        ]);
        self::assertNotNull($sessionOuverte);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);
        $sessionOuverte->setEtablissement($etabB);
        $em->flush();

        $reponse = $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/emettre-vente', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionOuverte->getId()],
        ]);

        self::assertSame(
            404,
            $reponse->getStatusCode(),
            'Une session hors périmètre doit être introuvable, jamais interdite : ' . (string) $reponse->getContent(false),
        );

        $em->clear();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation);
        self::assertNull(
            $facturation->getVenteRattachee(),
            'Aucune vente ne doit avoir été encaissée dans la caisse d\'un autre établissement.',
        );
    }

    public function testCa11DebitPmvAutomatiqueSansAgent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Le bénéficiaire payeur dispose d'un PMV crédité (50 €, CrmFixtures) -> débit auto sans agent.
        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::DebitPmv, montant: '10.00');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertSame('facturee', $facturation->getStatut()->value, 'CA-11 : débit PMV automatique, sans agent présent.');
        self::assertNotNull($facturation->getVenteRattachee());
    }

    public function testPrelevementDiffereGenereEcheanceSepa(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Le bénéficiaire payeur dispose d'un mandat SEPA actif (SepaFixtures) -> échéance générée.
        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::PrelevementDiffere);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/emettre-vente', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation->getReferenceEcheanceSepa(), 'Squelette : échéance SEPA générée.');
        self::assertSame('a_facturer', $facturation->getStatut()->value, 'Squelette documenté : aucun encaissement garanti (statut inchangé, spec §8).');

        /** @var ReservationEcheanceSepaSource $source */
        $source = static::getContainer()->get(ReservationEcheanceSepaSource::class);
        $etablissement = $facturation->getReservation()->getEtablissement();
        $echeances = $source->echeancesDues($etablissement, new \DateTimeImmutable());
        $references = array_map(static fn ($e) => $e->referenceOrigine, $echeances);
        self::assertContains($idFacturation, $references, 'L\'échéance est exposée via le port EcheanceSepaSource (plan §3/§5).');
    }

    public function testCa12ExonerationTraceeSansEncaissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::VenteDiffereeAgent);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/exonerer', $entete + [
            'json' => ['motif' => 'Membre exonéré (1ʳᵉ occurrence tolérée).'],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('exoneree', $donnees['statut'], 'CA-12 : exonération sans encaissement.');
        self::assertNull($donnees['venteRattachee'] ?? null);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation->getExonerePar(), 'CA-12 : l\'exonération est tracée (auteur).');
        self::assertNotNull($facturation->getMotifExoneration());
    }

    public function testAucuneRegleActiveAucuneFacturation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        // Désactive la règle établissement de démonstration : aucune règle active ne couvre le créneau.
        foreach ($em->getRepository(RegleAnnulation::class)->findAll() as $regle) {
            $regle->setActif(false);
        }
        $em->flush();

        $idCreneau = $this->creerCreneauHorsDelai($client, $entete);
        $idReservation = $this->reserverGratuit($client, $entete, $idCreneau);

        // Sans RegleAnnulation active, aucun délai franc n'est défini : l'annulation reste gratuite
        // (§7 cas limite, comportement historique — la moins agressive des options possibles).
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('annulee_libre', $client->getResponse()->toArray()['statut']);

        // Bascule no-show automatique (créneau non annulé, sans présence) : même en no-show, sans
        // règle active, aucune FacturationNoShow n'est créée. Décalage horaire pour éviter un
        // chevauchement de ressource avec le créneau précédent.
        $idCreneau2 = $this->creerCreneauHorsDelai($client, $entete, 180);
        $idReservation2 = $this->reserverGratuit($client, $entete, $idCreneau2);

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer((new \DateTimeImmutable())->modify('+5 hours'));

        $em->clear();
        $reservationApres = $em->getRepository(Reservation::class)->find($idReservation2);
        self::assertSame('no_show_facture', $reservationApres->getStatut()->value, 'Le statut réservation reflète le no-show même sans règle (point de vigilance UX documenté, plan §9).');
        $facturations = $em->getRepository(FacturationNoShow::class)->findBy(['reservation' => $reservationApres]);
        self::assertEmpty($facturations, '§7 : aucune RegleAnnulation active -> aucune FacturationNoShow créée (comportement historique).');
    }
}
