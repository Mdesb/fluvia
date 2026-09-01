<?php

declare(strict_types=1);

namespace App\Tests\Dining\Unit;

use App\Dining\Domain\CourseRef;
use App\Dining\Entity\DiningOrder;
use App\Dining\Entity\DiningOrderLine;
use App\Dining\Enum\LineStatus;
use App\Organisation\Entity\Etablissement;
use PHPUnit\Framework\TestCase;

/**
 * Le cycle de vie d une ligne de commande (ACT-4). Ces tests figent la frontiere du module :
 * ce qui precede l envoi en cuisine s efface, ce qui le suit se constate.
 */
final class DiningOrderLineTest extends TestCase
{
    private function addition(): DiningOrder
    {
        return new DiningOrder(
            $this->createStub(Etablissement::class),
            'ADD-0001',
            '12',
            4,
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );
    }

    private function ligne(?DiningOrder $addition = null): DiningOrderLine
    {
        return new DiningOrderLine(
            $addition ?? $this->addition(),
            CourseRef::of('plat', 2),
            'Entrecote',
            2,
            '24.50',
        );
    }

    private function midi(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-01 12:30:00');
    }

    public function testUneLigneNaitAuBrouillonEtSEfface(): void
    {
        $ligne = $this->ligne();

        self::assertSame(LineStatus::Draft, $ligne->getStatus());
        self::assertTrue($ligne->isRemovable(), 'Rien n est engage avant l envoi.');
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

        self::assertFalse($ligne->isRemovable(), 'Apres l envoi, la matiere est engagee.');
        self::assertTrue($ligne->hasConsumed());
        self::assertNotNull($ligne->getFiredAt());
    }

    public function testAnnulerApresEnvoiExigeUnMotif(): void
    {
        $ligne = $this->ligne()->fire($this->midi());

        // Le motif distingue l erreur de saisie du plat renvoye par le client, et ces deux-la ne se
        // comprennent pas pareil au moment d expliquer une perte en fin de mois.
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
        // et c est le stock qui ne se rattrape pas.
        self::assertFalse($ligne->isBillable());
        self::assertTrue($ligne->hasConsumed());
        self::assertSame('Plat renvoye par le client', $ligne->getVoidReason());
    }

    public function testOnNeSertQueCeQuiEstParti(): void
    {
        $this->expectException(\LogicException::class);
        $this->ligne()->serve();
    }

    public function testUneQuantiteNulleNaPasDeSens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiningOrderLine($this->addition(), CourseRef::of('plat', 2), 'Entrecote', 0, '24.50');
    }

    public function testUneAdditionCloseNaccepteplusDeCommande(): void
    {
        // Le pendant cote addition : une fois l addition demandee, la salle ne saisit plus rien.
        $addition = $this->addition();
        $addition->close(new \DateTimeImmutable('2026-09-01 13:30:00'));

        $this->expectException(\LogicException::class);
        $this->ligne($addition);
    }

    public function testLaLignePorteSonPropreEtablissement(): void
    {
        $addition = $this->addition();
        $ligne = $this->ligne($addition);

        // D8 : une ligne recuperee par son id, sans passer par son addition, doit rester cloisonnable.
        self::assertSame($addition->getEstablishment(), $ligne->getEstablishment());
    }
}
