<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Piscine\DataFixtures\PiscineFixtures;
use App\Piscine\Entity\Bassin;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cloisonnement multi-entités des ressources L6 (RG-SOCLE-05) : un utilisateur sans affectation sur
 * l'établissement d'un bassin/casier n'y a aucun accès, même avec `piscine.lire` (joker `*.lire`).
 */
final class CloisonnementTest extends PiscineApiTestCase
{
    public function testLecteurSansAffectationSurEtabBNeVoitPasSesBassins(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);

        $espaceB = (new Espace())->setNom('Bassin B privé (socle)')->setEtablissement($etabB)->setType('bassin');
        $em->persist($espaceB);
        $bassinB = (new Bassin())->setLibelle('Bassin B privé')->setEspace($espaceB)->setNbLignes(2)->setCapacite(20);
        $em->persist($bassinB);
        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/bassins', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];

        // ⚠ LES DEUX MOITIÉS, ET UNE SEULE NE PROUVE RIEN.
        //
        // La boucle seule passait sur une liste VIDE — le corps ne s'exécute jamais, zéro
        // assertion jouée, test vert. Elle était donc également vraie d'un filtre cassé, d'un
        // en-tête d'établissement mal passé, ou d'une fixture qui ne crée plus rien. Le défaut
        // d'uuid en BINARY(16) rendait une liste vide à 145 filtres : ce test était vert
        // pendant tout ce temps.
        //
        // On affirme donc ce que A VOIT avant d'affirmer ce qu'il ne voit pas.
        $libelles = array_column($membres, 'libelle');
        self::assertContains(
            PiscineFixtures::BASSIN_LIBELLE,
            $libelles,
            'A doit voir SES bassins : sans cette moitié, le test est vrai d\'un point d\'entrée mort.',
        );
        self::assertNotContains('Bassin B privé', $libelles);
    }

    public function testAgentSansPermissionConfigurerNePeutPasCreerDeBassin(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $espace = (new Espace())->setNom('Bassin refusé')->setEtablissement($etabA)->setType('bassin');
        $em->persist($espace);
        $em->flush();

        $client->request('POST', '/api/bassins', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
            'json' => ['libelle' => 'Bassin refusé', 'espace' => '/api/espaces/' . $espace->getId(), 'nbLignes' => 2, 'capacite' => 20],
        ]);
        self::assertResponseStatusCodeSame(403, 'Le lecteur (permission "*.lire" seulement) ne peut pas configurer un bassin (piscine.configurer requis).');
    }
}
