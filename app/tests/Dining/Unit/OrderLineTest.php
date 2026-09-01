<?php

declare(strict_types=1);

namespace App\Tests\Dining\Unit;

use App\Dining\Domain\CourseRef;
use App\Dining\Domain\LineStatus;
use App\Dining\Domain\OrderLine;
use PHPUnit\Framework\TestCase;

/**
 * Le cycle de vie d'une ligne de commande (ACT-4). Ces tests figent la frontière du module :
 * ce qui précède l'envoi en cuisine s'efface, ce qui le suit se constate.
 */
final class OrderLineTest extends TestCase
{
    private function ligne(string $service = 'plat', int $rang = 2): OrderLine
    {
        return new OrderLine(CourseRef::of($service, $rang), 'Entrecote', 2, '24.50');
    }

    private function midi(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-01 12:30:00');
    }

    public function testUneLigneNaitAuBrouillonEtSEfface(): void
    {
        $ligne = $this->ligne();

        self::assertSame(LineStatus::Draft, $ligne->status());
        self::assertTrue($ligne->isRemovable(), 'Rien n\'est engage avant l\'envoi.');
        self::assertFalse($ligne->hasConsumed());
    }

    public function testEnvoyerDeuxFoisEstRefuse(): void
    {
        // **La regle la plus concrete du module** : un service envoye deux fois, ce sont deux fois les
        // plats qui sortent. Le cuisinier ne peut pas savoir que le second bon est un doublon.
        $ligne = $this->ligne()->fire($this->midi());

        $this->expectException(\LogicException::class);
        $ligne->fire($this->midi());
    }

    public function testUneLigneEnvoyeeNeSeRetirePlus(): void
    {
        $ligne = $this->ligne()->fire($this->midi());

        self::assertFalse($ligne->isRemovable(), 'Apres l\'envoi, la matiere est engagee.');
        self::assertTrue($ligne->hasConsumed());
    }

    public function testAnnulerApresEnvoiExigeUnMotif(): void
    {
        $ligne = $this->ligne()->fire($this->midi());

        // Le motif distingue l'erreur de saisie du plat renvoye par le client, et ces deux-la ne se
        // comprennent pas pareil au moment d'expliquer une perte en fin de mois.
        $this->expectException(\InvalidArgumentException::class);
        $ligne->void('   ');
    }

    public function testUnBrouillonNeSAnnulePas(): void
    {
        $this->expectException(\LogicException::class);
        $this->ligne()->void('erreur de saisie');
    }

    public function testUneLigneAnnuleeSortDeLAdditionMaisResteConsommee(): void
    {
        $ligne = $this->ligne()->fire($this->midi());
        $ligne->void('Plat renvoye par le client');

        // **Le coeur de la regle.** La faire disparaitre ferait mentir a la fois la note et le stock —
        // et c'est le stock qui ne se rattrape pas.
        self::assertFalse($ligne->isBillable());
        self::assertTrue($ligne->hasConsumed());
        self::assertSame('Plat renvoye par le client', $ligne->voidReason());
    }

    public function testOnNeSertQueCeQuiEstParti(): void
    {
        $this->expectException(\LogicException::class);
        $this->ligne()->serve();
    }

    public function testUneQuantiteNulleNaPasDeSens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderLine(CourseRef::of('plat', 2), 'Entrecote', 0, '24.50');
    }
}
