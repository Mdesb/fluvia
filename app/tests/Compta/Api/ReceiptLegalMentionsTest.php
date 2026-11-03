<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\DataFixtures\SocleFixtures;
use App\Legal\Entity\LegalIdentity;
use App\Organisation\Entity\Etablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le ticket de caisse porte ce qu'un ticket doit porter en France : l'émetteur (raison sociale,
 * adresse, SIRET) et la ventilation de la TVA par taux.
 *
 * Mentions vérifiées le 21/10/2026 sur economie.gouv.fr, « Ticket de caisse : professionnels, quelles
 * sont vos obligations ? » (page du 10/11/2025) : coordonnées du professionnel, désignation des
 * produits, quantité et prix, date et heure.
 */
final class ReceiptLegalMentionsTest extends ComptaApiTestCase
{
    public function testTheReceiptNamesTheIssuerAndBreaksDownVatByRate(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var Etablissement $etablissement */
        $etablissement = $em->getRepository(Etablissement::class)->find($this->idEtablissement(SocleFixtures::ETAB_A_NOM));
        $identite = (new LegalIdentity())
            ->setEstablishment($etablissement)
            ->setLegalName('Ville de Perpignan')
            ->setSiret('21660136400019')
            ->setRegisteredAddress('Place de la Loge, 66000 Perpignan');
        $em->persist($identite);
        $em->flush();

        $vente = $this->creerVenteValidee($client, $entete, quantite: 2);
        $ticket = $this->ticket($client, $entete, $vente['id']);

        self::assertSame('Ville de Perpignan', $ticket['emetteur']['raisonSociale'] ?? null);
        self::assertSame('21660136400019', $ticket['emetteur']['siret'] ?? null);
        self::assertSame('Place de la Loge, 66000 Perpignan', $ticket['emetteur']['adresse'] ?? null);
        self::assertNotSame('', $ticket['emetteur']['nom'] ?? '');

        self::assertNotEmpty($ticket['ventilationTva'] ?? [], 'La TVA doit être ventilée par taux.');
        $ttc = 0;
        foreach ($ticket['ventilationTva'] as $taux) {
            self::assertNotNull($taux['taux'], 'Le produit vendu a une catégorie mappée : son taux est connu.');
            self::assertSame(
                (int) round((float) $taux['totalTtc'] * 100),
                (int) round(((float) $taux['totalHt'] + (float) $taux['totalTva']) * 100),
                'HT + TVA = TTC, taux par taux.',
            );
            self::assertGreaterThan(0, (int) round((float) $taux['totalTva'] * 100), 'Témoin : une entrée taxée porte de la TVA.');
            $ttc += (int) round((float) $taux['totalTtc'] * 100);
        }
        self::assertSame((int) round((float) $vente['total'] * 100), $ttc, 'La ventilation fait le total du ticket.');
    }

    public function testWithoutLegalIdentityTheReceiptStillNamesTheEstablishment(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);
        $ticket = $this->ticket($client, $entete, $vente['id']);

        self::assertNotSame('', $ticket['emetteur']['nom'] ?? '');
        self::assertArrayHasKey('siret', $ticket['emetteur']);
        self::assertNull($ticket['emetteur']['siret'], 'Un SIRET absent reste absent : il ne se devine pas.');
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function ticket(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $venteId): array
    {
        return $client->request('POST', '/api/ventes/' . $venteId . '/ticket', $entete + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
    }
}
