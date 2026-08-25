<?php

declare(strict_types=1);

namespace App\Tests\Stay\Integration;

use App\Stay\DataFixtures\StayFixtures;
use App\Stay\Entity\Stay;
use App\Stay\Enum\StayResolutionReason;
use App\Stay\Doctrine\DoctrineOpenStayLookup;
use App\Stay\Service\OpenStayResolver;
use App\Tests\Stay\StayApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le fil, **contre une vraie base** (ACT-3).
 *
 * Le pendant unitaire (`OpenStayResolverTest`) fige les trois issues de la règle ; il ne prouve rien
 * sur la requête qui les alimente. La leçon du 24/08 est encore chaude : une liaison de paramètre
 * incorrecte sur un identifiant `BINARY(16)` ne remonte aucune ligne **sans lever d'erreur**, et le
 * résolveur conclurait alors sereinement « client de passage » pour tout le monde — c'est-à-dire
 * qu'aucune consommation ne serait jamais rattachée, en silence, sur un module dont c'est la raison
 * d'être.
 */
final class DoctrineOpenStayLookupTest extends StayApiTestCase
{
    public function testUneConsommationTrouveLeSejourOuvertDeSonClient(): void
    {
        [$em, $resolveur] = $this->services();
        $sejourA = $this->sejour($em, StayFixtures::REFERENCE_A);

        $resolution = $resolveur->resolve(
            $sejourA->getEstablishment(),
            $sejourA->getCustomer(),
            new \DateTimeImmutable('2026-08-25 19:00:00'),
        );

        self::assertTrue($resolution->isMatched(), 'Le sejour ouvert du client doit etre trouve.');
        self::assertSame($sejourA->getId()->toRfc4122(), $resolution->stay?->getId()->toRfc4122());
    }

    public function testUnSejourClosNemporteplusRien(): void
    {
        [$em, $resolveur] = $this->services();
        $sejourA = $this->sejour($em, StayFixtures::REFERENCE_A);

        $sejourA->close(new \DateTimeImmutable('2026-08-26 10:00:00'));
        $em->flush();

        $resolution = $resolveur->resolve(
            $sejourA->getEstablishment(),
            $sejourA->getCustomer(),
            new \DateTimeImmutable('2026-08-26 19:00:00'),
        );

        // Apres le depart, une consommation redevient une vente ordinaire : le compte est fige.
        self::assertSame(StayResolutionReason::NoOpenStay, $resolution->reason);
    }

    public function testLeSejourDunAutreEtablissementNestJamaisEmporte(): void
    {
        [$em, $resolveur] = $this->services();
        $sejourA = $this->sejour($em, StayFixtures::REFERENCE_A);
        $sejourB = $this->sejour($em, StayFixtures::REFERENCE_B);

        // Le client de B, consommant dans A : aucun rattachement. Le cloisonnement ne vaut pas que
        // pour la lecture — porter une ligne au sejour d'un autre etablissement serait pire.
        $resolution = $resolveur->resolve(
            $sejourA->getEstablishment(),
            $sejourB->getCustomer(),
            new \DateTimeImmutable('2026-08-25 19:00:00'),
        );

        self::assertSame(StayResolutionReason::NoOpenStay, $resolution->reason);
    }

    public function testUnSejourPasEncoreCommenceNemporteRien(): void
    {
        [$em, $resolveur] = $this->services();
        $sejourA = $this->sejour($em, StayFixtures::REFERENCE_A);

        // Une reservation enregistree a l'avance ne doit pas aspirer les consommations d'aujourd'hui.
        $resolution = $resolveur->resolve(
            $sejourA->getEstablishment(),
            $sejourA->getCustomer(),
            new \DateTimeImmutable('2026-08-23 19:00:00'),
        );

        self::assertSame(StayResolutionReason::NoOpenStay, $resolution->reason);
    }

    /**
     * Le resolveur est **construit a la main**, pas tire du conteneur.
     *
     * Ce n'est pas un contournement : `OpenStayResolver` n'a aujourd'hui **aucun consommateur** — son
     * abonne au bus attend que `sale.completed` et `access.recorded` soient emis, ce qui n'est pas mon
     * perimetre — et Symfony retire du conteneur les services que personne n'injecte. L'assembler ici
     * avec la vraie implementation Doctrine teste exactement le chemin de production, sans dependre
     * d'une declaration d'injection qui n'a pas encore lieu d'etre.
     *
     * @return array{0: EntityManagerInterface, 1: OpenStayResolver}
     */
    private function services(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return [$em, new OpenStayResolver(new DoctrineOpenStayLookup($em))];
    }

    private function sejour(EntityManagerInterface $em, string $reference): Stay
    {
        $sejour = $em->getRepository(Stay::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Stay::class, $sejour);

        return $sejour;
    }
}
