<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Crm\Entity\Client;
use App\Crm\Event\PassageMajoriteEvent;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\Legacy\LegacyEventBridge;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Event\AccesRedevableChangeEvent;
use App\Recouvrement\Event\IncidentImpayeDetecteEvent;
use App\Recouvrement\Event\IncidentImpayeReouvertureForceeEvent;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * PLAT-3 — le pont republie les événements historiques sous leur nom de contrat, **sans jamais mettre
 * en danger l'action métier qui les a déclenchés**.
 *
 * Les deux propriétés qui comptent ici ne sont pas « ça republie » mais : le tenant vient du sujet
 * (D6), et une défaillance du bus ne remonte pas à l'émetteur. La première est une règle de
 * cloisonnement, la seconde évite qu'une brique d'infrastructure fasse échouer un encaissement.
 */
final class LegacyEventBridgeTest extends TestCase
{
    public function testImpayeDetecteDevientPaymentFailed(): void
    {
        [$dispatcher, $bus] = $this->pont();
        $incident = $this->incident(4550, 'MS03');

        $dispatcher->dispatch(new IncidentImpayeDetecteEvent($incident, 4550, new \DateTimeImmutable('2026-08-19 10:00:00')));

        self::assertCount(1, $bus->publies);
        $publie = $bus->publies[0];
        self::assertSame('payment.failed', $publie->name->value);
        self::assertSame('PaymentIncident', $publie->subject->type);
        self::assertSame((string) $incident->getId(), $publie->subject->id);
        self::assertSame(4550, $publie->payload['amount_cents']);
        self::assertSame('MS03', $publie->payload['cause']);
    }

    /** D6 — le tenant se dérive du sujet, pas d'un contexte de requête. */
    public function testLeTenantVientDeLEtablissementDeLIncident(): void
    {
        [$dispatcher, $bus] = $this->pont();
        $etablissement = new Etablissement();
        $incident = $this->incident(1000, 'AM04', $etablissement);

        $dispatcher->dispatch(new IncidentImpayeDetecteEvent($incident, 1000, new \DateTimeImmutable()));

        self::assertTrue($bus->publies[0]->tenant->establishmentId->equals($etablissement->getId()));
    }

    public function testImpayeResoluDevientPaymentSucceeded(): void
    {
        [$dispatcher, $bus] = $this->pont();
        $incident = $this->incident(2500, 'MS03');

        $dispatcher->dispatch(new IncidentImpayeResoluEvent($incident, 2500, new \DateTimeImmutable(), 'resolution_1_clic'));

        self::assertSame('payment.succeeded', $bus->publies[0]->name->value);
        self::assertSame('resolution_1_clic', $bus->publies[0]->payload['origin']);
    }

    public function testReouvertureForceeDevientIncidentReopened(): void
    {
        [$dispatcher, $bus] = $this->pont();

        $dispatcher->dispatch(new IncidentImpayeReouvertureForceeEvent($this->incident(700, 'MS02')));

        self::assertSame('payment.incident_reopened', $bus->publies[0]->name->value);
        self::assertSame(700, $bus->publies[0]->payload['amount_cents']);
    }

    public function testPassageMajoriteDevientCustomerCameOfAge(): void
    {
        [$dispatcher, $bus] = $this->pont();
        $etablissement = new Etablissement();
        $client = (new Client())->setEtablissementCreation($etablissement);

        $dispatcher->dispatch(new PassageMajoriteEvent($client, ['email', 'sms']));

        $publie = $bus->publies[0];
        self::assertSame('customer.came_of_age', $publie->name->value);
        self::assertSame('Customer', $publie->subject->type);
        self::assertTrue($publie->tenant->establishmentId->equals($etablissement->getId()));
        self::assertSame(['email', 'sms'], $publie->payload['channels_to_renew']);
    }

    /**
     * Sans établissement, on ne publie pas — l'enveloppe exige un tenant réel (RG-PLAT-03) et on
     * n'invente pas un périmètre pour satisfaire le bus. Surtout : l'action métier aboutit quand même.
     */
    public function testSansEtablissementRienNestPublieEtRienNechoue(): void
    {
        [$dispatcher, $bus] = $this->pont();

        $dispatcher->dispatch(new IncidentImpayeDetecteEvent(
            $this->incidentSansEtablissement(100, 'MS03'),
            100,
            new \DateTimeImmutable(),
        ));
        $dispatcher->dispatch(new PassageMajoriteEvent(new Client(), ['email']));

        self::assertSame([], $bus->publies);
    }

    /**
     * La propriété la plus importante du lot : un bus en panne ne casse pas l'encaissement.
     *
     * Le bus est synchrone et propage les exceptions (RG-PLAT-05) ; ce pont s'exécute dans la
     * transaction d'une action métier réelle. S'il laissait remonter une erreur, une panne
     * d'infrastructure ferait échouer la détection d'un impayé.
     */
    public function testUneDefaillanceDuBusNeRemontePasALEmetteur(): void
    {
        $busEnPanne = new class implements EventBus {
            public function publish(DomainEvent $event): void
            {
                throw new \RuntimeException('bus indisponible');
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new LegacyEventBridge($busEnPanne));

        $dispatcher->dispatch(new IncidentImpayeDetecteEvent($this->incident(100, 'MS03', new Etablissement()), 100, new \DateTimeImmutable()));

        $this->expectNotToPerformAssertions();
    }

    /**
     * `AccesRedevableChangeEvent` ne porte pas d'établissement : il ne peut pas être ponté sans
     * modifier `Recouvrement`. Ce test fige ce choix — s'il tombe, c'est que quelqu'un a ajouté le
     * pontage sans traiter la question du tenant.
     */
    public function testAccesRedevableChangeNestPasPonte(): void
    {
        [$dispatcher, $bus] = $this->pont();

        $dispatcher->dispatch(new AccesRedevableChangeEvent('abonnement', 'REF-1', true));

        self::assertSame([], $bus->publies);
        self::assertArrayNotHasKey(AccesRedevableChangeEvent::class, LegacyEventBridge::getSubscribedEvents());
    }

    /** @return array{0: EventDispatcher, 1: object{publies: list<DomainEvent>}} */
    private function pont(): array
    {
        $bus = new class implements EventBus {
            /** @var list<DomainEvent> */
            public array $publies = [];

            public function publish(DomainEvent $event): void
            {
                $this->publies[] = $event;
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new LegacyEventBridge($bus));

        return [$dispatcher, $bus];
    }

    /** Un incident réel porte toujours un établissement : c'est le cas nominal, donc le défaut ici. */
    private function incident(int $montantCentimes, string $motif, ?Etablissement $etablissement = null): IncidentImpaye
    {
        return $this->incidentSansEtablissement($montantCentimes, $motif)
            ->setEtablissement($etablissement ?? new Etablissement());
    }

    /** Le cas dégradé, isolé dans son propre assistant pour qu'on ne l'obtienne jamais par distraction. */
    private function incidentSansEtablissement(int $montantCentimes, string $motif): IncidentImpaye
    {
        return (new IncidentImpaye())
            ->setMontantCentimes($montantCentimes)
            ->setMotifBancaire($motif);
    }
}
