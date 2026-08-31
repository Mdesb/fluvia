<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE DROIT OUVRE-T-IL CETTE ZONE ?
 *
 * La question ne se posait nulle part. `ValidationPassageHandler` prononçait onze motifs de refus —
 * support bloqué, appairage, droit dévalidé, fédération, sens, heures d'ouverture, marges,
 * anti-passback, crédit, jauge, signature — et **aucun ne regardait la zone**. L'espace était résolu
 * depuis l'équipement dès l'entrée, mais il ne servait qu'à la jauge et à l'enregistrement du
 * passage.
 *
 * Conséquence : un billet de piscine ouvrait la porte de la salle de sport du même établissement.
 * Sur un site multi-activités — ce que Fluvia vend — c'est le cœur du contrôle d'accès qui manquait.
 *
 * ⚠ LE PREMIER TEST EST LE PLUS IMPORTANT DES DEUX, et ce n'est pas celui qui vérifie le refus.
 * Un droit sans espace déclaré doit continuer d'ouvrir TOUT : c'est le comportement d'hier, et
 * c'est ce qui rend le déploiement sans danger. Les droits déjà projetés n'ont aucun espace ; les
 * refuser partout à la seconde où la migration passe fermerait des portes devant des gens qui ont
 * payé, sur un mécanisme dont ils ignorent le changement.
 */
final class ZoneAutoriseeTest extends AccesApiTestCase
{
    /**
     * SANS RESTRICTION DÉCLARÉE, RIEN NE CHANGE.
     *
     * C'est la non-régression du déploiement. Le sens sûr de l'erreur est d'ordinaire celui qui
     * restreint ; ici la restriction n'existe que si quelqu'un l'a demandée.
     */
    public function testUnDroitSansZoneDeclareeOuvreToujours(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'] ?? null);
    }

    /**
     * UNE ZONE DÉCLARÉE QUI N'EST PAS CELLE DE L'ÉQUIPEMENT FERME LA PORTE.
     *
     * Le droit se voit attribuer un espace CRÉÉ POUR L'OCCASION, distinct de celui de l'équipement
     * franchi. Sans cette création, on ne saurait pas distinguer « la règle s'applique » de « il n'y
     * a qu'un espace, donc tout coïncide ».
     */
    public function testUnDroitLimiteAUneAutreZoneEstRefuse(): void
    {
        $this->limiterLeDroitAUneAutreZone();

        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertResponseIsSuccessful('le passage est ENREGISTRÉ même refusé : un refus qui ne laisse pas de trace ne s’explique pas');

        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat'] ?? null);
        self::assertSame('zone_non_autorisee', $reponse['codeMotif'] ?? null);
    }

    /**
     * ET LA ZONE DE L'ÉQUIPEMENT, ELLE, RESTE OUVERTE.
     *
     * Sans ce troisième cas, un `ouvre()` qui rendrait toujours `false` passerait le test
     * précédent — on aurait remplacé « ouvre tout » par « n'ouvre rien » sans le voir.
     */
    public function testLaZoneDeclareeResteOuverte(): void
    {
        $this->limiterLeDroitASaPropreZone();

        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'] ?? null);
    }

    private function limiterLeDroitAUneAutreZone(): void
    {
        $em = $this->em();

        // ⚠ `espaceSocle` est obligatoire en base : un espace d'accès est toujours la déclinaison
        // d'un espace du socle. On reprend celui de l'espace existant plutôt que d'en inventer un —
        // le test porte sur la zone, pas sur la topologie.
        $existant = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $existant);

        $autre = (new EspaceAcces())
            ->setLibelle('Zone d’épreuve, sans équipement')
            ->setEspaceSocle($existant->getEspaceSocle());
        $em->persist($autre);

        $droitCible = $this->droit($em);

        // ⚠ « LIMITER À UNE AUTRE ZONE » DOIT REMPLACER, PAS AJOUTER — ET CE N'ÉTAIT PAS LE CAS.
        //
        // Tant que le jeu de données ne déclarait aucune zone, `add` suffisait : le droit passait de
        // « aucune » à « une autre », donc de « ouvre tout » à « ouvre ailleurs ». Depuis D87 la
        // fixture déclare la zone de l'équipement — `add` donnait alors un droit qui ouvre LES DEUX,
        // et le test attendait un refus en obtenant une validation.
        //
        // Le nom promettait « limiter » ; le code ajoutait. La différence ne se voyait que parce
        // qu'une donnée voisine était vide.
        foreach ($droitCible->getAuthorisedSpaces()->toArray() as $dejaOuvert) {
            $droitCible->removeAuthorisedSpace($dejaOuvert);
        }
        $droitCible->addAuthorisedSpace($autre);
        $em->flush();
    }

    private function limiterLeDroitASaPropreZone(): void
    {
        $em = $this->em();

        $espace = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $espace);

        $this->droit($em)->addAuthorisedSpace($espace);
        $em->flush();
    }

    private function droit(EntityManagerInterface $em): DroitAcces
    {
        $droit = $em->getRepository(DroitAcces::class)->find($this->idDroit());
        self::assertInstanceOf(DroitAcces::class, $droit);

        return $droit;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
