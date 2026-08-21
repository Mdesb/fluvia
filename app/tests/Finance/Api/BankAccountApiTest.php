<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\Sepa\Service\ChiffreurIbanInterface;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * CA-1 (US-TRE-01, RG-TRE-01) : l'IBAN n'apparaît jamais en clair dans une réponse API JSON — seul
 * `ibanLast4` est lisible.
 */
final class BankAccountApiTest extends TreasuryApiTestCase
{
    public function testIbanJamaisExposeEnClairEnLecture(): void
    {
        [$client, $entete] = $this->adminSurA();

        $compte = $this->creerCompteBancaire($client, $entete);

        $brut = $client->getResponse()->getContent();
        self::assertStringNotContainsString('FR7630006000011234567890189', $brut);
        self::assertArrayNotHasKey('ibanCipher', $compte);
        self::assertArrayNotHasKey('ibanClear', $compte);
        self::assertSame('0189', $compte['ibanLast4']);

        // Relecture explicite (GET) : même garde.
        $relu = $client->request('GET', '/api/bank_accounts/' . $compte['id'], $entete);
        $bruteRelue = $relu->getContent();
        self::assertStringNotContainsString('FR7630006000011234567890189', $bruteRelue);
        self::assertArrayNotHasKey('ibanCipher', $relu->toArray());
    }

    public function testCreationSansIbanRestePossible(): void
    {
        [$client, $entete] = $this->adminSurA();

        $compte = $this->creerCompteBancaire($client, $entete, ['ibanClear' => '', 'label' => 'Compte sans IBAN saisi']);

        self::assertSame('', $compte['ibanLast4']);
    }

    public function testModificationSansIbanClearConserveIbanDejaChiffre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        $modifie = $client->request('PATCH', '/api/bank_accounts/' . $compte['id'], $this->entetePatch($entete) + [
            'json' => ['label' => 'Nouveau libellé'],
        ])->toArray();

        self::assertSame('Nouveau libellé', $modifie['label']);
        self::assertSame('0189', $modifie['ibanLast4'], 'Un PATCH sans ibanClear ne doit pas effacer l\'IBAN déjà chiffré.');
    }

    public function testIbanChiffreEtDechiffrableViaCoffreSepa(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compte = $this->creerCompteBancaire($client, $entete);

        $entite = $this->entite(\App\Finance\Treasury\Entity\BankAccount::class, ['id' => $compte['id']]);
        self::assertNotNull($entite->getIbanCipher());

        /** @var ChiffreurIbanInterface $chiffreur */
        $chiffreur = static::getContainer()->get(ChiffreurIbanInterface::class);
        self::assertSame('FR7630006000011234567890189', $chiffreur->dechiffrer((string) $entite->getIbanCipher()));
    }
}
