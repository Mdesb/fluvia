<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Tests\Acces\AccesApiTestCase;

/**
 * Appairage Support ↔ DroitAcces (US-L3-02, écran A-02, CA-2) : lien + type enregistrés ; support
 * déjà appairé actif → refus explicite ; support bloqué → refus explicite ; un seul appairage actif
 * par support ; la révocation libère le support pour un ré-appairage.
 */
final class AppairageTest extends AccesApiTestCase
{
    public function testCa2AppairageEnregistreLienEtType(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => 'RFID-NEUF-0001',
                'typeSupport' => 'RFID',
                'droit' => '/api/droit_acces/' . $this->idDroit2emeSupport(),
                'mode' => 'autonome',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));
        $appairage = $reponse->toArray();
        self::assertTrue($appairage['actif']);
        self::assertSame('autonome', $appairage['mode']);
    }

    public function testCa2SupportDejaAppaireActifRefuseExplicitement(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Le support de démonstration (fixtures) est déjà appairé activement.
        $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => \App\Acces\DataFixtures\AccesFixtures::SUPPORT_IDENTIFIANT,
                'typeSupport' => 'QR',
                'droit' => '/api/droit_acces/' . $this->idDroit2emeSupport(),
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCa2SupportBloqueRefuseAppairage(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $entete + [
            'json' => ['motif' => 'Support perdu'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => \App\Acces\DataFixtures\AccesFixtures::SUPPORT_IDENTIFIANT,
                'typeSupport' => 'QR',
                'droit' => '/api/droit_acces/' . $this->idDroit2emeSupport(),
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCa2RevocationLibereLeSupportPourReAppairage(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Trouver l'appairage actif du support de démonstration.
        $reponse = $client->request('GET', '/api/appairages', $entete);
        $liste = $reponse->toArray()['member'] ?? $reponse->toArray()['hydra:member'];
        $actif = null;
        foreach ($liste as $item) {
            if (($item['actif'] ?? false) === true) {
                $actif = $item;
                break;
            }
        }
        self::assertNotNull($actif);

        $client->request('POST', '/api/acces/appairages/' . basename((string) $actif['id']) . '/revoquer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $revoque = $client->getResponse()->toArray();
        self::assertFalse($revoque['actif']);

        // Ré-appairage désormais possible.
        $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => \App\Acces\DataFixtures\AccesFixtures::SUPPORT_IDENTIFIANT,
                'typeSupport' => 'QR',
                'droit' => '/api/droit_acces/' . $this->idDroit2emeSupport(),
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** Crée un second droit d'accès (billet simple) directement en base pour les tests d'appairage. */
    private function idDroit2emeSupport(): string
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $droit = new \App\Acces\Entity\DroitAcces();
        $droit->setSourceType(\App\Acces\Enum\TypeDroitAcces::Billet)
            ->setStatutProjection(\App\Acces\Enum\StatutProjectionDroit::Valide)
            ->setEtablissement($this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]));
        $em->persist($droit);
        $em->flush();

        return (string) $droit->getId();
    }
}
