<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Audit\Entity\EntreeAudit;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Utilisateur;
use App\Tests\Caisse\CaisseClotureRoleApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Code agent de caisse (#346).
 *
 * Le défaut d'origine : `RouvrirCaisseProcessor` acceptait n'importe quel code régisseur non vide.
 * Témoin : un code FAUX est refusé et la caisse reste sécurisée ; le code JUSTE est accepté.
 * Deux comptes : l'admin (établissement A) et le régisseur B (établissement B), qui ne doit ni poser
 * ni effacer le code d'un autre.
 */
final class AgentPinTest extends CaisseClotureRoleApiTestCase
{
    private const PIN = '482915';

    public function testReouvertureRefuseUnCodeFauxEtAccepteLeCodeJuste(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->definirPin($client, $entete, $this->idAdmin(), self::PIN);
        $session = $this->sessionClose($client, $entete);
        self::assertSame(EtatCaisse::Securisee, $this->etatCaisse());

        // Le témoin de la faille : avant #346, ce code-ci rouvrait la caisse.
        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => 'CODE-REGIE-2026']]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => '000000']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(EtatCaisse::Securisee, $this->etatCaisse(), 'Un code faux ne doit rien rouvrir.');

        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => self::PIN]]);
        self::assertResponseIsSuccessful();
        self::assertSame(EtatCaisse::Ouverte, $this->etatCaisse());
        self::assertSame(0, $this->utilisateur(SocleFixtures::ADMIN_EMAIL)->getCashPinFailures(), 'Un succès remet le compteur à zéro.');
        self::assertGreaterThanOrEqual(2, $this->journal('cash_pin.refused'));
        self::assertSame(1, $this->journal('cash_register.reopened'));
    }

    public function testReouvertureRefuseeSiLeRegisseurNaPasDeCode(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->sessionClose($client, $entete);

        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => self::PIN]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(EtatCaisse::Securisee, $this->etatCaisse());
    }

    public function testCinqErreursBloquentLeCodeMemeJusteJusquaEffacement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->definirPin($client, $entete, $this->idAdmin(), self::PIN);
        $session = $this->sessionClose($client, $entete);

        for ($i = 1; $i <= 4; ++$i) {
            $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => '1111']]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => '1111']]);
        self::assertResponseStatusCodeSame(423);

        // Bloqué : le code JUSTE est refusé aussi — sinon le blocage ne protège rien.
        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => self::PIN]]);
        self::assertResponseStatusCodeSame(423);
        self::assertSame(EtatCaisse::Securisee, $this->etatCaisse());

        // L'administrateur efface le code ; le titulaire en redéfinit un, et il passe.
        $client->request('POST', '/api/utilisateurs/' . $this->idAdmin() . '/cash-pin/reset', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->utilisateur(SocleFixtures::ADMIN_EMAIL)->isCashPinDefined());
        $this->definirPin($client, $entete, $this->idAdmin(), '7531');
        $client->request('POST', '/api/sessions-caisse/' . $session . '/rouvrir', $entete + ['json' => ['codeRegisseur' => '7531']]);
        self::assertResponseIsSuccessful();
    }

    public function testDefinirSonCodeExigeLeMotDePasseEtUnFormatNumerique(): void
    {
        [$client, $entete] = $this->adminSurA();
        $url = '/api/utilisateurs/' . $this->idAdmin() . '/cash-pin';

        $client->request('POST', $url, $entete + ['json' => ['motDePasse' => 'mauvais', 'pin' => self::PIN]]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, $entete + ['json' => ['motDePasse' => SocleFixtures::ADMIN_MDP, 'pin' => '12a4']]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, $entete + ['json' => ['motDePasse' => SocleFixtures::ADMIN_MDP, 'pin' => '123']]);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->utilisateur(SocleFixtures::ADMIN_EMAIL)->isCashPinDefined());

        $lu = $client->request('POST', $url, $entete + ['json' => ['motDePasse' => SocleFixtures::ADMIN_MDP, 'pin' => self::PIN]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($lu, 'Sinon l\'absence du haché ne prouverait rien.');
        self::assertTrue($lu['cashPinDefined']);
        self::assertArrayNotHasKey('cashPinHash', $lu, 'Le haché ne sort jamais.');

        $hash = $this->utilisateur(SocleFixtures::ADMIN_EMAIL)->getCashPinHash();
        self::assertNotNull($hash);
        self::assertNotSame(self::PIN, $hash, 'Le code est stocké haché, jamais en clair.');
        self::assertTrue(password_verify(self::PIN, $hash));
    }

    public function testUnAutreCompteNePeutNiPoserNiEffacerLeCode(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $this->definirPin($clientA, $enteteA, $this->idAdmin(), self::PIN);
        $avant = $this->utilisateur(SocleFixtures::ADMIN_EMAIL)->getCashPinHash();

        [$clientB, $enteteB] = $this->regisseurSurB();
        $clientB->request('POST', '/api/utilisateurs/' . $this->idAdmin() . '/cash-pin', $enteteB + [
            'json' => ['motDePasse' => SocleFixtures::ADMIN_MDP, 'pin' => '9999'],
        ]);
        self::assertContains($clientB->getResponse()->getStatusCode(), [403, 404]);
        $clientB->request('POST', '/api/utilisateurs/' . $this->idAdmin() . '/cash-pin/reset', $enteteB + ['json' => []]);
        self::assertContains($clientB->getResponse()->getStatusCode(), [403, 404]);

        self::assertSame($avant, $this->utilisateur(SocleFixtures::ADMIN_EMAIL)->getCashPinHash(), 'Le code de A est intact.');
    }

    public function testOuvertureExigeLeCodeAgentQuandLeGuichetLImpose(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->exigerPin(true);
        $corps = [
            'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
            'caisse' => '/api/caisses/' . $this->idCaisse(),
            'fondDeCaisse' => '50.00',
        ];

        // Sans code défini, sans saisie, avec un code faux : refusé, et aucune session n'existe.
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $corps + ['agentPin' => self::PIN]]);
        self::assertResponseStatusCodeSame(422);
        $this->definirPin($client, $entete, $this->idAdmin(), self::PIN);
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $corps + ['agentPin' => '0000']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->nombreSessions());

        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $corps + ['agentPin' => self::PIN]])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($session['agentPinVerified']);
        self::assertSame(1, $this->journal('cash_session.opened'));
    }

    public function testGuichetSansExigenceOuvreSansCodeMaisRefuseUnCodeFaux(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->definirPin($client, $entete, $this->idAdmin(), self::PIN);
        $corps = [
            'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
            'caisse' => '/api/caisses/' . $this->idCaisse(),
            'fondDeCaisse' => '50.00',
        ];

        // Un code saisi est toujours vérifié : un code faux n'ouvre pas, même ici.
        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $corps + ['agentPin' => '0000']]);
        self::assertResponseStatusCodeSame(422);

        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + ['json' => $corps])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($session['agentPinVerified'], 'Sans code saisi, la session ne se prétend pas vérifiée.');
    }

    /** @param array<string, mixed> $entete */
    private function definirPin(Client $client, array $entete, string $idUtilisateur, string $pin): void
    {
        $client->request('POST', '/api/utilisateurs/' . $idUtilisateur . '/cash-pin', $entete + [
            'json' => ['motDePasse' => SocleFixtures::ADMIN_MDP, 'pin' => $pin],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Ouvre puis clôture une session : la caisse passe « sécurisée ».
     *
     * @param array<string, mixed> $entete
     */
    private function sessionClose(Client $client, array $entete): string
    {
        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'fondDeCaisse' => '50.00',
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        $client->request('POST', '/api/sessions-caisse/' . $session['id'] . '/cloturer', $entete + ['json' => ['comptages' => []]]);
        self::assertResponseIsSuccessful();

        return $session['id'];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }

    private function etatCaisse(): EtatCaisse
    {
        $caisse = $this->em()->getRepository(Caisse::class)->findOneBy(['libelle' => VenteFixtures::CAISSE_LIBELLE]);
        self::assertInstanceOf(Caisse::class, $caisse);

        return $caisse->getEtat();
    }

    private function utilisateur(string $email): Utilisateur
    {
        $u = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $u);

        return $u;
    }

    private function exigerPin(bool $exiger): void
    {
        $em = $this->em();
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => VenteFixtures::PDV_LIBELLE]);
        self::assertInstanceOf(PointDeVente::class, $pdv);
        $pdv->setRequireAgentPin($exiger);
        $em->flush();
    }

    private function nombreSessions(): int
    {
        return $this->em()->getRepository(SessionCaisse::class)->count([]);
    }

    private function journal(string $action): int
    {
        return $this->em()->getRepository(EntreeAudit::class)->count(['action' => $action]);
    }
}
