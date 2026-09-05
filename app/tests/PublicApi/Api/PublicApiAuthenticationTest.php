<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\Organisation\Entity\Etablissement;
use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\ApiScope;
use App\PublicApi\Enum\CredentialStatus;
use App\PublicApi\Service\ApiCredentialFactory;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La chaine d'authentification de l'API publique (T1).
 *
 * ⚠ **LE TEMOIN QUI COMPTE EST CELUI DU REFUS D'UN JETON HUMAIN**, et il a ete VU TOMBER : le 06/09,
 * en placant volontairement le pare-feu `public_api` APRES `api`, lui seul a echoue — les trois
 * autres sont restes verts, puisqu'ils ne verifient que ce que le mecanisme laisse passer.
 *
 * ⚠ **CE QUE L'EXPERIENCE A CORRIGE DANS MA PROPRE DESCRIPTION.** Je l'avais annonce comme « le JWT
 * humain serait accepte ». Il ne l'est pas : la requete rend alors 403 (« The user doesn't have
 * ROLE_PARTNER »), refusee par `access_control` et non par le pare-feu. Il y a donc DEUX barrieres
 * independantes. Ce temoin ne prouve pas que l'ordre est la seule protection — il prouve que l'une
 * des deux a cede, en distinguant 401 (le pare-feu a fait son travail) de tout le reste.
 */
final class PublicApiAuthenticationTest extends SocleApiTestCase
{
    public function testUneCleValideEstAcceptee(): void
    {
        $client = static::createClient();
        ['secret' => $secret, 'application' => $application] = $this->fabriquerPartenaire([ApiScope::BookingsRead]);

        $reponse = $client->request('GET', '/v1/me', [
            'headers' => ['Authorization' => 'Bearer '.$secret],
        ]);

        self::assertResponseIsSuccessful();

        $corps = $reponse->toArray();
        self::assertSame((string) $application->getId(), $corps['application']['id']);
        self::assertCount(1, $corps['grants']);
        self::assertSame(['bookings:read'], $corps['grants'][0]['scopes']);
    }

    public function testUneCleInconnueEstRefusee(): void
    {
        $client = static::createClient();

        $client->request('GET', '/v1/me', [
            'headers' => ['Authorization' => 'Bearer flv_totalement_inventee'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUneCleRevoqueeEstRefusee(): void
    {
        $client = static::createClient();
        ['secret' => $secret, 'credential' => $credential] = $this->fabriquerPartenaire([ApiScope::BookingsRead]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $credential->setStatus(CredentialStatus::Revoked);
        $em->flush();

        $client->request('GET', '/v1/me', [
            'headers' => ['Authorization' => 'Bearer '.$secret],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * ⚠ LE TEMOIN QUI PROTEGE L'ISOLATION. Il tombe si le pare-feu `public_api` cesse d'etre place
     * avant `api` — et lui seul.
     */
    public function testUnJetonHumainNeVautRienSurLApiPublique(): void
    {
        $client = static::createClient();
        $jetonHumain = $this->jeton($client, \App\DataFixtures\SocleFixtures::ADMIN_EMAIL, \App\DataFixtures\SocleFixtures::ADMIN_MDP);

        $client->request('GET', '/v1/me', [
            'headers' => ['Authorization' => 'Bearer '.$jetonHumain],
        ]);

        self::assertResponseStatusCodeSame(
            401,
            "Un JWT humain a ete accepte sur /v1 : le pare-feu `public_api` n'est plus place avant `api`.",
        );
    }

    /**
     * @param list<ApiScope> $portees
     *
     * @return array{secret: string, application: PartnerApplication, credential: \App\PublicApi\Entity\ApiCredential}
     */
    private function fabriquerPartenaire(array $portees): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // ⚠ ON L'INSTANCIE PLUTOT QUE DE LA RENDRE PUBLIQUE. Elle ne depend que de l'EntityManager ;
        //   exposer un service dans le conteneur pour la seule commodite d'un test change la
        //   production pour une raison qui n'existe qu'au test.
        $fabrique = new ApiCredentialFactory($em);

        $application = (new PartnerApplication())
            ->setName('Agregateur de test')
            ->setContactEmail('integration@example.test');
        $em->persist($application);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy([]);
        self::assertNotNull($etablissement, 'Le socle doit fournir au moins un etablissement.');

        $grant = (new ApiGrant())
            ->setApplication($application)
            ->setEtablissement($etablissement)
            ->setScopes($portees);
        $em->persist($grant);
        $em->flush();

        $frappe = $fabrique->issue($application);

        return [
            'secret' => $frappe['secret'],
            'application' => $application,
            'credential' => $frappe['credential'],
        ];
    }
}
