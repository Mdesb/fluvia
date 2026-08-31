<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\FactureB2G;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\Port\ChorusProInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\CanalFacture;
use App\Facturation\Service\DepotChorusProHandler;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * US-FACT-06, RG-FACT-07 (CA-9) — dépôt Chorus Pro.
 *
 * ── CE QUE CE FICHIER AFFIRMAIT AVANT, ET POURQUOI IL A CHANGÉ ──────────────────────────────────
 *
 * Il vérifiait `statutEnvoi === 'transmis'`. Cette assertion était **vraie et sans valeur** :
 * `ChorusProStubAdapter` rendait `Transmis` sans rien transmettre, et le test scellait ce mensonge
 * au lieu de le révéler. Un exploitant aurait vu ses factures B2G marquées transmises et l'aurait
 * découvert par une relance de sa collectivité.
 *
 * Arbitré par Maxime le 31/08 : un canal non raccordé **refuse** au lieu d'annoncer un succès.
 *
 * ── LES DEUX MOITIÉS, ET LA SECONDE A DÛ CHANGER DE NIVEAU ──────────────────────────────────────
 *
 * 1. Par l'API : le dépôt est refusé, le message dit ce qui N'A PAS eu lieu, et **rien n'est écrit**.
 * 2. Par le handler : le rejeu réutilise le même `FactureB2G` sans regénérer de numéro (§7 spec).
 *
 * ⚠ La seconde ne peut plus passer par l'API — le canal refuse avant d'y arriver. Elle est donc
 * éprouvée contre un **adaptateur d'essai**, ce qui est légitime et même plus juste : cette règle est
 * la NÔTRE, pas celle de Chorus Pro. La tester à travers un adaptateur qui ment revenait à faire
 * dépendre notre propre invariant d'une intégration absente.
 */
final class ChorusProFactureApiTest extends FacturationApiTestCase
{
    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * Émet une facture à destinataire public, prête au dépôt.
     *
     * @return array{0: object, 1: array<string, mixed>, 2: string} client, en-tête, id de la facture
     */
    private function factureEmisePourCollectivite(): array
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => [
                'destinataire' => [
                    'type' => 'personne_morale',
                    'raisonSociale' => 'Mairie de Test',
                    'siret' => '12345678900011',
                    'adresse' => ['rue' => '1 place de la Mairie', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
                    'estOrganismePublic' => true,
                ],
                'lignes' => [[
                    'designation' => 'Prestation collectivité',
                    'quantite' => 1,
                    'prixUnitaireHT' => '500.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ]],
            ],
        ])->toArray();

        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
        self::assertNull($emise['factureB2G'] ?? null, 'Une facture émise ne porte pas encore de dépôt.');

        return [$client, $entete, (string) $emise['id']];
    }

    /** Le canal n'est pas raccordé : le dépôt est refusé, et il le dit. */
    public function testUnCanalNonRaccordeRefuseAuLieuDAnnoncerUnSucces(): void
    {
        [$client, $entete, $idFacture] = $this->factureEmisePourCollectivite();

        $client->request('POST', '/api/factures/' . $idFacture . '/chorus', $entete + [
            'json' => ['numeroEngagement' => 'ENG-2026-001', 'serviceExecutant' => 'Service Sport'],
        ]);

        self::assertResponseStatusCodeSame(503, 'Le canal manque : ce n\'est pas une faute de l\'appelant.');

        // ⚠ LE MESSAGE EST LA MOITIÉ QUI COMPTE. Un 503 muet laisse croire à un incident passager.
        // Celui-ci doit dire que RIEN n'a été transmis, et que le dépôt reste rejouable.
        $corps = $client->getResponse()->getContent(false);
        self::assertStringContainsString('Chorus Pro', $corps, 'Le message doit nommer le canal.');
        self::assertStringContainsString('PAS été transmise', $corps, 'Le message doit dire ce qui n\'a PAS eu lieu.');

        // Et rien n'a été écrit : ni dépôt, ni bascule de canal.
        $this->em()->clear();
        $apres = $client->request('GET', '/api/factures/' . $idFacture, $entete)->toArray();
        self::assertNull($apres['factureB2G'] ?? null, 'Un dépôt refusé ne doit laisser aucun enregistrement.');
        self::assertNotSame('chorus_pro', $apres['canal'] ?? null, 'Le canal ne bascule pas sur un dépôt qui n\'a pas eu lieu.');
    }

    /**
     * Le rejeu réutilise le même `FactureB2G` et ne regénère pas de numéro (§7 spec).
     *
     * Éprouvé contre un adaptateur d'essai : la règle est la nôtre, et la faire dépendre d'un canal
     * absent reviendrait à ne plus la vérifier du tout.
     */
    public function testLeRejeuDUnDepotNeRegenerePasDeNumero(): void
    {
        [$client, $entete, $idFacture] = $this->factureEmisePourCollectivite();

        $avant = $client->request('GET', '/api/factures/' . $idFacture, $entete)->toArray();
        $numeroAvant = $avant['numero'];
        self::assertNotEmpty($numeroAvant, 'Témoin : la facture émise porte bien un numéro.');

        $canalDEssai = new class implements ChorusProInterface {
            public function deposer(FactureB2G $facture): StatutEnvoi
            {
                return StatutEnvoi::Transmis;
            }
        };

        $handler = new DepotChorusProHandler($this->em(), $canalDEssai);

        /** @var Facture $facture */
        $facture = $this->em()->getRepository(Facture::class)->find(Uuid::fromString($idFacture));

        $premier = $handler->deposer($facture, 'ENG-2026-001', 'Service Sport');
        self::assertSame(StatutEnvoi::Transmis, $premier->getStatutEnvoi());
        self::assertSame('ENG-2026-001', $premier->getNumeroEngagement());
        self::assertSame(CanalFacture::ChorusPro, $facture->getCanal());

        $second = $handler->deposer($facture, null, null);
        self::assertSame((string) $premier->getId(), (string) $second->getId(), 'Même enregistrement de dépôt réutilisé.');
        self::assertSame('ENG-2026-001', $second->getNumeroEngagement(), 'Le rejeu sans argument ne perd pas ce qui était posé.');

        $this->em()->clear();
        $apres = $client->request('GET', '/api/factures/' . $idFacture, $entete)->toArray();
        self::assertSame($numeroAvant, $apres['numero'], 'Un rejeu de dépôt ne consomme pas de numéro de facture.');
    }
}
