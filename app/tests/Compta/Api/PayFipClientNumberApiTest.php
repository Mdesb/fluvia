<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Numéro client PayFiP par régie (D2-B de #365) : l'écriture passe par `PATCH …/payfip_client_number`,
 * droit `payment.configure`, depuis l'établissement principal du profil, régie directe seulement. Le PATCH
 * ordinaire du profil (`compta.gerer`) ne le lit jamais.
 *
 * Chaque refus a son témoin positif (la même écriture, passée ailleurs) : un refus ne prouve rien seul.
 */
final class PayFipClientNumberApiTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return string|null valeur lue en SQL brut (pas par l'ORM) */
    private function stored(ProfilExploitant $profile): ?string
    {
        $value = $this->em()->getConnection()->fetchOne(
            'SELECT payfip_client_number FROM compta_profil_exploitant WHERE id = ?',
            [Uuid::fromString((string) $profile->getId())->toBinary()],
        );

        return $value === false || $value === null ? null : (string) $value;
    }

    private function seed(?string $number, TypeExploitant $type = TypeExploitant::RegieDirecte): ProfilExploitant
    {
        $profile = $this->profilExploitant()->setType($type)->setPayfipClientNumber($number);
        $this->em()->flush();

        return $profile;
    }

    /**
     * Un utilisateur qui porte `compta.gerer` sans `payment.configure` : le rôle « Lecture seule » du lecteur
     * reçoit `compta.gerer` (il y a déjà `*.lire`).
     */
    private function grantComptaManageToReader(): void
    {
        $role = $this->entite(Role::class, ['nom' => 'Lecture seule']);
        $role->addPermission($this->entite(Permission::class, ['module' => 'compta', 'action' => 'gerer']));
        $this->em()->flush();
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} */
    private function readerOnA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        return [$client, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]]];
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function patchNumber(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $profileId, array $json): void
    {
        $client->request('PATCH', '/api/profil_exploitants/' . $profileId . '/payfip_client_number', [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $json,
        ] + $entete);
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function patchProfile(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $profileId, array $json): void
    {
        $client->request('PATCH', '/api/profil_exploitants/' . $profileId, [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $json,
        ] + $entete);
    }

    public function testWithoutThePermissionTheWriteIsForbiddenAndNothingIsStored(): void
    {
        $profile = $this->seed('111111');
        $this->grantComptaManageToReader();
        [$client, $entete] = $this->readerOnA();

        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => '222222']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('111111', $this->stored($profile));
    }

    public function testWithThePermissionFromThePrincipalTheNumberIsWritten(): void
    {
        $profile = $this->seed(null);
        [$client, $entete] = $this->adminSurA();

        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => '123456']);

        self::assertResponseIsSuccessful();
        self::assertSame('123456', $this->stored($profile));
        self::assertSame('123456', $client->getResponse()->toArray()['payfipClientNumber']);
    }

    public function testAnAttachedNonPrincipalEstablishmentCannotWriteAndNothingIsStored(): void
    {
        $profileOnB = (new ProfilExploitant())
            ->setType(TypeExploitant::RegieDirecte)
            ->setReferentielComptable(ReferentielComptable::M57)
            ->setSiren('888888888')
            ->setEtablissementPrincipal($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]));
        $profileOnB->addEtablissementRattache($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]));
        $this->em()->persist($profileOnB);
        $own = $this->seed(null);
        $this->em()->flush();
        [$client, $entete] = $this->adminSurA();

        $this->patchNumber($client, $entete, (string) $profileOnB->getId(), ['payfipClientNumber' => '654321']);
        // Mesuré : l'extension de périmètre rend l'item introuvable avant la sécurité (UNVERIFIED 2).
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->stored($profileOnB), 'rien n\'est écrit chez le voisin');

        // Témoin positif : la même requête sur son profil principal passe.
        $this->patchNumber($client, $entete, (string) $own->getId(), ['payfipClientNumber' => '654321']);
        self::assertResponseIsSuccessful();
        self::assertSame('654321', $this->stored($own));
    }

    public function testTheOrdinaryProfilePatchNeverModifiesTheNumber(): void
    {
        $profile = $this->seed('111111');
        $this->grantComptaManageToReader();
        [$client, $entete] = $this->readerOnA();

        // Un utilisateur `compta.gerer` : la raison sociale change (témoin positif), le numéro non.
        $this->patchProfile($client, $entete, (string) $profile->getId(), ['raisonSociale' => 'Regie temoin', 'payfipClientNumber' => '654321']);
        $status = $client->getResponse()->getStatusCode();
        // Mesuré (UNVERIFIED 1) : champ hors groupe d'écriture ignoré, 200.
        self::assertSame(200, $status);
        $this->em()->clear();
        self::assertSame('Regie temoin', $this->profilExploitant()->getRaisonSociale());
        self::assertSame('111111', $this->stored($profile));

        // Même cumul de droits (`compta.gerer` + `payment.configure`) : l'opération ordinaire ne le lit pas.
        [$admin, $enteteAdmin] = $this->adminSurA();
        $this->patchProfile($admin, $enteteAdmin, (string) $profile->getId(), ['raisonSociale' => 'Regie cumul', 'payfipClientNumber' => '654321']);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame('Regie cumul', $this->profilExploitant()->getRaisonSociale());
        self::assertSame('111111', $this->stored($profile));
    }

    public function testAMalformedNumberIsRefusedWithoutEchoAndNothingIsStored(): void
    {
        $profile = $this->seed('111111');
        [$client, $entete] = $this->adminSurA();

        foreach (['12345', '1234567', '12345a'] as $bad) {
            $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => $bad]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringNotContainsString($bad, $client->getResponse()->getContent(false));
            self::assertSame('111111', $this->stored($profile));
        }

        // Témoin positif : six chiffres passent.
        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => '222222']);
        self::assertResponseIsSuccessful();
        self::assertSame('222222', $this->stored($profile));
    }

    public function testOnlyADirectRegieCanReceiveANumber(): void
    {
        [$client, $entete] = $this->adminSurA();

        foreach ([TypeExploitant::GroupePrive, TypeExploitant::Dsp] as $type) {
            $profile = $this->seed(null, $type);
            $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => '123456']);
            self::assertResponseStatusCodeSame(422);
            self::assertNull($this->stored($profile), $type->value);
        }

        // Témoin positif : la même saisie sur une régie directe passe.
        $profile = $this->seed(null);
        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => '123456']);
        self::assertResponseIsSuccessful();
        self::assertSame('123456', $this->stored($profile));
    }

    public function testClearingIsAllowedForARegieAndForALeftoverOnANonRegieProfile(): void
    {
        [$client, $entete] = $this->adminSurA();

        $profile = $this->seed('123456');
        self::assertSame('123456', $this->stored($profile), 'témoin : le numéro est bien en base avant l\'effacement');
        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stored($profile));

        $profile = $this->seed('123456');
        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => '']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stored($profile));

        // Reliquat d'un changement de type (décision par défaut, UNVERIFIED 4) : seule la saisie est refusée.
        $profile = $this->seed('123456', TypeExploitant::GroupePrive);
        self::assertSame('123456', $this->stored($profile));
        $this->patchNumber($client, $entete, (string) $profile->getId(), ['payfipClientNumber' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stored($profile));
    }

    public function testTheNumberIsReadWithTheProfile(): void
    {
        $profile = $this->seed('123456');
        $this->grantComptaManageToReader();
        [$client, $entete] = $this->readerOnA();

        $client->request('GET', '/api/profil_exploitants/' . $profile->getId(), $entete);

        self::assertResponseIsSuccessful();
        self::assertSame('123456', $client->getResponse()->toArray()['payfipClientNumber']);
    }

    public function testTheFieldIsInNoWriteGroupButTheDedicatedOne(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3) . '/src/Compta/Entity/ProfilExploitant.php');
        self::assertSame(1, preg_match("/#\\[Groups\\(\\[([^\\]]*)\\]\\)\\]\\s*private \\?string \\\$payfipClientNumber/", $source, $m), 'témoin : le motif trouve la propriété');
        self::assertStringContainsString('profil:payfip:write', $m[1]);
        self::assertStringNotContainsString('profil:write', $m[1]);
        // Qu'aucun PATCH ordinaire ne l'écrive : prouvé par comportement dans les tests de PATCH ci-dessus.
    }
}
