<?php

declare(strict_types=1);

namespace App\Tests\Stay\Integration;

use App\Stay\DataFixtures\StayFixtures;
use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use App\Stay\Service\StayChargeRecorder;
use App\Stay\Service\StayFolio;
use App\Tests\Stay\StayApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'idempotence des lignes, **contre une vraie base** (ACT-3).
 *
 * **Ce test existe à cause d'un défaut réel, pas par principe.** `StayChargeRecorderTest` couvre la
 * même règle en unitaire, via un double de `StayChargeLookup` — et il était vert pendant que
 * l'implémentation Doctrine, elle, ne remontait **aucune ligne** : les paramètres étaient liés en
 * passant l'entité, ce qui produit sur un identifiant `BINARY(16)` une comparaison qui ne correspond
 * à rien, **sans lever d'erreur**. La lecture préalable de l'idempotence était donc muette, et seule
 * la contrainte d'unicité du schéma protégeait encore le client. Un double ne peut pas révéler ça :
 * il faut la vraie base.
 *
 * La leçon, plus générale que le module : un test unitaire prouve la **logique**, jamais la
 * **traduction** vers le stockage. Toute règle dont dépend de l'argent mérite les deux.
 */
final class StayChargeRecorderDoctrineTest extends StayApiTestCase
{
    public function testLeMemeFaitNestPorteQuUneFois(): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        /** @var StayChargeRecorder $recorder */
        $recorder = $container->get(StayChargeRecorder::class);

        $sejour = $em->getRepository(Stay::class)->findOneBy(['reference' => StayFixtures::REFERENCE_A]);
        self::assertInstanceOf(Stay::class, $sejour);

        $arguments = [
            $sejour, 'Piscine - 1 entree', '4.50',
            new \DateTimeImmutable('2026-08-25 14:30:00'),
            'acces', 'access.recorded', 'passage-integration-77',
        ];

        $premiere = $recorder->record(...$arguments);
        self::assertInstanceOf(StayCharge::class, $premiere, 'Le premier enregistrement doit creer la ligne.');

        // Le rejeu : c'est le cas nominal du transport asynchrone (D7-bis), pas un cas limite.
        $seconde = $recorder->record(...$arguments);
        self::assertNull($seconde, 'Le rejeu du meme fait ne doit pas creer de seconde ligne.');

        $lignes = $em->getRepository(StayCharge::class)->findBy([
            'stay' => $sejour,
            'sourceSubjectId' => 'passage-integration-77',
        ]);
        self::assertCount(1, $lignes, 'Une seule ligne doit exister en base pour ce fait.');
    }

    public function testLaNoteAdditionneLesLignesReellementStockees(): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        /** @var StayChargeRecorder $recorder */
        $recorder = $container->get(StayChargeRecorder::class);
        /** @var StayFolio $folio */
        $folio = $container->get(StayFolio::class);

        $sejour = $em->getRepository(Stay::class)->findOneBy(['reference' => StayFixtures::REFERENCE_A]);
        self::assertInstanceOf(Stay::class, $sejour);

        // Les fixtures posent deja 9,00 € sur ce sejour.
        $recorder->record(
            $sejour, 'Piscine - 1 entree', '4.50',
            new \DateTimeImmutable('2026-08-25 14:30:00'),
            'acces', 'access.recorded', 'passage-solde-1',
        );

        // 9,00 + 4,50 : c'est l'assertion qui serait restee a « 0.00 » avec l'ancienne liaison de
        // parametres, en affichant une note vide sur un compte qui ne l'etait pas.
        self::assertSame('13.50', $folio->balanceOf($sejour)->total());
        self::assertCount(2, $folio->linesOf($sejour));
    }
}
