<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\ProfilExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /profil_exploitants/{id}/logo` (#345) : le logo de l'en-tête de facture.
 *
 * Deux clients : le profil de A et un profil de B. Depuis l'établissement A, on pose le logo de A
 * (autorisé) et on ne touche pas celui de B (404, et le logo de B reste vide : témoin). Le PNG est
 * refusé, parce que l'image de production ne sait pas le dessiner dans un PDF.
 */
final class OperatorLogoUploadTest extends ComptaApiTestCase
{
    public function testLeLogoJpegDuProfilDeAEstEnregistre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $lu = $client->request('POST', '/api/profil_exploitants/' . $this->idProfilExploitant() . '/logo', $this->multipart($entete, $this->fichier('jpeg'), 'image/jpeg'))->toArray();

        self::assertResponseIsSuccessful();
        self::assertTrue($lu['logoDefined']);
        self::assertNotNull($this->profilFrais($this->idProfilExploitant())->getLogoDocumentId());
    }

    public function testUnPngEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/profil_exploitants/' . $this->idProfilExploitant() . '/logo', $this->multipart($entete, $this->fichier('png'), 'image/png'));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->profilFrais($this->idProfilExploitant())->getLogoDocumentId());
    }

    public function testLeProfilDUnAutreClientEstIntouchable(): void
    {
        $idB = $this->profilSurB();
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/profil_exploitants/' . $idB . '/logo', $this->multipart($entete, $this->fichier('jpeg'), 'image/jpeg'));
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->profilFrais($idB)->getLogoDocumentId(), 'Le logo de B est resté vide.');
    }

    public function testDepuisBLeProfilDeBAccepteSonLogo(): void
    {
        // Le cas à AUTORISER : sans lui, un contrôle qui refuserait tout passerait le test précédent.
        $idB = $this->profilSurB();
        [$client, $entete] = $this->adminSurA();
        $entete['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $client->request('POST', '/api/profil_exploitants/' . $idB . '/logo', $this->multipart($entete, $this->fichier('jpeg'), 'image/jpeg'));
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->profilFrais($idB)->getLogoDocumentId());
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function multipart(array $entete, string $chemin, string $mime): array
    {
        // Pas de Content-Type écrit à la main : le client de test pose lui-même la frontière multipart.
        $entete['extra'] = ['files' => ['file' => new UploadedFile($chemin, 'logo', $mime, null, true)]];

        return $entete;
    }

    private function fichier(string $type): string
    {
        $octets = $type === 'png'
            ? (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true)
            : (string) hex2bin('ffd8ffe000104a46494600010100000100010000ffdb004300' . str_repeat('01', 64)
                . 'ffc0000b080008000801011100'
                . 'ffc40014000100' . str_repeat('00', 15) . 'ffc40014100100' . str_repeat('00', 15)
                . 'ffda0008010100003f003fffd9');
        $chemin = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($chemin, $octets);

        return $chemin;
    }

    private function profilSurB(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $profil = (new ProfilExploitant())->setSiren('999888777')->setEtablissementPrincipal($etabB);
        $em->persist($profil);
        $em->flush();

        return (string) $profil->getId();
    }

    private function profilFrais(string $id): ProfilExploitant
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $profil = $em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(ProfilExploitant::class, $profil);

        return $profil;
    }
}
