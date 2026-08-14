<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\EspaceAcces;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cloisonnement multi-entités des ressources L3 (RG-SOCLE-05) : un utilisateur sans affectation sur
 * l'établissement d'un espace n'y a aucun accès (données absentes de ses lectures).
 */
final class CloisonnementTest extends AccesApiTestCase
{
    public function testLecteurSansAffectationSurEtabBNeVoitPasSesEspacesAcces(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $espaceSocleB = (new Espace())->setNom('Espace B privé')->setEtablissement($etabB)->setType('bassin');
        $em->persist($espaceSocleB);
        $espaceAccesB = (new EspaceAcces())->setLibelle('Espace Accès B privé')->setEspaceSocle($espaceSocleB)->setSeuilFmi(10);
        $em->persist($espaceAccesB);
        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        // Le lecteur (affecté uniquement sur A) ne doit voir aucun espace de B, même avec acces.lire.
        $client->request('GET', '/api/espace_acces', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        foreach ($membres as $item) {
            self::assertNotSame('Espace Accès B privé', $item['libelle']);
        }
    }
}
