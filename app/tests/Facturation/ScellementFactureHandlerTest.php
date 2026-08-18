<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Intégrité NF525 propre à Facturation (empreinte chaînée, signature) : une altération du contenu
 * après scellement (ici via un contournement direct SQL, hors ORM/listener) est détectée par
 * `verifieChaine()`.
 */
final class ScellementFactureHandlerTest extends FacturationApiTestCase
{
    public function testChaineDetecteAlteration(): void
    {
        [$client, $entete] = $this->adminSurA();

        $b1 = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0)])->toArray();
        $f1 = $client->request('POST', '/api/factures/' . $b1['id'] . '/emettre', $entete)->toArray();
        $b2 = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(200.0)])->toArray();
        $f2 = $client->request('POST', '/api/factures/' . $b2['id'] . '/emettre', $entete)->toArray();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var ScellementFactureHandler $scellement */
        $scellement = static::getContainer()->get(ScellementFactureHandler::class);

        $entite1 = $em->getRepository(Facture::class)->find(Uuid::fromString($f1['id']));
        $entite2 = $em->getRepository(Facture::class)->find(Uuid::fromString($f2['id']));
        self::assertNotNull($entite1);
        self::assertNotNull($entite2);

        $rapportAvant = $scellement->verifieChaine([$entite1, $entite2]);
        self::assertTrue($rapportAvant['intacte'], 'Chaîne intacte avant altération.');

        // Altération directe en base (contourne l'ORM/le listener d'inaltérabilité) pour simuler une
        // corruption externe détectable uniquement par le recalcul de l'empreinte.
        $em->getConnection()->executeStatement(
            'UPDATE facturation_facture SET total_ht = ? WHERE id = ?',
            ['999.99', $entite2->getId()->toBinary()],
        );

        $em->clear();
        $entite1Rechargee = $em->getRepository(Facture::class)->find(Uuid::fromString($f1['id']));
        $entite2Rechargee = $em->getRepository(Facture::class)->find(Uuid::fromString($f2['id']));

        $rapportApres = $scellement->verifieChaine([$entite1Rechargee, $entite2Rechargee]);
        self::assertFalse($rapportApres['intacte'], 'Altération détectée (empreinte incohérente).');
        self::assertNotEmpty($rapportApres['anomalies']);
    }
}
