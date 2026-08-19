<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventName;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Event\Exception\InvalidDomainEventException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'enveloppe `DomainEvent` (spec §4) : CA-2 (tenant obligatoire), CA-3 (nommage), `occurredAt` en UTC
 * et charge utile minimale (RG-PLAT-04).
 *
 * Ces tests documentent surtout une intention : un événement mal formé doit être **impossible à
 * construire**, pas seulement déconseillé. Le bus ne re-valide rien, il fait confiance au type.
 */
final class DomainEventTest extends TestCase
{
    private function tenant(): EventTenant
    {
        return new EventTenant(Uuid::v4());
    }

    private function subject(): EventSubject
    {
        return new EventSubject('SupplierInvoice', Uuid::v4()->toRfc4122());
    }

    public function testEnveloppeComplete(): void
    {
        $actorId = Uuid::v4();

        $event = new DomainEvent(
            'supplier_invoice.recorded',
            $this->tenant(),
            $this->subject(),
            ['supplier' => 'ACME', 'amount' => 1250, 'ocr' => true],
            new EventActor($actorId),
        );

        self::assertSame('supplier_invoice.recorded', $event->name->value);
        self::assertSame('supplier_invoice', $event->name->domain());
        self::assertSame('recorded', $event->name->fact());
        self::assertTrue($event->actor?->userId->equals($actorId));
        self::assertSame('SupplierInvoice', $event->subject->type);
        self::assertSame(1250, $event->payload['amount']);
    }

    /** L'absence d'acteur est une information (le système), pas un trou. */
    public function testActeurNulSignifieSysteme(): void
    {
        $event = new DomainEvent('invoice.overdue', $this->tenant(), $this->subject());

        self::assertNull($event->actor);
        self::assertSame([], $event->payload);
    }

    /** CA-3 — un nom non conforme est refusé à la construction. */
    public static function nomsInvalides(): iterable
    {
        yield 'français, PascalCase' => ['FactureEnregistree'];
        yield 'fait en majuscule' => ['finance.Recorded'];
        yield 'sans domaine' => ['recorded'];
        yield 'trois segments' => ['finance.invoice.recorded'];
        yield 'tiret' => ['supplier-invoice.recorded'];
        yield 'commence par un chiffre' => ['1finance.recorded'];
        yield 'vide' => [''];
        yield 'espace' => ['invoice. overdue'];
    }

    #[DataProvider('nomsInvalides')]
    public function testCa3NomInvalideRefuse(string $nom): void
    {
        $this->expectException(InvalidDomainEventException::class);

        new DomainEvent($nom, $this->tenant(), $this->subject());
    }

    public function testNomsDuCatalogueAcceptes(): void
    {
        foreach (['payment.failed', 'supplier_invoice.recorded', 'booking.no_show', 'credit_note.issued'] as $nom) {
            self::assertSame($nom, (new EventName($nom))->value);
        }
    }

    /**
     * CA-2 — un événement sans établissement réel est refusé.
     *
     * Le cas « pas de tenant du tout » est déjà impossible : le paramètre est typé non-nullable. Reste
     * la forme sournoise, l'UUID nil — bien typé, et pourtant il ne désigne aucun établissement.
     */
    public function testCa2TenantNilRefuse(): void
    {
        $this->expectException(InvalidDomainEventException::class);

        new EventTenant(Uuid::fromString('00000000-0000-0000-0000-000000000000'));
    }

    public function testOccurredAtNormaliseEnUtc(): void
    {
        $paris = new \DateTimeImmutable('2026-08-19 12:00:00', new \DateTimeZone('Europe/Paris'));

        $event = new DomainEvent('invoice.issued', $this->tenant(), $this->subject(), [], null, $paris);

        self::assertSame('UTC', $event->occurredAt->getTimezone()->getName());
        self::assertSame('2026-08-19T10:00:00Z', $event->toArray()['occurredAt']);
    }

    /** RG-PLAT-04 — la charge utile porte des références, jamais un secret. */
    public function testRgPlat04CleInterditeRefusee(): void
    {
        $this->expectException(InvalidDomainEventException::class);
        $this->expectExceptionMessageMatches('/Clé interdite/');

        new DomainEvent('payment.failed', $this->tenant(), $this->subject(), ['amount' => 10, 'iban' => 'FR76…']);
    }

    public function testRgPlat04CleInterditeImbriqueeRefusee(): void
    {
        $this->expectException(InvalidDomainEventException::class);

        new DomainEvent('payment.failed', $this->tenant(), $this->subject(), [
            'method' => ['type' => 'card', 'card_number' => '4242…'],
        ]);
    }

    /** Un objet dans la charge utile relierait l'abonné au code de l'émetteur (D2). */
    public function testObjetDansLaChargeUtileRefuse(): void
    {
        $this->expectException(InvalidDomainEventException::class);
        $this->expectExceptionMessageMatches('/non transportable/');

        new DomainEvent('invoice.issued', $this->tenant(), $this->subject(), ['invoice' => new \stdClass()]);
    }

    public function testSujetSansIdRefuse(): void
    {
        $this->expectException(InvalidDomainEventException::class);

        new EventSubject('Invoice', '   ');
    }

    public function testTypeDeSujetDoitEtreUnNomCourtAnglais(): void
    {
        $this->expectException(InvalidDomainEventException::class);

        new EventSubject('App\\Facturation\\Entity\\Facture', Uuid::v4()->toRfc4122());
    }

    /** L'enveloppe sérialisée doit correspondre au catalogue (`CONTRACT/catalogue-evenements.md`). */
    public function testToArraySuitLEnveloppeDuCatalogue(): void
    {
        $event = new DomainEvent('sale.completed', $this->tenant(), $this->subject(), ['amount' => 42]);

        self::assertSame(
            ['name', 'occurredAt', 'tenant', 'actor', 'subject', 'payload'],
            array_keys($event->toArray()),
        );
        self::assertNull($event->toArray()['actor']['userId']);
    }
}
