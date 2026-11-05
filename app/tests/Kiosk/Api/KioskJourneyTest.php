<?php

declare(strict_types=1);

namespace App\Tests\Kiosk\Api;

use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Kiosk\DataFixtures\KioskFixtures;
use App\Kiosk\Port\BadgeEncoderInterface;
use App\Kiosk\Port\BadgeEncodingResult;
use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Enum\KioskDelivery;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Kiosk\KioskTestTrait;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutTPE;
use App\Vente\Enum\StatutVente;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalOutcomeSink;
use App\Vente\Tpe\TpeMock;
use Symfony\Component\Uid\Uuid;

/**
 * La borne automatique de vente (#341), de bout en bout, avec le terminal SIMULÉ : appairage, offre,
 * commande, paiement, délivrance — et ce qui ne doit JAMAIS arriver : un titre délivré sans paiement
 * accepté, une borne qui touche la commande d'une autre, un jeton de borne qui ouvre le back-office.
 */
final class KioskJourneyTest extends VenteApiTestCase
{
    use KioskTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadKioskFixtures();
    }

    /** Le parcours complet : choisir, payer (terminal simulé, accepté), recevoir son ticket. */
    public function testFullJourneyWithSimulatedTerminalDeliversATicket(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);

        [$status, $config] = $this->device($client, $token, 'GET', '/config');
        self::assertSame(200, $status);
        self::assertTrue($config['terminalReady']);
        $offer = $this->offerOf($config, OffreFixtures::PRODUIT_ENTREE, OffreFixtures::TARIF_PLEIN);

        [$status, $order] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $offer, 'quantity' => 2]]]);
        self::assertSame(201, $status, json_encode($order));
        self::assertGreaterThan(0, (float) $order['total']);
        $saleId = $order['order'];

        // La vente est sur le point de vente de la borne, sans session : jamais d'espèces (D44-bis).
        $sale = $this->sale($saleId);
        self::assertNull($sale->getSession());
        self::assertSame(KioskFixtures::POS_A_LABEL, $sale->getPointDeVente()?->getLibelle());

        [$status, $paid] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        self::assertSame(200, $status, json_encode($paid));
        self::assertSame('accepted', $paid['status']);

        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status, json_encode($done));
        self::assertTrue($done['printTicket']);
        self::assertFalse($done['ticket']['duplicata']);
        self::assertNotEmpty($done['ticket']['supports'], 'Une entrée vendue à la borne émet son support (QR du ticket).');
        self::assertNotNull($done['ticket']['codeSupport']);
        self::assertSame($order['total'], $done['ticket']['total']);

        $sale = $this->sale($saleId);
        self::assertSame(StatutVente::Validee, $sale->getStatut());
        self::assertTrue($sale->isImprime(), 'La borne imprime : une réédition au guichet dira DUPLICATA.');
        self::assertNotNull($this->sealedOperationOf($saleId), 'La vente est scellée NF525 sur la chaîne de la borne.');
        self::assertNotNull($this->order($saleId)->getCompletedAt());

        // Rejouée (réseau coupé pendant la réponse) : le même document, marqué duplicata, aucun second support.
        $supports = \count($done['ticket']['supports']);
        [$status, $again] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status);
        self::assertTrue($again['ticket']['duplicata']);
        self::assertCount($supports, $again['ticket']['supports']);
    }

    /** UN PAIEMENT REFUSÉ NE DÉLIVRE RIEN : ni support, ni scellement, ni recharge. */
    public function testRefusedPaymentDeliversNothing(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        $saleId = $this->openEntryOrder($client, $token);

        $this->forceTerminal(StatutTPE::Refuse);
        [$status, $paid] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        self::assertSame(200, $status, json_encode($paid));
        self::assertSame('refused', $paid['status']);

        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(409, $status, json_encode($done));

        $sale = $this->sale($saleId);
        self::assertSame(StatutVente::EnCours, $sale->getStatut());
        self::assertCount(0, $this->kioskEm()->getRepository(BilletSupport::class)->findBy(['vente' => $sale]), 'Aucun titre émis sans paiement.');
        self::assertNull($this->sealedOperationOf($saleId), 'Aucune écriture NF525 sans paiement.');
        self::assertNull($this->order($saleId)->getCompletedAt());

        // L'usager abandonne : le panier se vide, rien n'est dû, rien n'est délivré.
        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/abandon');
        self::assertSame(200, $status);
        self::assertCount(0, $this->sale($saleId)->getLignes());
        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(409, $status, 'Une commande abandonnée ne délivre plus rien.');
    }

    /** Le terminal attend le code de l'usager : rien n'est délivré pendant l'attente, tout l'est après. */
    public function testWaitingTerminalDeliversOnlyOnceAccepted(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        $saleId = $this->openEntryOrder($client, $token);

        [$status, $paid] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()], [TpeMock::HEADER_SIMULATION => TpeMock::NO_ANSWER]);
        self::assertSame(202, $status, json_encode($paid));
        self::assertSame('waiting', $paid['status']);
        $attempt = $paid['attempt']['id'];

        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(409, $status, 'Pendant l\'attente du terminal, rien n\'est délivré.');
        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/abandon');
        self::assertSame(409, $status, 'On n\'abandonne pas une commande dont la carte est peut-être débitée.');

        $sink = static::getContainer()->get(TerminalOutcomeSink::class);
        self::assertInstanceOf(TerminalOutcomeSink::class, $sink);
        $sink->record(str_replace('-', '', $attempt), new ResultatTpe(StatutTPE::Accepte, 'TX-KIOSK', ticketClient: "CARTE BANCAIRE\nDEBIT"));

        [$status, $poll] = $this->device($client, $token, 'GET', '/orders/' . $saleId . '/payments/' . $attempt);
        self::assertSame(200, $status);
        self::assertSame('accepted', $poll['status']);

        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status, json_encode($done));
        self::assertSame("CARTE BANCAIRE\nDEBIT", $done['ticket']['ticketClientTerminal'], 'Le ticket carte du terminal sort avec le ticket.');
    }

    /** Deux bornes, deux établissements : chacune ne touche qu'à SES commandes ; la propriétaire, elle, délivre. */
    public function testAKioskCannotTouchAnotherKiosksOrder(): void
    {
        [$client, $staffA] = $this->adminSurA();
        $client->disableReboot();
        $staffB = ['auth_bearer' => $staffA['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];
        $tokenA = $this->pairDevice($client, $staffA, KioskFixtures::KIOSK_A_LABEL);
        $tokenB = $this->pairDevice($client, $staffB, KioskFixtures::KIOSK_B_LABEL);

        $saleId = $this->openEntryOrder($client, $tokenA);
        foreach ([['POST', '/pay', ['key' => (string) Uuid::v4()]], ['POST', '/complete', null], ['POST', '/abandon', null]] as [$method, $suffix, $json]) {
            [$status] = $this->device($client, $tokenB, $method, '/orders/' . $saleId . $suffix, $json);
            self::assertSame(404, $status, 'La borne B ne voit pas la commande de la borne A : ' . $suffix);
        }
        self::assertCount(0, $this->sale($saleId)->getPaiements(), 'Aucun paiement n\'est parti depuis la borne B.');

        // Témoin : la borne propriétaire, elle, paie et délivre.
        [$status] = $this->device($client, $tokenA, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        self::assertSame(200, $status);
        [$status] = $this->device($client, $tokenA, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status);

        // Et l'agent de A ne voit pas la borne de B.
        $list = $client->request('GET', '/api/kiosks', $staffA)->toArray();
        $labels = array_column($list['member'] ?? $list['hydra:member'] ?? [], 'label');
        self::assertContains(KioskFixtures::KIOSK_A_LABEL, $labels);
        self::assertNotContains(KioskFixtures::KIOSK_B_LABEL, $labels);
        $client->request('GET', '/api/kiosks/' . $this->kiosk(KioskFixtures::KIOSK_B_LABEL)->getId(), $staffA);
        self::assertResponseStatusCodeSame(404);
    }

    /** Le jeton d'une borne n'ouvre AUCUNE route du back-office ; un compte du personnel n'ouvre pas la borne. */
    public function testKioskTokenAndStaffTokenStayInTheirLane(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);

        foreach (['/api/ventes', '/api/kiosks', '/me', '/api/point_de_ventes'] as $path) {
            $client->request('GET', $path, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]]);
            self::assertResponseStatusCodeSame(401, 'Le jeton de borne ne doit rien ouvrir hors /api/kiosk/ : ' . $path);
        }

        [$status] = $this->device($client, $staff['auth_bearer'], 'GET', '/config');
        self::assertSame(401, $status, 'Un JWT du personnel n\'est pas un jeton de borne.');

        // Témoin : le même jeton de borne, sur sa route, est accepté.
        [$status] = $this->device($client, $token, 'GET', '/config');
        self::assertSame(200, $status);
    }

    /** L'offre : le canal « borne », un tarif visible à la borne, jamais un produit nominatif. */
    public function testOfferFollowsTheKioskChannel(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        [, $config] = $this->device($client, $token, 'GET', '/config');

        $pairs = array_map(static fn (array $o): string => $o['label'] . ' / ' . $o['tariffLabel'], $config['offers']);
        self::assertContains(OffreFixtures::PRODUIT_ENTREE . ' / ' . OffreFixtures::TARIF_PLEIN, $pairs);
        self::assertNotContains(OffreFixtures::PRODUIT_ENTREE . ' / ' . OffreFixtures::TARIF_GUICHET, $pairs, 'Un tarif « guichet » ne se vend pas à la borne.');
        self::assertNotContains(OffreFixtures::PRODUIT_GOLD . ' / ' . OffreFixtures::TARIF_PLEIN, $pairs, 'Ni un produit hors canal « borne », ni un produit nominatif.');

        // Une offre fabriquée (le tarif guichet) est refusée à l'ouverture, avant tout débit.
        $entry = $this->idProduit(OffreFixtures::PRODUIT_ENTREE);
        $counterTariff = $this->idTarif(OffreFixtures::TARIF_GUICHET);
        [$status] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $entry . '|' . $counterTariff, 'quantity' => 1]]]);
        self::assertSame(422, $status);
    }

    /** Sans terminal piloté (le terminal externe attend un caissier), la borne est hors service et le dit. */
    public function testExternalTerminalPutsTheKioskOutOfService(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        $em = $this->kioskEm();
        $pos = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => KioskFixtures::POS_A_LABEL]);
        self::assertInstanceOf(PointDeVente::class, $pos);
        $pos->setTpe(['connecteur' => 'externe']);
        $em->flush();

        [, $config] = $this->device($client, $token, 'GET', '/config');
        self::assertFalse($config['terminalReady']);
        [$status, $body] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $this->offerOf($config, OffreFixtures::PRODUIT_ENTREE, OffreFixtures::TARIF_PLEIN), 'quantity' => 1]]]);
        self::assertSame(409, $status);
        self::assertSame('kiosk_out_of_service', $body['code']);
    }

    /** Sans encodeur de badges raccordé (#337), la borne le dit et imprime le ticket : l'usager repart avec un titre. */
    public function testBadgeDeliveryFallsBackToTheTicketWhenNoEncoderIsConnected(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->switchToBadgeDelivery($client, $staff);

        $saleId = $this->openEntryOrder($client, $token);
        $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status, json_encode($done));
        self::assertSame(BadgeEncodingResult::UNAVAILABLE, $done['badge']['status']);
        self::assertNotEmpty($done['badge']['message']);
        self::assertTrue($done['printTicket'], 'Sans badge, le ticket sort.');
    }

    /** Avec un encodeur qui répond (doublure du pilote #337) : le badge est remis, aucun ticket papier ne sort. */
    public function testBadgeDeliveryWithAnEncoderSkipsThePaperTicket(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $encoded = new \ArrayObject();
        static::getContainer()->set(BadgeEncoderInterface::class, new class($encoded) implements BadgeEncoderInterface {
            public function __construct(private readonly \ArrayObject $encoded)
            {
            }

            public function encode(Kiosk $kiosk, BilletSupport $support): BadgeEncodingResult
            {
                $this->encoded[] = $support->getIdentifiantSupport();

                return BadgeEncodingResult::issued('UID-' . $support->getIdentifiantSupport());
            }
        });
        $token = $this->switchToBadgeDelivery($client, $staff);

        $saleId = $this->openEntryOrder($client, $token);
        $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status, json_encode($done));
        self::assertSame(BadgeEncodingResult::ISSUED, $done['badge']['status']);
        self::assertFalse($done['printTicket']);
        self::assertSame([$done['ticket']['codeSupport']], $encoded->getArrayCopy(), 'L\'encodeur a reçu le support vendu, et lui seul.');
    }

    /** @param array<string, mixed> $staff */
    private function switchToBadgeDelivery(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $staff): string
    {
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        $client->request('PATCH', '/api/kiosks/' . $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getId(), [
            'auth_bearer' => $staff['auth_bearer'],
            'headers' => $staff['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['delivery' => KioskDelivery::Badge->value],
        ]);
        self::assertResponseIsSuccessful();

        return $token;
    }

    private function openEntryOrder(\ApiPlatform\Symfony\Bundle\Test\Client $client, string $token): string
    {
        [, $config] = $this->device($client, $token, 'GET', '/config');
        [$status, $order] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $this->offerOf($config, OffreFixtures::PRODUIT_ENTREE, OffreFixtures::TARIF_PLEIN), 'quantity' => 1]]]);
        self::assertSame(201, $status, json_encode($order));

        return $order['order'];
    }

    private function sealedOperationOf(string $saleId): ?OperationScellee
    {
        return $this->kioskEm()->getRepository(OperationScellee::class)->findOneBy(['cibleId' => Uuid::fromString($saleId)]);
    }
}
