<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\RegieRecettes;
use App\Compta\Service\RegieHandler;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-02, RG-REGIE-02, RG-M6-10 : plafond d'encaisse (alerte au dépassement, CA-4), versement daté
 * avec justificatifs qui décrémente le solde (CA-5).
 */
final class RegieTest extends ComptaApiTestCase
{
    public function testDepassementDuPlafondDeclencheUneAlerteBloquante(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $regie = $this->entite(RegieRecettes::class, ['libelle' => \App\Compta\DataFixtures\ComptaFixtures::REGIE_LIBELLE]);
        self::assertFalse($regie->depassePlafond());

        /** @var RegieHandler $handler */
        $handler = static::getContainer()->get(RegieHandler::class);
        $handler->enregistrerEncaissement($regie, $regie->getPlafondEncaisseCentimes() + 100);

        $em->refresh($regie);
        self::assertTrue($regie->depassePlafond(), 'Le dépassement du plafond doit être détectable (alerte bloquante, RG-M6-10).');
    }

    public function testVersementGenereUnBordereauDateEtDecrementeLeSolde(): void
    {
        [$client, $entete] = $this->adminSurA();

        $regie = $this->entite(RegieRecettes::class, ['libelle' => \App\Compta\DataFixtures\ComptaFixtures::REGIE_LIBELLE]);

        /** @var RegieHandler $handler */
        $handler = static::getContainer()->get(RegieHandler::class);
        $handler->enregistrerEncaissement($regie, 10000);

        // Re-fetch : le client de test reboote le kernel à chaque requête (le conteneur/EM d'avant la
        // requête devient obsolète), cf. `entite()` qui récupère toujours un EM frais.
        $regie = $this->entite(RegieRecettes::class, ['libelle' => \App\Compta\DataFixtures\ComptaFixtures::REGIE_LIBELLE]);
        self::assertSame(10000, $regie->getSoldeEncaisseCentimes());

        $bordereau = $client->request('POST', '/api/compta/regies/' . $regie->getId() . '/versements', $entete + [
            'json' => ['montant' => '60.00', 'justificatifs' => ['ticket-z-001.pdf']],
        ])->toArray();

        self::assertSame(6000, $bordereau['montantCentimes']);
        self::assertNotEmpty($bordereau['dateVersement']);
        self::assertSame(['ticket-z-001.pdf'], $bordereau['justificatifs']);

        $regie = $this->entite(RegieRecettes::class, ['libelle' => \App\Compta\DataFixtures\ComptaFixtures::REGIE_LIBELLE]);
        self::assertSame(4000, $regie->getSoldeEncaisseCentimes(), 'Le solde d\'encaisse doit décroître du montant versé (CA-5).');
    }
}
