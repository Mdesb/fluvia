<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\DataFixtures\SocleFixtures;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\PublicApi\Entity\PartnerWebhookDelivery;
use App\PublicApi\Enum\DeliveryStatus;
use App\PublicApi\Webhook\DeliverPartnerWebhook;
use App\PublicApi\Webhook\DeliverPartnerWebhookHandler;
use App\PublicApi\Webhook\PartnerConsent;
use App\PublicApi\Webhook\PartnerWebhookCipher;
use App\PublicApi\Webhook\PartnerWebhookFanOut;
use App\PublicApi\Webhook\WebhookDestinationGuard;
use App\PublicApi\Webhook\WebhookSender;
use App\Tests\PublicApi\PublicApiTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La livraison des webhooks partenaires (spec API partenaire v1, §3.3, §3.5, critère 5).
 *
 * ⚠ LE TEST QUI COMPTE : un accord retiré entre deux réessais ARRÊTE la livraison. Vu rouge le 06/10 en
 * retirant la revérification du consentement du handler, dans une copie jetable.
 */
final class PartnerWebhookDeliveryTest extends PublicApiTestCase
{
    private const URL = 'https://93.184.216.34/fluvia/hook';

    /** @var list<array{body: string, headers: list<string>}> */
    private array $sent = [];

    private int $status = 204;

    /** @var \ArrayObject<int, Envelope> les messages remis en file par le handler */
    private \ArrayObject $requeued;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requeued = new \ArrayObject();
    }

    public function testSeulUnEtablissementQuiAAccordeEventsSubscribeRecoitEtLaSignatureSeVerifie(): void
    {
        ['id' => $app, 'secret' => $secret] = $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe'], SocleFixtures::ETAB_B_NOM => ['access:read']]);

        $this->publish('booking.cancelled', SocleFixtures::ETAB_A_NOM, ['slotId' => 'creneau-1', 'leadTimeMinutes' => 90, 'withinFreeWindow' => true]);
        $this->publish('booking.cancelled', SocleFixtures::ETAB_B_NOM, ['slotId' => 'creneau-2', 'leadTimeMinutes' => 10, 'withinFreeWindow' => false]);

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries, 'B n’a accordé que access:read : rien ne part pour lui');
        $this->handler(true)(new DeliverPartnerWebhook((string) $deliveries[0]->getId()));

        self::assertCount(1, $this->sent);
        $envelope = json_decode($this->sent[0]['body'], true);
        self::assertSame(['id', 'type', 'version', 'occurredAt', 'establishmentId', 'data'], array_keys($envelope));
        self::assertSame($this->idEtablissement(SocleFixtures::ETAB_A_NOM), $envelope['establishmentId']);
        self::assertSame('creneau-1', $envelope['data']['slotId']);

        $headers = implode("\n", $this->sent[0]['headers']);
        preg_match('/Fluvia-Signature: t=(\d+),v1=([0-9a-f]{64})/', $headers, $m);
        self::assertSame($m[2], hash_hmac('sha256', $m[1].'.'.$this->sent[0]['body'], $secret), 'signature vérifiable avec le secret affiché');
        self::assertNotSame($m[2], hash_hmac('sha256', $m[1].'.'.str_replace('creneau-1', 'creneau-9', $this->sent[0]['body']), $secret), 'altération détectée');
        self::assertStringContainsString('Idempotency-Key: '.$envelope['id'], $headers);
        self::assertSame(DeliveryStatus::Delivered, $this->reload($deliveries[0])->getStatus());
        self::assertNotNull($app);
    }

    /** Aucune donnée nominative, aucun identifiant interne de support : le contrat ne prend que ses champs. */
    public function testLEnveloppeNeContientAucunChampNominatif(): void
    {
        $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $supportId = (string) Uuid::v4();
        $this->publish('access.card_recharged', SocleFixtures::ETAB_A_NOM, [
            'droitId' => (string) Uuid::v4(), 'supportId' => $supportId, 'creditsAdded' => 10, 'creditBalanceAfter' => 12,
            'newExpiryAt' => '2027-01-01T00:00:00+00:00', 'saleId' => 'vente-42', 'customerName' => 'Jeanne Martin',
        ]);
        $this->publish('booking.no_show', SocleFixtures::ETAB_A_NOM, ['customerId' => 'client-7', 'amountAtRisk' => '12.00']);

        $bodies = array_map(static fn (PartnerWebhookDelivery $d): string => $d->getBody(), $this->deliveries());
        self::assertCount(2, $bodies, 'sinon les absences ci-dessous ne prouveraient rien');
        foreach (['Jeanne', 'client-7', 'vente-42', $supportId, 'droitId', 'customerId'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, implode("\n", $bodies), $forbidden);
        }
        self::assertMatchesRegularExpression('/"supportReference":"sup_[0-9a-f]{64}"/', implode("\n", $bodies));
    }

    /** Abonné en panne : chaque tentative échoue et rend la main pour un réessai ; la 6ᵉ est définitive et visible. */
    public function testUnAbonneEnPanneRecoitLesReessaisPuisLEchecEstDefinitifEtVisible(): void
    {
        ['id' => $app] = $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $this->publish('payment.failed', SocleFixtures::ETAB_A_NOM, ['amount_cents' => 4200, 'cause' => 'AM04', 'rejected_at' => '2026-10-06T08:00:00+00:00']);
        $delivery = $this->deliveries()[0];
        $this->status = 503;

        for ($attempt = 1; $attempt < DeliverPartnerWebhookHandler::MAX_ATTEMPTS; ++$attempt) {
            try {
                $this->handler(true)(new DeliverPartnerWebhook((string) $delivery->getId()));
                self::fail("La tentative $attempt aurait dû rendre la main pour un réessai.");
            } catch (\RuntimeException) {
                self::assertSame(DeliveryStatus::Pending, $this->reload($delivery)->getStatus());
            }
        }
        $this->handler(true)(new DeliverPartnerWebhook((string) $delivery->getId()));

        self::assertCount(DeliverPartnerWebhookHandler::MAX_ATTEMPTS, $this->sent, '1 envoi + 5 réessais');
        self::assertSame(DeliveryStatus::Failed, $this->reload($delivery)->getStatus());
        [$client, $headers] = $this->admin();
        $list = $client->request('GET', '/api/editor/partner-applications', $headers)->toArray()['member'];
        $webhook = array_values(array_filter($list, static fn (array $a): bool => $a['id'] === $app))[0]['webhook'];
        self::assertSame(6, $webhook['failedDeliveries'][0]['attempts'], 'l’échec définitif est visible côté éditeur');
    }

    /** ⚠ Un accord retiré entre deux réessais arrête la livraison. */
    public function testUnAccordRetireEntreDeuxReessaisArreteLaLivraison(): void
    {
        ['id' => $app] = $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $this->publish('payment.succeeded', SocleFixtures::ETAB_A_NOM, ['amount_cents' => 4200, 'origin' => 'virement', 'settled_at' => '2026-10-06T09:00:00+00:00']);
        $delivery = $this->deliveries()[0];
        $this->status = 500;
        try {
            $this->handler(true)(new DeliverPartnerWebhook((string) $delivery->getId()));
        } catch (\RuntimeException) {
        }
        self::assertCount(1, $this->sent, 'témoin : la première tentative est bien partie');

        [$client, $headers] = $this->admin();
        $client->request('POST', '/api/partner-accesses/'.$app.'/withdraw', $headers);
        self::assertResponseIsSuccessful();

        $this->status = 204;
        $this->handler(true)(new DeliverPartnerWebhook((string) $delivery->getId()));
        self::assertCount(1, $this->sent, 'accord retiré : le réessai n’est pas parti');
        $reloaded = $this->reload($delivery);
        self::assertSame(DeliveryStatus::Abandoned, $reloaded->getStatus());
        self::assertStringContainsString('events:subscribe', (string) $reloaded->getLastError());
    }

    /** Interrupteur fermé : rien ne part, le message reste en file ; à la réouverture, il est livré. */
    public function testInterrupteurFermePuisReouvert(): void
    {
        $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $this->publish('booking.no_show', SocleFixtures::ETAB_A_NOM, ['amountAtRisk' => '0.00']);
        $delivery = $this->deliveries()[0];
        $message = new DeliverPartnerWebhook((string) $delivery->getId());

        $this->handler(false)($message);
        self::assertCount(0, $this->sent);
        self::assertCount(1, $this->requeued, 'le message est remis en file, pas perdu');
        self::assertSame(DeliveryStatus::Pending, $this->reload($delivery)->getStatus());
        self::assertSame(0, $this->reload($delivery)->getAttempts(), 'fermé : aucun réessai brûlé');

        $this->handler(true)($this->requeued[0]->getMessage());
        self::assertCount(1, $this->sent);
        self::assertSame(DeliveryStatus::Delivered, $this->reload($delivery)->getStatus());
    }

    public function testLeCollecteurEstBrancheSurLeBus(): void
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        foreach (['access.card_recharged', 'booking.cancelled', 'booking.no_show', 'payment.failed', 'payment.succeeded'] as $name) {
            $listeners = array_map(static fn ($l) => \is_array($l) && \is_object($l[0]) ? $l[0]::class : '', $dispatcher->getListeners($name));
            self::assertContains(PartnerWebhookFanOut::class, $listeners, $name);
        }
    }

    /**
     * ⚠ LE JETON DE L'URL NE FUIT NULLE PART. Un VRAI échec de transport (client curl réel, port fermé)
     * vers une URL qui porte `?token=` : le jeton n'apparaît ni en base, ni dans la fiche éditeur, ni
     * dans le message de l'exception que Messenger journalise.
     */
    public function testLeJetonDeLUrlNeFuitPasDansLesErreurs(): void
    {
        ['id' => $app] = $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']], 'https://partenaire.example:9/hook?token=SECRET-TOKEN-42');
        $this->publish('booking.no_show', SocleFixtures::ETAB_A_NOM, ['amountAtRisk' => '0.00']);
        $delivery = $this->deliveries()[0];
        // Dernière tentative : l'échec devient définitif et remonte dans la fiche éditeur.
        $this->em()->getConnection()->executeStatement('UPDATE public_api_webhook_delivery SET attempts = :n', ['n' => DeliverPartnerWebhookHandler::MAX_ATTEMPTS - 1]);
        $this->em()->clear();

        $message = '';
        try {
            $this->handler(true, real: true)(new DeliverPartnerWebhook((string) $delivery->getId()));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        }

        $stored = (string) $this->reload($delivery)->getLastError();
        self::assertNotSame('', $stored, 'sinon l’absence ci-dessous ne prouverait rien');
        self::assertStringNotContainsString('SECRET-TOKEN-42', $stored, 'en base');
        self::assertStringNotContainsString('SECRET-TOKEN-42', $message, 'dans l’exception');
        [$client, $headers] = $this->admin();
        $raw = $client->request('GET', '/api/editor/partner-applications', $headers)->getContent();
        self::assertStringContainsString('"attempts":6', $raw, 'l’échec définitif est bien dans la fiche — sinon l’absence ne prouverait rien');
        self::assertStringNotContainsString('SECRET-TOKEN-42', $raw, 'dans la fiche éditeur');
        self::assertNotNull($app);
    }

    /** Une erreur AVANT l'envoi compte quand même la tentative (sinon la livraison réessaie sans fin, muette). */
    public function testUneErreurAvantLEnvoiCompteLaTentative(): void
    {
        $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $this->publish('booking.no_show', SocleFixtures::ETAB_A_NOM, ['amountAtRisk' => '0.00']);
        $delivery = $this->deliveries()[0];

        try {
            $this->handler(true, brokenCipher: true)(new DeliverPartnerWebhook((string) $delivery->getId()));
            self::fail('Le coffre illisible aurait dû lever.');
        } catch (\Throwable) {
        }
        $reloaded = $this->reload($delivery);
        self::assertSame(1, $reloaded->getAttempts());
        self::assertNotNull($reloaded->getLastError());
    }

    /** Un événement qui casse ne fait pas perdre ceux des autres établissements. */
    public function testUnEvenementEnErreurNeFaitPasPerdreLesAutres(): void
    {
        $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe'], SocleFixtures::ETAB_B_NOM => ['events:subscribe']]);
        $fanOut = static::getContainer()->get(PartnerWebhookFanOut::class);
        // UTF-8 invalide : l'enveloppe de cet événement ne se sérialise pas.
        $fanOut->collect($this->event('booking.cancelled', SocleFixtures::ETAB_A_NOM, ['slotId' => "\xB1\x31"]));
        $fanOut->collect($this->event('booking.cancelled', SocleFixtures::ETAB_B_NOM, ['slotId' => 'creneau-b']));
        $fanOut->flush();

        $types = array_map(static fn (PartnerWebhookDelivery $d): string => (string) $d->getEtablissement()->getId(), $this->deliveries());
        self::assertSame([$this->idEtablissement(SocleFixtures::ETAB_B_NOM)], $types, 'l’événement de B est livré malgré celui de A');
    }

    /** Interrupteur fermé depuis plus de 24 h : la livraison expire au lieu de tourner sans fin. */
    public function testInterrupteurFermeUneLivraisonDePlusDe24HeuresExpire(): void
    {
        $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $this->publish('booking.no_show', SocleFixtures::ETAB_A_NOM, ['amountAtRisk' => '0.00']);
        $delivery = $this->deliveries()[0];
        $this->em()->getConnection()->executeStatement('UPDATE public_api_webhook_delivery SET created_at = :old', ['old' => (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s')]);
        $this->em()->clear();

        $this->handler(false)(new DeliverPartnerWebhook((string) $delivery->getId()));

        self::assertCount(0, $this->requeued, 'plus remise en file');
        $reloaded = $this->reload($delivery);
        self::assertSame(DeliveryStatus::Abandoned, $reloaded->getStatus());
        self::assertStringContainsString('expirée', (string) $reloaded->getLastError());
    }

    /** Une livraison `pending` sans message en file (mise en file perdue) est remise en file par la relance. */
    public function testLaRelanceRemetEnFileUneLivraisonOubliee(): void
    {
        $this->subscribed([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);
        $this->publish('booking.no_show', SocleFixtures::ETAB_A_NOM, ['amountAtRisk' => '0.00']);
        $delivery = $this->deliveries()[0];
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();
        $this->em()->getConnection()->executeStatement('UPDATE public_api_webhook_delivery SET created_at = :old, queued_at = NULL', ['old' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s')]);

        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('public-api:webhooks:requeue'));
        self::assertSame(0, $tester->execute([]));

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame((string) $delivery->getId(), $sent[0]->getMessage()->deliveryId);
        self::assertSame(0, (new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('public-api:webhooks:requeue')))->execute([]), 'témoin');
        self::assertCount(1, $transport->getSent(), 'remise en file une fois, pas à chaque passage');
    }

    // ---------------------------------------------------------------- montage

    /**
     * Une application, son webhook (par l'API éditeur) et les accords donnés par l'API d'exploitant.
     *
     * @param array<string, list<string>> $grants
     *
     * @return array{id: string, secret: string}
     */
    private function subscribed(array $grants, string $url = self::URL): array
    {
        $application = $this->createApplication();
        [$client, $headers] = $this->admin();
        $secret = $client->request('POST', '/api/editor/partner-applications/'.$application['id'].'/webhook', $headers + [
            'json' => ['url' => $url, 'events' => ['access.card_recharged', 'booking.cancelled', 'booking.no_show', 'payment.failed', 'payment.succeeded']],
        ])->toArray()['issuedWebhookSecret'];
        foreach ($grants as $establishment => $scopes) {
            [$client, $headers] = $this->admin($establishment);
            $client->request('POST', '/api/partner-accesses/'.$application['id'].'/grant', $headers + ['json' => ['scopes' => $scopes]]);
            self::assertResponseIsSuccessful();
        }

        return ['id' => $application['id'], 'secret' => $secret];
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $name, string $establishment, array $payload): void
    {
        // On remet l'événement au collecteur plutôt qu'au bus : sur le bus, les AUTRES abonnés
        // (Smart Flow, notifications…) le traiteraient aussi, avec des références inventées. Le
        // branchement sur le bus est prouvé à part (`testLeCollecteurEstBrancheSurLeBus`).
        $fanOut = static::getContainer()->get(PartnerWebhookFanOut::class);
        $fanOut->collect($this->event($name, $establishment, $payload));
        $fanOut->flush();
    }

    /** @param array<string, mixed> $payload */
    private function event(string $name, string $establishment, array $payload): DomainEvent
    {
        return new DomainEvent(
            $name,
            new EventTenant(Uuid::fromString($this->idEtablissement($establishment))),
            new EventSubject('Reservation', (string) Uuid::v4()),
            $payload,
        );
    }

    /** @return list<PartnerWebhookDelivery> */
    private function deliveries(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(PartnerWebhookDelivery::class)->findBy([], ['createdAt' => 'ASC']);
    }

    private function reload(PartnerWebhookDelivery $delivery): PartnerWebhookDelivery
    {
        $this->em()->clear();

        return $this->em()->find(PartnerWebhookDelivery::class, $delivery->getId()) ?? throw new \LogicException('livraison disparue');
    }

    /** Le handler réel, avec un client HTTP simulé et un bus espion (pour voir la remise en file). */
    private function handler(bool $enabled, bool $real = false, bool $brokenCipher = false): DeliverPartnerWebhookHandler
    {
        $container = static::getContainer();
        $http = $real ? \Symfony\Component\HttpClient\HttpClient::create() : new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['body' => $options['body'], 'headers' => $options['headers']];

            return new MockResponse('', ['http_code' => $this->status]);
        });
        $bus = new class($this->requeued) implements MessageBusInterface {
            /** @param \ArrayObject<int, Envelope> $requeued */
            public function __construct(private readonly \ArrayObject $requeued)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                $this->requeued->append($envelope);

                return $envelope;
            }
        };

        return new DeliverPartnerWebhookHandler(
            $this->em(),
            $container->get(PartnerConsent::class),
            $brokenCipher ? new PartnerWebhookCipher('A_GENERER_PAR_LE_DEPLOIEMENT', 'prod') : $container->get(PartnerWebhookCipher::class),
            new WebhookSender($http, $real ? new WebhookDestinationGuard(new class implements \App\PublicApi\Webhook\HostResolver {
                public function resolve(string $host): array
                {
                    return ['93.184.216.34'];
                }
            }) : $container->get(WebhookDestinationGuard::class)),
            $bus,
            $enabled,
        );
    }
}
