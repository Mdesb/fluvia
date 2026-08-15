<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-04/09 (CA-7) : une écriture scellée est inaltérable — `preUpdate`/`preRemove` lèvent une
 * exception ; la seule voie de correction est l'extourne.
 */
final class ImmuabiliteTest extends ComptaApiTestCase
{
    public function testModificationOrmDuneEcritureScelleeLeveUneException(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);
        self::assertTrue($ecriture->estScellee());

        $ecriture->setLibelle('Modification interdite');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $em->flush();
    }

    public function testExtourneEstLaSeuleVoieDeCorrection(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);

        $ecritureOrigineId = $ecriture->getId()->toRfc4122();
        $extourne = $client->request('POST', '/api/compta/ecritures/' . $ecriture->getId() . '/extourne', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotSame($ecritureOrigineId, $extourne['id']);

        // Re-fetch : le client de test reboote le kernel à chaque requête.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $extourneEntite = $em->getRepository(EcritureComptable::class)->find($extourne['id']);
        self::assertNotNull($extourneEntite);
        self::assertTrue($extourneEntite->estEquilibree());
        self::assertNotNull($extourneEntite->getPieceExtourneDe());
        self::assertSame($ecriture->getId()->toRfc4122(), $extourneEntite->getPieceExtourneDe()->getId()->toRfc4122());
    }
}
