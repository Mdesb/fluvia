<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Unit;

use App\Organisation\Entity\Etablissement;
use App\Patinoire\Doctrine\VerificateurFenetreSaisonEphemereListener;
use App\Patinoire\Entity\SaisonEphemere;
use App\Patinoire\Enum\BasculeSaisonEphemere;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PrePersistEventArgs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * La fenêtre de vente d'une saison éphémère se compte en jours de la patinoire, pas en jours UTC :
 * elle s'ouvre à 00:00 à Paris le premier jour et se ferme à 00:00 à Paris le lendemain du dernier.
 *
 * Mesuré le 07/10/2026 : le contrôle prenait `new \DateTimeImmutable('today')`, le jour UTC. Entre
 * minuit à Paris et minuit UTC, la vente restait ouverte le lendemain du dernier jour et restait
 * fermée le premier jour.
 */
final class EphemeralSeasonSaleWindowTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function instants(): iterable
    {
        // [dernier jour de vente J, instant UTC, ouverte]. La fenêtre s'ouvre le 01/10/2026.
        yield 'J à 23:30 à Paris, hiver (22:30 UTC)' => ['2026-12-31', '2026-12-31T22:30:00+00:00', true];
        yield 'J+1 à 00:30 à Paris, hiver (23:30 UTC la veille)' => ['2026-12-31', '2026-12-31T23:30:00+00:00', false];
        yield 'J à 23:30 à Paris, été (21:30 UTC)' => ['2027-08-31', '2027-08-31T21:30:00+00:00', true];
        yield 'J+1 à 00:30 à Paris, été (22:30 UTC la veille)' => ['2027-08-31', '2027-08-31T22:30:00+00:00', false];
        yield 'premier jour à 00:30 à Paris (22:30 UTC la veille)' => ['2026-12-31', '2026-09-30T22:30:00+00:00', true];
        yield 'veille du premier jour à 23:30 à Paris' => ['2026-12-31', '2026-09-30T21:30:00+00:00', false];
    }

    #[DataProvider('instants')]
    public function testSaleWindowCoversWholeLocalDays(string $fin, string $instant, bool $ouverte): void
    {
        $saison = $this->saison('Europe/Paris', '2026-10-01', $fin);

        self::assertSame($ouverte, $saison->fenetreVenteOuverteA(new \DateTimeImmutable($instant)));
    }

    /** @return iterable<string, array{string}> */
    public static function fuseaux(): iterable
    {
        // À toute heure, l'un des deux fuseaux extrêmes (UTC+14, UTC-11) n'a pas le jour UTC.
        yield 'Paris' => ['Europe/Paris'];
        yield 'Kiritimati' => ['Pacific/Kiritimati'];
        yield 'Pago Pago' => ['Pacific/Pago_Pago'];
    }

    /** Le listener ferme la vente sur le jour de l'établissement, maintenant. */
    #[DataProvider('fuseaux')]
    public function testListenerUsesTheLocalDay(string $fuseau): void
    {
        $jour = fn (string $quand): string => (new \DateTimeImmutable($quand, new \DateTimeZone($fuseau)))->format('Y-m-d');

        self::assertTrue($this->vendable($this->saison($fuseau, $jour('today'), $jour('today'))), 'Ouverte le jour même.');
        self::assertFalse($this->vendable($this->saison($fuseau, $jour('yesterday'), $jour('yesterday'))), 'Fermée le lendemain.');
        self::assertFalse($this->vendable($this->saison($fuseau, $jour('tomorrow'), $jour('tomorrow'))), 'Fermée la veille.');
    }

    private function saison(string $fuseau, string $debut, string $fin): SaisonEphemere
    {
        // Les bornes comme Doctrine rend une colonne `date` : 00:00 dans le fuseau du serveur.
        return (new SaisonEphemere())->setLibelle('Patinoire')->setActif(true)
            ->setBascule(BasculeSaisonEphemere::Automatique)
            ->setEtablissement((new Etablissement())->setFuseauHoraire($fuseau))
            ->setFenetreVenteDebut(new \DateTimeImmutable($debut))->setFenetreVenteFin(new \DateTimeImmutable($fin));
    }

    private function vendable(SaisonEphemere $saison): bool
    {
        $ligne = new LigneVente();
        $saison->setCatalogueAssocie([(string) $ligne->getProduit()]);

        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findBy')->willReturn([$saison]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);

        try {
            (new VerificateurFenetreSaisonEphemereListener())->prePersist(new PrePersistEventArgs($ligne, $em));
        } catch (UnprocessableEntityHttpException) {
            return false;
        }

        return true;
    }
}
