<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\CardRechargeHandler;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Service\ApplyNoShowCreditIssueHandler;
use App\Reservation\Service\StockCardCreditHandler;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use Symfony\Component\Uid\Uuid;

/**
 * Les quatre chemins en SQL direct (passage, recharge, débit/restitution de carte, no-show) font
 * avancer la version de TOUS les supports du droit.
 *
 * Ils en faisaient déjà avancer UN : celui qu'ils avaient sous la main, ou celui que rendait un
 * `findOneBy()` sans ordre sur une clé UUID. Un même droit peut être porté par un QR et par un badge ;
 * le second gardait l'ancien solde sur la borne. D'où un droit à DEUX supports dans chaque test, et
 * les deux vérifiés quand le support touché était tiré au hasard — sinon le rouge n'apparaît qu'une
 * fois sur deux.
 */
final class SnapshotDeltaSqlPathsTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    // --- Les quatre chemins en SQL direct, sur un droit à deux supports -----------------------------

    public function testPassageEnLigneDecrementeLeCreditDuSecondSupport(): void
    {
        [, [$scanne, $autre]] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 2, 5);
        $curseur = $this->snapshotCursor();

        [$client, $entete] = $this->adminSurA();
        $reponse = $client->request('POST', '/api/acces/passages', $entete + ['json' => [
            'equipement' => '/api/equipements/' . $this->idEquipement(),
            'identifiantSupport' => $scanne,
        ]]);
        self::assertSame('valide', $reponse->toArray()['resultat'], (string) $reponse->getContent(false));

        self::assertSame(4, $this->assertInDelta($curseur, $scanne, 'ValidationPassageHandler (support scanné)')['compostagesRestants']);
        self::assertSame(4, $this->assertInDelta($curseur, $autre, 'ValidationPassageHandler (second support)')['compostagesRestants']);
    }

    public function testRechargeCarteAtteintLeSecondSupport(): void
    {
        [, [$recharge, $autre]] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 2, 5);
        $billetSupport = $this->creerBilletSupport($recharge);
        $curseur = $this->snapshotCursor();

        /** @var CardRechargeHandler $handler */
        $handler = static::getContainer()->get(CardRechargeHandler::class);
        $handler->recharge($this->snapshotEm()->getRepository(\App\Vente\Entity\BilletSupport::class)->find($billetSupport), 3);

        self::assertSame(8, $this->assertInDelta($curseur, $autre, 'CardRechargeHandler (second support)')['compostagesRestants']);
    }

    public function testDebitEtRestitutionDeCarteAtteignentLeSecondSupport(): void
    {
        // Les DEUX supports sont vérifiés : l'ancien code faisait avancer celui que rendait un
        // `findOneBy()` sans ordre sur une clé UUID — l'un ou l'autre selon le tirage. Vérifier un
        // seul support aurait donné un rouge une fois sur deux.
        [$droitId, $supports] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 2, 5);

        $curseur = $this->snapshotCursor();
        static::getContainer()->get(StockCardCreditHandler::class)->debiter(Uuid::fromString($droitId), $this->snapshotEtablissementA());
        foreach ($supports as $identifiant) {
            self::assertSame(4, $this->assertInDelta($curseur, $identifiant, 'StockCardCreditHandler::debiter')['compostagesRestants']);
        }

        $curseur = $this->snapshotCursor();
        static::getContainer()->get(StockCardCreditHandler::class)->restituer(Uuid::fromString($droitId));
        foreach ($supports as $identifiant) {
            self::assertSame(5, $this->assertInDelta($curseur, $identifiant, 'StockCardCreditHandler::restituer')['compostagesRestants']);
        }
    }

    public function testRestitutionNoShowAtteintLeSecondSupport(): void
    {
        [$droitId, $supports] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 2, 5);
        $reservation = $this->createConfirmedReservation();
        $reservation->setCreditDroitRef(Uuid::fromString($droitId));
        $this->snapshotEm()->flush();
        $curseur = $this->snapshotCursor();

        $reservation = $this->snapshotEm()->getRepository(Reservation::class)->find($reservation->getId());
        static::getContainer()->get(ApplyNoShowCreditIssueHandler::class)->apply($reservation, IssueCreditNoShow::Restored);

        foreach ($supports as $identifiant) { // les deux : même tirage `findOneBy()` que ci-dessus
            self::assertSame(6, $this->assertInDelta($curseur, $identifiant, 'ApplyNoShowCreditIssueHandler')['compostagesRestants']);
        }
    }

    // --- Échafaudages ---------------------------------------------------------------------------

    private function creerBilletSupport(string $identifiant): Uuid
    {
        $em = $this->snapshotEm();
        $etab = $this->snapshotEtablissementA();
        $admin = $em->getRepository(\App\Securite\Entity\Utilisateur::class)->findOneBy(['email' => \App\DataFixtures\SocleFixtures::ADMIN_EMAIL]);

        $pdv = (new \App\Caisse\Entity\PointDeVente())->setLibelle('PDV delta')->setEtablissement($etab);
        $em->persist($pdv);
        $caisse = (new \App\Caisse\Entity\Caisse())->setLibelle('Caisse delta')->setPointDeVente($pdv)->setEtat(\App\Caisse\Enum\EtatCaisse::Ouverte);
        $em->persist($caisse);
        $session = (new \App\Caisse\Entity\SessionCaisse())->setNumero('S-DELTA-' . uniqid())->setPointDeVente($pdv)->setCaisse($caisse)
            ->setRegisseur($admin)->setOperateur($admin)->setFondDeCaisse('0.00')->setEtablissement($etab);
        $em->persist($session);
        $vente = (new \App\Vente\Entity\Vente())->setNumero('V-DELTA-' . uniqid())->setSession($session)->setEtablissement($etab);
        $em->persist($vente);
        $billet = (new \App\Vente\Entity\BilletSupport())->setVente($vente)->setType(\App\Vente\Enum\TypeSupport::Billet)->setIdentifiantSupport($identifiant);
        $em->persist($billet);
        $em->flush();

        return $billet->getId();
    }
}
