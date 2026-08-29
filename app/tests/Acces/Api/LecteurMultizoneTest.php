<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN LECTEUR PLACÉ ENTRE DEUX ACTIVITÉS LES DESSERT TOUTES LES DEUX.
 *
 * Depuis que les produits déclarent les zones qu'ils ouvrent, un tourniquet commun à la piscine et
 * à la salle de sport refusait les abonnements salle : le contrôleur ne connaissait qu'un espace,
 * et la décision ne regardait que celui-là.
 *
 * ⚠ CE QUE CES TESTS NE PROUVENT PAS, ET QU'IL FAUT SAVOIR.
 *
 * Ils portent sur la DÉCISION seule. La jauge décrémentée, la portée de l'anti-passback et l'espace
 * inscrit sur le passage restent ceux de l'espace PRINCIPAL, même quand le titre est accepté au
 * titre d'un espace desservi. C'est délibéré : le porteur a franchi cette porte-là, et une jauge ne
 * se décrémente pas à un endroit où personne n'est passé.
 */
final class LecteurMultizoneTest extends AccesApiTestCase
{
    /**
     * LE TITRE LIMITÉ À UNE AUTRE ZONE PASSE, SI LE LECTEUR DESSERT AUSSI CETTE ZONE.
     */
    public function testUnLecteurQuiDessertLaZoneDuTitreLaisssePasser(): void
    {
        $autre = $this->limiterLeDroitAUneAutreZone();
        $this->faireDesservirParLeControleur($autre);

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
     * ET SANS CE RATTACHEMENT, LE MÊME TITRE RESTE REFUSÉ.
     *
     * ⚠ LE TÉMOIN DE LA PAIRE, et il est indispensable. Sans lui, une décision qui accepterait
     * TOUJOURS — la régression exacte que ce lot pourrait introduire en élargissant trop — passerait
     * le premier test avec les félicitations.
     */
    public function testSansRattachementLeTitreResteRefuse(): void
    {
        $this->limiterLeDroitAUneAutreZone();

        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertResponseIsSuccessful('un refus laisse une trace : un refus qui ne s’enregistre pas ne s’explique pas');

        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat'] ?? null);
        self::assertSame('zone_non_autorisee', $reponse['codeMotif'] ?? null);
    }

    /**
     * `espacesOuverts()` contient le principal ET les desservis.
     *
     * L'union est calculée à un seul endroit pour que la décision s'y adosse ; si elle oubliait le
     * principal, tout titre non restreint continuerait de passer et les deux tests ci-dessus
     * resteraient verts. Ce troisième cas est ce qui rend l'oubli visible.
     */
    public function testLUnionContientLePrincipalEtLesDesservis(): void
    {
        $autre = $this->limiterLeDroitAUneAutreZone();
        $controleur = $this->faireDesservirParLeControleur($autre);

        $numeros = array_map(
            static fn (EspaceAcces $e): string => (string) $e->getId(),
            $controleur->espacesOuverts(),
        );
        sort($numeros);

        $attendus = [(string) $autre->getId(), $this->idEspaceAcces()];
        sort($attendus);

        self::assertSame($attendus, $numeros);
    }

    /**
     * Crée un espace distinct de celui de l'équipement et y limite le droit des fixtures.
     *
     * ⚠ `espaceSocle` est obligatoire en base : on reprend celui de l'espace existant plutôt que
     * d'en inventer un — le test porte sur le rattachement, pas sur la topologie du socle.
     */
    private function limiterLeDroitAUneAutreZone(): EspaceAcces
    {
        $em = $this->em();

        $existant = $em->getRepository(EspaceAcces::class)->find($this->idEspaceAcces());
        self::assertInstanceOf(EspaceAcces::class, $existant);

        $autre = (new EspaceAcces())
            ->setLibelle('Salle de sport, voisine du tourniquet')
            ->setEspaceSocle($existant->getEspaceSocle());
        $em->persist($autre);

        $droit = $em->getRepository(DroitAcces::class)->find($this->idDroit());
        self::assertInstanceOf(DroitAcces::class, $droit);
        $droit->addAuthorisedSpace($autre);

        $em->flush();

        return $autre;
    }

    private function faireDesservirParLeControleur(EspaceAcces $espace): Controleur
    {
        $em = $this->em();

        $controleur = $em->getRepository(Controleur::class)->find($this->idControleur());
        self::assertInstanceOf(Controleur::class, $controleur);

        $controleur->addEspaceDesservi($espace);
        $em->flush();

        return $controleur;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
