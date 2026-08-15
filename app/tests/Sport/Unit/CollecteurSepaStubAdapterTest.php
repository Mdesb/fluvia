<?php

declare(strict_types=1);

namespace App\Tests\Sport\Unit;

use App\Sport\Entity\RemiseSepa;
use App\Sport\Sepa\Adapter\CollecteurSepaStubAdapter;
use App\Sport\Sepa\Dto\RetourSepaDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur SEPA stub (§2.1 du plan, Risque n°2) : référence déterministe, aucune remise bancaire
 * réelle, `injecterRetourDeTest()` permet de simuler un retour banque pour tester le moteur
 * anti-impayés bout en bout sans banque réelle.
 */
final class CollecteurSepaStubAdapterTest extends TestCase
{
    public function testGenererRemiseProduitUneReferenceDeterministe(): void
    {
        $adapter = new CollecteurSepaStubAdapter();
        $remise = new RemiseSepa();

        $resultat1 = $adapter->genererRemise($remise, []);
        $resultat2 = $adapter->genererRemise($remise, []);

        self::assertSame($resultat1->referenceRemise, $resultat2->referenceRemise);
        self::assertStringStartsWith('REMISE-', $resultat1->referenceRemise);
    }

    public function testInjecterRetourDeTestEstRelevePuisVide(): void
    {
        $adapter = new CollecteurSepaStubAdapter();
        $retour = new RetourSepaDto(Uuid::v4(), 'AM04', 'Fonds insuffisants', 3990, new \DateTimeImmutable());

        self::assertSame([], $adapter->relerverRetours(new \DateTimeImmutable('-1 day')));

        $adapter->injecterRetourDeTest($retour);
        $releves = $adapter->relerverRetours(new \DateTimeImmutable('-1 day'));
        self::assertCount(1, $releves);
        self::assertSame($retour, $releves[0]);

        // Une fois relevés, les retours ne sont pas rendus deux fois.
        self::assertSame([], $adapter->relerverRetours(new \DateTimeImmutable('-1 day')));
    }
}
