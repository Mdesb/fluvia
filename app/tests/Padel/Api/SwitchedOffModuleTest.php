<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\DataFixtures\SocleFixtures;
use App\Padel\DataFixtures\PadelFixtures;
use App\Padel\Entity\PlageHoraire;
use App\Padel\Entity\ReservationPadel;
use App\Padel\Entity\Tournoi;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Padel\PadelApiTestCase;
use App\Tests\Platform\ModuleSwitch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Recentrage, CA-1 — un module éteint pour un établissement n'existe plus pour lui.
 *
 * Padel est pris comme module masqué réel : éteint pour A, allumé pour B. Pour A, lecture,
 * écriture et sous-ressource rendent 404 — la même réponse qu'une ressource inexistante, pour ne
 * pas révéler que le module existe. Pour B, les mêmes appels sont servis : c'est le témoin sans
 * lequel un 404 pourrait venir de n'importe quoi (une route mal écrite, un cloisonnement).
 *
 * Deux points de vigilance de la spec y ont aussi leur témoin : un rôle joker (`*.*`) ne rallume
 * pas le module, et une donnée née du module reste lisible par le cœur qui la porte.
 */
final class SwitchedOffModuleTest extends PadelApiTestCase
{
    use ModuleSwitch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->switchModule('padel', false, SocleFixtures::ETAB_A_NOM);
        $this->switchModule('padel', true, SocleFixtures::ETAB_B_NOM);
        self::ensureKernelShutdown();
    }

    public function testReadsOfASwitchedOffModuleAre404(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $terrain = $this->idTerrain();

        $client->request('GET', '/api/padel_terrains', $headers);
        self::assertResponseStatusCodeSame(404, 'collection');

        $client->request('GET', '/api/padel_terrains/' . $terrain, $headers);
        self::assertResponseStatusCodeSame(404, 'élément existant');
    }

    public function testWritesOfASwitchedOffModuleAre404AndWriteNothing(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $before = $this->rowCount(PlageHoraire::class);

        $client->request('POST', '/api/padel_plage_horaires', $headers + [
            'json' => ['libelle' => 'creuse', 'joursApplicables' => [3]],
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->rowCount(PlageHoraire::class), 'un 404 qui a quand même écrit serait pire qu\'un 200');
    }

    public function testSubResourcesOfASwitchedOffModuleAre404(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $before = $this->rowCount(ReservationPadel::class);

        $client->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $headers + ['json' => []]);
        self::assertResponseStatusCodeSame(404, 'sous-ressource en écriture');
        self::assertSame($before, $this->rowCount(ReservationPadel::class));

        $tournoi = (string) $this->entite(Tournoi::class, ['nom' => PadelFixtures::TOURNOI_NOM])->getId();
        $client->request('GET', '/api/padel/tournois/' . $tournoi . '/classement', $headers);
        self::assertResponseStatusCodeSame(404, 'sous-ressource en lecture');
    }

    /** Le 404 de la garde ne se distingue pas de celui d'une ressource qui n'existe pas. */
    public function testTheGuard404LooksLikeAMissingResource(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();

        $guarded = $client->request('GET', '/api/padel_terrains/' . $this->idTerrain(), $headers);
        self::assertResponseStatusCodeSame(404);

        $onB = $headers;
        $onB['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $missing = $client->request('GET', '/api/padel_terrains/' . Uuid::v4(), $onB);
        self::assertResponseStatusCodeSame(404);

        $keep = static fn (array $body): array => array_intersect_key($body, array_flip(['title', 'detail', 'status', 'hydra:title', 'hydra:description']));
        self::assertSame($keep($missing->toArray(false)), $keep($guarded->toArray(false)));
    }

    /** Le témoin : le même administrateur, sur B où le module est allumé, est servi. */
    public function testTheSameCallsAreServedWhereTheModuleIsOn(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $headers['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $client->request('GET', '/api/padel_terrains', $headers);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/padel_plage_horaires', $headers + [
            'json' => ['libelle' => 'creuse', 'joursApplicables' => [3]],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Retirer l'en-tête `X-Etablissement` ne contourne pas la garde : sans établissement actif, le
     * module doit être allumé dans au moins un établissement de l'utilisateur. Témoin : allumé sur B,
     * la même requête sans en-tête n'est plus refusée par la garde.
     */
    public function testDroppingTheEstablishmentHeaderDoesNotBypassTheGuard(): void
    {
        $this->switchModule('padel', false);
        self::ensureKernelShutdown();

        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        unset($headers['headers'][ContexteEtablissement::HEADER]);

        $client->request('GET', '/api/padel_terrains', $headers);
        self::assertResponseStatusCodeSame(404, 'éteint partout, sans en-tête');

        $this->switchModule('padel', true, SocleFixtures::ETAB_B_NOM);
        $client->request('GET', '/api/padel_terrains', $headers);
        self::assertResponseIsSuccessful();
    }

    /** Les permissions ne rallument jamais un module : la garde ne lit que les capacités. */
    public function testAWildcardRoleDoesNotReopenASwitchedOffModule(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $wildcard = $em->getRepository(Permission::class)->findOneBy(['module' => '*', 'action' => '*'])
            ?? (new Permission())->setModule('*')->setAction('*');
        $em->persist($wildcard);
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        self::assertInstanceOf(Role::class, $role);
        $role->addPermission($wildcard);
        $em->flush();
        self::ensureKernelShutdown();

        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', '/api/padel_terrains', $headers);
        self::assertResponseStatusCodeSame(404, 'un rôle *.* ne rallume pas padel sur A');

        $headers['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $client->request('GET', '/api/padel_terrains', $headers);
        self::assertResponseIsSuccessful();
    }

    /**
     * Vigilance de la spec : la garde porte sur les opérations DU module, jamais sur le cœur qui lit
     * ses propres données. La réservation socle née d'une partie de padel reste lisible sur A, alors
     * que la surcouche padel de cette même partie ne l'est plus.
     */
    public function testCoreStillReadsDataCreatedByTheSwitchedOffModule(): void
    {
        $overlay = $this->entite(ReservationPadel::class, []);
        $reservation = (string) $overlay->getReservation()?->getId();
        $overlayId = (string) $overlay->getId();

        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();

        $client->request('GET', '/api/reservations/' . $reservation, $headers);
        self::assertResponseIsSuccessful('la réservation du cœur reste lisible');

        $client->request('GET', '/api/padel_reservations/' . $overlayId, $headers);
        self::assertResponseStatusCodeSame(404, 'la surcouche padel, elle, ne l\'est plus');
    }

    /** @param class-string $class */
    private function rowCount(string $class): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->getRepository($class)->count([]);
    }
}
