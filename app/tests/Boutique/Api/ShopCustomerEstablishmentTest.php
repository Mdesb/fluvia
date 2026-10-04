<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\Adapter\ClientM4Adapter;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * LE CLIENT NÉ D'UN ACHAT EN LIGNE APPARTIENT À L'ÉTABLISSEMENT DE LA VITRINE — relevé le 04/10/2026.
 *
 * `ClientM4Adapter::creerRapide()` prenait `etablissementActif() ?? findOneBy([])`. Or le visiteur de la
 * boutique est anonyme : son en-tête `X-Etablissement` n'est validé par personne, et sans lui le dépôt
 * rendait UN établissement quelconque. En préprod, les 8 clients nés de paniers de « Piscine A » étaient
 * rattachés à « Musée C » — un autre groupe, donc un autre client de la plateforme, qui lisait leurs fiches.
 */
final class ShopCustomerEstablishmentTest extends BoutiqueApiTestCase
{
    public function testLeClientPrendLEtablissementDuPanierSansEnTete(): void
    {
        $client = $this->clientNeDuPanier([]);

        $this->assertRattacheAA($client);
    }

    public function testUnEnTeteForgeVersUnAutreClientNeDeplaceRien(): void
    {
        $etabC = $this->entite(Etablissement::class, ['nom' => CrmFixtures::ETAB_C_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabC);
        // Témoin du choix : « Musée C » est bien chez un AUTRE client que « Piscine A ».
        self::assertNotEquals(
            $this->etablissementA()->getRegion()?->getGroupe()?->getId(),
            $etabC->getRegion()?->getGroupe()?->getId(),
            'Le témoin doit viser un établissement d’un autre groupe.',
        );

        $client = $this->clientNeDuPanier([ContexteEtablissement::HEADER => (string) $etabC->getId()]);

        $this->assertRattacheAA($client);
    }

    /** Sans établissement connu, on refuse : choisir « le premier venu » rattachait au hasard. */
    public function testSansEtablissementConnuLaCreationEstRefusee(): void
    {
        $adapter = static::getContainer()->get(ClientM4Adapter::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('aucun établissement connu');
        $adapter->creerRapide(['nom' => 'Sans établissement']);
    }

    /** @param array<string, string> $entetesEnPlus */
    private function clientNeDuPanier(array $entetesEnPlus): Client
    {
        [$http, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton] + $entetesEnPlus;

        // Le consentement résout (ou crée) le Client payeur du panier.
        $http->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => 'rattachement.' . bin2hex(random_bytes(3)) . '@example.test'],
        ]);
        self::assertResponseIsSuccessful();
        $http->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['rgpd' => true],
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panier);
        self::assertNotNull($panier->getClientResolu(), 'Le consentement doit avoir résolu un client.');
        $client = $this->em()->getRepository(Client::class)->find($panier->getClientResolu());
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    private function assertRattacheAA(Client $client): void
    {
        $etabA = $this->etablissementA();
        self::assertEquals($etabA->getId(), $client->getEtablissementCreation()?->getId(), 'Le client doit naître dans l’établissement de la vitrine.');
        self::assertEquals($etabA->getRegion()?->getGroupe()?->getId(), $client->getGroupe()?->getId(), 'Le client doit appartenir au groupe de la vitrine.');
    }

    private function etablissementA(): Etablissement
    {
        $etab = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        return $etab;
    }
}
