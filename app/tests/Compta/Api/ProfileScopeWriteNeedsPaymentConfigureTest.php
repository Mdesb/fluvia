<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D2-B de #365 : ce qui ROUTE les encaissements en ligne d'un établissement (`OnlinePaymentProfileResolver` : le profil
 * qui le couvre, principal d'abord ; `SelecteurPaiementEnLigne` : PayFiP ou PSP selon le TYPE du profil) exige
 * `payment.configure` EN PLUS de `compta.gerer`. Un PATCH qui ne touche pas à ce routage reste à `compta.gerer`.
 *
 * Le gestionnaire de test est le « Lecteur » auquel on accorde `compta.gerer` seul. Pour avoir un SECOND établissement
 * atteignable (et ne pas mesurer un refus sur un établissement qu'on ne peut même pas nommer), on lui donne une
 * affectation sur B quand le test le demande (`$reachB`).
 *
 * Établissement « lisible par IRI mais hors périmètre d'écriture » : non constructible. La lecture
 * (`PerimetreEtablissementExtension` : affectations + assistance) est incluse dans l'atteignable
 * (`EstablishmentReachability` : affectations + délégations + assistance). Un IRI hors périmètre est donc refusé
 * en 400 « Item not found » AVANT le processeur ; `assertReachable` (404) est la seconde ligne, couvrant le cas
 * inverse (atteignable sans être lisible : une délégation), non constructible ici non plus sans fixture dédiée.
 */
final class ProfileScopeWriteNeedsPaymentConfigureTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return array{0: object, 1: array<string, mixed>} */
    private function manager(bool $reachB = false): array
    {
        $role = $this->entite(Role::class, ['nom' => 'Lecture seule']);
        $role->addPermission($this->entite(Permission::class, ['module' => 'compta', 'action' => 'gerer']));
        if ($reachB) {
            $this->em()->persist((new Affectation())
                ->setUtilisateur($this->entite(Utilisateur::class, ['email' => SocleFixtures::LECTEUR_EMAIL]))
                ->setRole($role)
                ->setEtablissement($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])));
        }
        $this->em()->flush();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        return [$client, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]]];
    }

    private function grantPaymentConfigure(): void
    {
        $this->entite(Role::class, ['nom' => 'Lecture seule'])->addPermission($this->entite(Permission::class, ['module' => 'payment', 'action' => 'configure']));
        $this->em()->flush();
    }

    private function iri(string $id): string
    {
        return '/api/etablissements/' . $id;
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function patch(object $client, array $entete, array $json): void
    {
        $client->request('PATCH', '/api/profil_exploitants/' . $this->idProfilExploitant(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $json,
        ] + $entete);
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function post(object $client, array $entete, array $json): void
    {
        $client->request('POST', '/api/profil_exploitants', [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => $json,
        ] + $entete);
    }

    /** @return list<string> */
    private function attachedIds(): array
    {
        $this->em()->clear();
        $ids = array_map(static fn (Etablissement $e): string => (string) $e->getId(), $this->profilExploitant()->getEtablissementsRattaches()->toArray());
        sort($ids);

        return $ids;
    }

    private function principalId(): string
    {
        $this->em()->clear();

        return (string) $this->profilExploitant()->getEtablissementPrincipal()?->getId();
    }

    private function typeNow(): TypeExploitant
    {
        $this->em()->clear();

        return $this->profilExploitant()->getType();
    }

    /** Le profil de la fixture couvre A (principal) et B (rattaché). */
    private function coverAAndB(): void
    {
        $this->profilExploitant()->setEtablissementsRattaches([$this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
        $this->em()->flush();
    }

    /** Libère A : le profil de la fixture passe sur l'éditeur et ne rattache rien (ou seulement `$attached`). */
    private function freeSiteA(array $attached = []): void
    {
        $this->profilExploitant()
            ->setEtablissementPrincipal($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_EDITEUR_NOM]))
            ->setEtablissementsRattaches($attached);
        $this->em()->flush();
    }

    public function testAnOrdinaryPatchStaysWithComptaManageEvenWhenThePerimeterIsRewrittenInAnotherOrder(): void
    {
        $this->coverAAndB();
        [$client, $entete] = $this->manager(true);
        $a = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $b = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $this->profilExploitant()->setEtablissementsRattaches([
            $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]),
            $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]),
        ]);
        $this->em()->flush();
        self::assertCount(2, $this->attachedIds(), 'témoin : deux rattachés au départ');

        $this->patch($client, $entete, ['raisonSociale' => 'Régie des Oliviers']);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame('Régie des Oliviers', $this->profilExploitant()->getRaisonSociale());

        // Même périmètre, autre ordre : pas une modification.
        $this->patch($client, $entete, ['etablissementPrincipal' => $this->iri($this->principalId()), 'etablissementsRattaches' => [$this->iri($b), $this->iri($a)]]);
        self::assertResponseIsSuccessful();
    }

    public function testAMultiSiteProfileStaysEditableByACallerWhoReachesOnlyOneOfItsSites(): void
    {
        $this->coverAAndB();
        [$client, $entete] = $this->manager(false);
        $b = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        self::assertSame([$b], $this->attachedIds(), 'témoin : B est bien rattaché et hors de portée du lecteur');

        $this->patch($client, $entete, ['raisonSociale' => 'Multi-sites']);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame('Multi-sites', $this->profilExploitant()->getRaisonSociale());

        // Ajouter un rattaché hors périmètre : refusé, rien n'est écrit.
        $this->grantPaymentConfigure();
        $this->patch($client, $entete, ['etablissementsRattaches' => [$this->iri($b), $this->iri($this->idEtablissement(SocleFixtures::ETAB_EDITEUR_NOM))]]);
        self::assertContains($client->getResponse()->getStatusCode(), [400, 404], 'IRI hors périmètre refusée, même avec le droit');
        self::assertSame([$b], $this->attachedIds(), 'rien n\'est écrit');
    }

    public function testChangingTheAttachedEstablishmentsNeedsPaymentConfigure(): void
    {
        [$client, $entete] = $this->manager(true);
        $a = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $b = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $before = $this->attachedIds();
        self::assertNotEmpty($before, 'témoin : le périmètre de départ n\'est pas vide');

        // B est ATTEIGNABLE : le refus n'est pas un « introuvable » déguisé.
        $this->patch($client, $entete, ['etablissementsRattaches' => [...array_map($this->iri(...), $before), $this->iri($b)]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->attachedIds(), 'rien n\'est écrit');

        $this->patch($client, $entete, ['etablissementsRattaches' => []]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->attachedIds(), 'le vidage non plus');

        $this->grantPaymentConfigure();
        $this->patch($client, $entete, ['etablissementsRattaches' => [$this->iri($a), $this->iri($b)]]);
        self::assertResponseIsSuccessful();
        $expected = [$a, $b];
        sort($expected);
        self::assertSame($expected, $this->attachedIds(), 'témoin : avec le droit, le même PATCH écrit');

        $this->patch($client, $entete, ['etablissementsRattaches' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->attachedIds());
    }

    public function testChangingThePrincipalNeedsPaymentConfigure(): void
    {
        [$client, $entete] = $this->manager(true);
        $principal = $this->principalId();
        $b = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $this->patch($client, $entete, ['etablissementPrincipal' => $this->iri($b)]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($principal, $this->principalId(), 'rien n\'est écrit');

        $this->grantPaymentConfigure();
        $this->patch($client, $entete, ['etablissementPrincipal' => $this->iri($b)]);
        self::assertResponseIsSuccessful();
        self::assertSame($b, $this->principalId(), 'témoin : avec le droit, le même PATCH écrit');
    }

    public function testChangingTheTypeNeedsPaymentConfigure(): void
    {
        [$client, $entete] = $this->manager(false);
        self::assertSame(TypeExploitant::RegieDirecte, $this->typeNow(), 'témoin : le profil part en régie directe');

        $this->patch($client, $entete, ['raisonSociale' => 'Même type']);
        self::assertResponseIsSuccessful();
        $this->patch($client, $entete, ['type' => TypeExploitant::RegieDirecte->value]);
        self::assertResponseIsSuccessful();

        $this->patch($client, $entete, ['type' => TypeExploitant::Dsp->value]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(TypeExploitant::RegieDirecte, $this->typeNow(), 'rien n\'est écrit');

        $this->grantPaymentConfigure();
        $this->patch($client, $entete, ['type' => TypeExploitant::Dsp->value]);
        self::assertResponseIsSuccessful();
        self::assertSame(TypeExploitant::Dsp, $this->typeNow(), 'témoin : avec le droit, le même PATCH écrit');
    }

    public function testAnEstablishmentOutOfTheCallersScopeCannotBeDesignatedEvenWithPaymentConfigure(): void
    {
        [$client, $entete] = $this->manager(false);
        $this->grantPaymentConfigure();
        $principal = $this->principalId();
        $a = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $foreign = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $this->patch($client, $entete, ['etablissementsRattaches' => [$this->iri($a)]]);
        self::assertResponseIsSuccessful();
        self::assertSame([$a], $this->attachedIds(), 'témoin : un établissement atteignable passe');

        foreach ([['etablissementsRattaches' => [$this->iri($a), $this->iri($foreign)]], ['etablissementPrincipal' => $this->iri($foreign)]] as $body) {
            $this->patch($client, $entete, $body);
            self::assertContains($client->getResponse()->getStatusCode(), [400, 404], 'refusé');
            self::assertSame([$a], $this->attachedIds(), 'rien n\'est écrit');
            self::assertSame($principal, $this->principalId(), 'rien n\'est écrit');
        }
    }

    public function testCreationOnAnUncoveredActiveEstablishmentKeepsComptaManage(): void
    {
        [$client, $entete] = $this->manager(false);
        $this->freeSiteA();

        $this->post($client, $entete, ['siren' => '123456789', 'etablissementPrincipal' => $this->iri($this->idEtablissement(SocleFixtures::ETAB_A_NOM))]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testCreationCannotCaptureAnEstablishmentAnotherProfileAlreadyCovers(): void
    {
        [$client, $entete] = $this->manager(false);
        // A est RATTACHÉ à un autre profil : un profil neuf dont A serait le principal passerait devant lui.
        $this->freeSiteA([$this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])]);
        $body = ['siren' => '123456789', 'etablissementPrincipal' => $this->iri($this->idEtablissement(SocleFixtures::ETAB_A_NOM))];

        $this->post($client, $entete, $body);
        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(ProfilExploitant::class)->findOneBy(['siren' => '123456789']), 'rien n\'est écrit');

        $this->grantPaymentConfigure();
        $this->post($client, $entete, $body);
        self::assertResponseStatusCodeSame(201);
    }

    public function testCreationWithAnAttachedEstablishmentNeedsPaymentConfigure(): void
    {
        [$client, $entete] = $this->manager(true);
        $this->freeSiteA();
        $body = ['siren' => '123456789', 'etablissementPrincipal' => $this->iri($this->idEtablissement(SocleFixtures::ETAB_A_NOM)), 'etablissementsRattaches' => [$this->iri($this->idEtablissement(SocleFixtures::ETAB_B_NOM))]];

        $this->post($client, $entete, $body);
        self::assertResponseStatusCodeSame(403);

        $this->grantPaymentConfigure();
        $this->post($client, $entete, $body);
        self::assertResponseStatusCodeSame(201);
    }
}
