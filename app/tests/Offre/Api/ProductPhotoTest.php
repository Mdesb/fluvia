<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\Entity\ProductPhoto;
use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Offre\OffreApiTestCase;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * LES PHOTOS DE PRODUIT — ce qui se sert au public se vérifie dans les octets.
 *
 * La route de lecture est **sans authentification** : c'est une brèche voulue, et elle n'est
 * défendable que si trois choses tiennent. Ces tests sont ces trois choses.
 */
final class ProductPhotoTest extends OffreApiTestCase
{
    /**
     * **Le chemin nominal : téléverser, puis relire l'adresse publique.**
     *
     * Le test que je n'ai pas écrit pour les campagnes, et qui a coûté un 500 en production.
     */
    public function testUnePhotoTeleverseeSeLitPubliquement(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ];
        $produit = $this->produitPublieEnLigne();

        $url = $this->televerser($client, $entete, $produit, $this->imagePng(), 'Vue du bassin nordique');

        // La lecture publique n'emporte AUCUN en-tête d'authentification : c'est tout l'objet.
        // Un client neuf, sans jeton, comme le navigateur d'un visiteur.
        $anonyme = static::createClient();
        $anonyme->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    /**
     * **La photo d'un produit non publié reste privée.**
     *
     * Sans cette borne, la route serait une fuite discrète : les visuels existent avant l'annonce,
     * et « Nouveauté été 2027 » se découvrirait en essayant des adresses.
     */
    public function testLaPhotoDUnProduitNonPublieNeSeSertPas(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ];
        $produit = $this->produitPublieEnLigne();
        $url = $this->televerser($client, $entete, $produit, $this->imagePng(), 'Visuel confidentiel');

        // Le produit repasse en brouillon : la photo doit disparaître du domaine public.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produit = $em->getRepository(Produit::class)->find($produit->getId());
        $produit->setStatut(StatutProduit::Brouillon);
        $em->flush();

        $reponse = $client->request('GET', $url);
        self::assertSame(404, $reponse->getStatusCode());
    }

    /**
     * **Un fichier qui se dit image sans l'être est refusé.**
     *
     * C'est le test qui compte. Le navigateur déclare le type ; on ne le croit pas. Servi depuis
     * notre domaine, un faux PNG contenant du HTML deviendrait du script exécuté chez le visiteur.
     */
    public function testUnFauxPngEstRefuse(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ];
        $produit = $this->produitPublieEnLigne();

        $chemin = tempnam(sys_get_temp_dir(), 'faux') . '.png';
        file_put_contents($chemin, '<script>alert(1)</script>');

        $reponse = $client->request('POST', '/api/offre/produits/' . $produit->getId() . '/photos', $entete + [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => [
                'parameters' => ['altText' => 'Prétendue image'],
                'files' => ['file' => new UploadedFile($chemin, 'piege.png', 'image/png', null, true)],
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode(), 'Le type annoncé ne fait pas foi : les octets, si.');
    }

    /** **Sans texte alternatif, pas de photo** — le RGAA n'est pas une option pour un service public. */
    public function testUnePhotoSansTexteAlternatifEstRefusee(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ];
        $produit = $this->produitPublieEnLigne();

        $reponse = $client->request('POST', '/api/offre/produits/' . $produit->getId() . '/photos', $entete + [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => [
                'parameters' => ['altText' => '   '],
                'files' => ['file' => new UploadedFile($this->imagePng(), 'photo.png', 'image/png', null, true)],
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    /** **Les photos s'ordonnent** : la deuxième passe après la première, elle ne la remplace pas. */
    public function testLesPhotosSOrdonnentAuLieuDeSeSuperposer(): void
    {
        [$client, $token, $idA] = $this->adminSurA();
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA],
        ];
        $produit = $this->produitPublieEnLigne();

        $this->televerser($client, $entete, $produit, $this->imagePng(), 'Première');
        $this->televerser($client, $entete, $produit, $this->imagePng(), 'Deuxième');

        $liste = $client->request('GET', '/api/offre/produits/' . $produit->getId() . '/photos', $entete)
            ->toArray();

        self::assertCount(2, $liste['photos']);
        self::assertSame([0, 1], array_column($liste['photos'], 'position'));
        self::assertSame('Première', $liste['photos'][0]['altText']);
        self::assertTrue($liste['visiblePubliquement']);
    }

    // --- outillage --------------------------------------------------------------------------------

    /** @param array<string, mixed> $entete */
    private function televerser(object $client, array $entete, Produit $produit, string $chemin, string $alt): string
    {
        $cree = $client->request('POST', '/api/offre/produits/' . $produit->getId() . '/photos', $entete + [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => [
                'parameters' => ['altText' => $alt],
                'files' => ['file' => new UploadedFile($chemin, 'photo.png', 'image/png', null, true)],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('url', $cree);

        return $cree['url'];
    }

    /**
     * Un vrai PNG d'un pixel, écrit octet par octet.
     *
     * Pas de `imagecreatetruecolor()` : GD n'est pas installé sur l'image PHP du projet — vérifié,
     * pas supposé. `getimagesize()`, lui, appartient au cœur de PHP et reste disponible : c'est
     * précisément pour cela que le contrôle du téléversement s'appuie sur elle.
     */
    private function imagePng(): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'photo') . '.png';
        file_put_contents($chemin, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));

        return $chemin;
    }

    private function produitPublieEnLigne(): Produit
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produit = $em->getRepository(Produit::class)->find($this->idProduit(OffreFixtures::PRODUIT_ENTREE));
        self::assertInstanceOf(Produit::class, $produit);

        $produit->setStatut(StatutProduit::Publie);
        if (!\in_array('en_ligne', $produit->getCanaux(), true)) {
            $produit->setCanaux([...$produit->getCanaux(), 'en_ligne']);
        }
        $em->flush();

        return $produit;
    }
}
