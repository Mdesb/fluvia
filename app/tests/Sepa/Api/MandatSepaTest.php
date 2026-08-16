<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Sepa\Entity\MandatSepa;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `MandatSepa` (plan-sepa.md §2/§6) : création via processor (IBAN en clair en entrée uniquement,
 * jamais persisté ni exposé), lecture masquée (4 derniers chiffres seulement).
 */
final class MandatSepaTest extends SepaApiTestCase
{
    public function testCreationMandatTokeniseLibanEtNeLexposeJamais(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client_ = $em->getRepository(\App\Crm\Entity\Client::class)->findOneBy(['email' => \App\Crm\DataFixtures\CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($client_);

        $client->request('POST', '/api/sepa/mandats', $entete + ['json' => [
            'client' => '/api/clients/' . $client_->getId(),
            'etablissement' => '/api/etablissements/' . $idA,
            'iban' => 'FR7630006000099876543210987',
            'bicDebiteur' => 'AGRIFRPPXXX',
            'debiteurNom' => 'Test Nouveau Mandat',
        ]]);
        self::assertResponseIsSuccessful();
        $mandat = $client->getResponse()->toArray();

        self::assertSame('actif', $mandat['statut']);
        self::assertSame('0987', $mandat['iban4Derniers']);
        self::assertArrayNotHasKey('ibanToken', $mandat);
        self::assertArrayNotHasKey('ibanChiffre', $mandat);
        self::assertArrayNotHasKey('iban', $mandat);
        self::assertStringStartsWith('RUM-', $mandat['rum']);

        $mandatEntite = $em->getRepository(MandatSepa::class)->find($mandat['id']);
        self::assertNotNull($mandatEntite);
        self::assertStringNotContainsString('FR7630006000099876543210987', $mandatEntite->getIbanToken());
        self::assertNotSame('FR7630006000099876543210987', $mandatEntite->getIbanToken());

        // Coffre IBAN réversible : l'IBAN chiffré est bien stocké (permet à Pain008Generator de
        // reconstruire le vrai IBAN), mais n'est jamais l'IBAN en clair ni exposé en API (ci-dessus).
        self::assertNotNull($mandatEntite->getIbanChiffre());
        self::assertStringNotContainsString('FR7630006000099876543210987', (string) $mandatEntite->getIbanChiffre());
        /** @var \App\Sepa\Service\ChiffreurIbanInterface $chiffreur */
        $chiffreur = static::getContainer()->get(\App\Sepa\Service\ChiffreurIbanInterface::class);
        self::assertSame('FR7630006000099876543210987', $chiffreur->dechiffrer((string) $mandatEntite->getIbanChiffre()));
    }

    public function testIbanJamaisExposeSurLesReponsesMandatDeDemo(): void
    {
        [$client, $entete] = $this->adminSurA();
        $mandatId = $this->idMandatDemo('RUM-DEMO-REGIE-0001');

        $client->request('GET', '/api/mandat_sepas/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString('ibanToken', $corps);
        self::assertStringNotContainsString('ibanChiffre', $corps);
        self::assertStringNotContainsString('FR76', $corps);

        $client->request('GET', '/api/mandat_sepas', $entete);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('ibanToken', $client->getResponse()->getContent());
        self::assertStringNotContainsString('ibanChiffre', $client->getResponse()->getContent());
    }

    public function testCloisonnementUnAgentDunAutreGroupeNeVoitAucunMandatDeAOuB(): void
    {
        // Même pattern que `App\Tests\Sport\Api\CloisonnementTest` : un utilisateur affecté à un
        // établissement d'un autre groupe (même avec les permissions `sepa.*` requises, cf. fixtures)
        // ne voit aucun mandat des établissements A/B (RG-SOCLE-05).
        $client = static::createClient();
        $token = $this->jeton($client, \App\Crm\DataFixtures\CrmFixtures::AGENT_B_EMAIL, \App\Crm\DataFixtures\CrmFixtures::AGENT_B_MDP);
        $idEtabC = $this->idEtablissement(\App\Crm\DataFixtures\CrmFixtures::ETAB_C_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idEtabC]];

        $client->request('GET', '/api/mandat_sepas', $entete);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertEmpty($membres, 'Aucun mandat visible hors périmètre établissement (RG-SOCLE-05).');
    }
}
