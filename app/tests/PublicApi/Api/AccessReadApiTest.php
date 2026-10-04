<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Tests\PublicApi\PublicApiTestCase;

/**
 * Les ressources `access:read` de `/v1` (spec API partenaire v1, §2, §3.2, critères 3 et 4b).
 *
 * ⚠ LE TEST CROISÉ EST LE CONTRÔLE QUI COMPTE : accord sur A, données sur A et B → A seul ; sans accord
 * → rien ; témoin : accord sur B → B. Vu rouge le 04/10 en retirant le filtre `establishmentsFor` d'un
 * provider, dans une copie jetable : le message nomme la ressource fautive.
 */
final class AccessReadApiTest extends PublicApiTestCase
{
    private const RESOURCES = ['access-rights', 'access-events'];

    public function testUnPartenaireNeVoitQueLesEtablissementsQuiOntConsenti(): void
    {
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-A-0001');
        $this->seed(SocleFixtures::ETAB_B_NOM, 'QR-B-0001');
        $a = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $b = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $onA = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);
        $onB = $this->partner([SocleFixtures::ETAB_B_NOM => ['access:read']]);
        $none = $this->partner([]);
        $eventsOnly = $this->partner([SocleFixtures::ETAB_A_NOM => ['events:subscribe']]);

        foreach (self::RESOURCES as $resource) {
            self::assertSame([$a], $this->establishmentsSeen($onA, $resource), "/v1/$resource : accord sur A, il ne doit voir que A");
            self::assertSame([$b], $this->establishmentsSeen($onB, $resource), "/v1/$resource : témoin, accord sur B, il voit B");
            self::assertSame([], $this->establishmentsSeen($none, $resource), "/v1/$resource : sans accord, rien");
            self::assertSame([], $this->establishmentsSeen($eventsOnly, $resource), "/v1/$resource : `events:subscribe` n’ouvre pas `access:read`");
            self::assertSame(404, $this->call($eventsOnly, $resource, ['establishment' => $a])[0], "/v1/$resource : A sans `access:read` est introuvable");
        }
    }

    /** Un établissement hors périmètre rend 404 — jamais 403, jamais une liste vide qui confirmerait son existence. */
    public function testUnEtablissementHorsPerimetreEstIntrouvable(): void
    {
        $this->seed(SocleFixtures::ETAB_B_NOM, 'QR-B-0001');
        $onA = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);

        foreach (self::RESOURCES as $resource) {
            self::assertSame(404, $this->call($onA, $resource, ['establishment' => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)])[0], $resource);
            self::assertSame(200, $this->call($onA, $resource, ['establishment' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)])[0], "témoin $resource");
        }
    }

    /** Critère 4b : le code réel d'un QR ou d'une carte n'apparaît dans AUCUNE réponse. */
    public function testAucuneReponseNeContientLeCodeDuSupport(): void
    {
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-CODE-REEL-7f3a9c');
        $onA = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);

        foreach (self::RESOURCES as $resource) {
            [$status, $body, $raw] = $this->call($onA, $resource);
            self::assertSame(200, $status);
            self::assertCount(1, $body['data'], "$resource : sinon le test ne prouverait rien");
            self::assertStringNotContainsString('QR-CODE-REEL-7f3a9c', $raw, $resource);
        }
    }

    /** Deux applications ne voient pas la même référence ; une application voit la même partout. */
    public function testLaReferenceDuSupportEstPropreAChaqueApplication(): void
    {
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-A-0001');
        $first = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);
        $second = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);

        $ref = $this->call($first, 'access-rights')[1]['data'][0]['reference'];
        self::assertMatchesRegularExpression('/^sup_[0-9a-f]{64}$/', $ref);
        self::assertSame($ref, $this->call($first, 'access-events')[1]['data'][0]['supportReference'], 'le droit et ses passages se relient');
        self::assertNotSame($ref, $this->call($second, 'access-rights')[1]['data'][0]['reference']);
    }

    public function testLaPaginationParCurseurNeFaitNiDoublonNiTrou(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-A-000'.$i);
        }
        $onA = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);

        foreach (['access-rights' => 'reference', 'access-events' => 'id'] as $resource => $key) {
            $seen = [];
            $cursor = null;
            $pages = 0;
            do {
                [, $body] = $this->call($onA, $resource, array_filter(['limit' => 2, 'cursor' => $cursor]));
                $seen = [...$seen, ...array_column($body['data'], $key)];
                $cursor = $body['nextCursor'];
                ++$pages;
            } while (null !== $cursor && $pages < 10);

            self::assertSame(3, $pages, $resource);
            self::assertCount(5, $seen, "$resource : aucun trou");
            self::assertCount(5, array_unique($seen), "$resource : aucun doublon");
        }
    }

    /** Statut et zones tels que le terminal les appliquerait (D87 : aucune zone = aucune porte, sauf exemptés). */
    public function testStatutEtZonesDesDroits(): void
    {
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-ACTIF');
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-SUSPENDU', statut: StatutProjectionDroit::Devalide);
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-BLOQUE', support: StatutSupport::Bloque);
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-EXPIRE', until: new \DateTimeImmutable('-1 day'));
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-SANS-ZONE', withZone: false);
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-PERSONNEL', withZone: false, type: TypeDroitAcces::Personnel);
        $onA = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);

        $rows = $this->call($onA, 'access-rights')[1]['data'];
        $statuses = array_count_values(array_column($rows, 'status'));
        ksort($statuses);
        self::assertSame(['active' => 3, 'expired' => 1, 'revoked' => 1, 'suspended' => 1], $statuses);

        $doors = array_map(static fn (array $r): string => \count($r['zones']).($r['allZones'] ? '+tout' : ''), $rows);
        sort($doors);
        self::assertSame(['0', '0+tout', '1', '1', '1', '1'], $doors, 'sans zone : aucune porte, sauf le personnel qui ouvre tout');
    }

    /** 90 jours au plus ; `updatedSince` porte sur l'enregistrement ; il n'est pas servi sur les droits. */
    public function testFenetreEtSynchronisationIncrementaleDesPassages(): void
    {
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-RECENT');
        $this->seed(SocleFixtures::ETAB_A_NOM, 'QR-ANCIEN', passedAt: new \DateTimeImmutable('-91 days'));
        $onA = $this->partner([SocleFixtures::ETAB_A_NOM => ['access:read']]);

        self::assertCount(1, $this->call($onA, 'access-events')[1]['data'], 'le passage de plus de 90 jours n’est pas servi');
        $before = (new \DateTimeImmutable('-1 minute'))->format(\DATE_ATOM);
        $after = (new \DateTimeImmutable('+1 minute'))->format(\DATE_ATOM);
        self::assertCount(1, $this->call($onA, 'access-events', ['updatedSince' => $before])[1]['data']);
        self::assertCount(0, $this->call($onA, 'access-events', ['updatedSince' => $after])[1]['data']);
        self::assertSame(400, $this->call($onA, 'access-rights', ['updatedSince' => $before])[0]);
    }

    // ---------------------------------------------------------------- montage

    /**
     * Une application, sa clé, et les accords donnés PAR L'API d'exploitant.
     *
     * @param array<string, list<string>> $grants nom d'établissement => portées
     */
    private function partner(array $grants): string
    {
        $application = $this->createApplication('Partenaire '.bin2hex(random_bytes(3)));
        ['secret' => $secret] = $this->issueKey($application['id']);

        foreach ($grants as $establishment => $scopes) {
            [$client, $headers] = $this->admin($establishment);
            $client->request('POST', '/api/partner-accesses/'.$application['id'].'/grant', $headers + ['json' => ['scopes' => $scopes]]);
            self::assertResponseIsSuccessful();
        }

        return $secret;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{0: int, 1: array<string, mixed>, 2: string}
     */
    private function call(string $secret, string $resource, array $query = []): array
    {
        $response = static::createClient()->request('GET', '/v1/'.$resource, [
            'headers' => ['Authorization' => 'Bearer '.$secret],
            'query' => $query,
        ]);
        $raw = $response->getContent(false);

        return [$response->getStatusCode(), json_decode($raw, true) ?? [], $raw];
    }

    /** @return list<string> */
    private function establishmentsSeen(string $secret, string $resource): array
    {
        [$status, $body] = $this->call($secret, $resource);
        self::assertSame(200, $status, $resource);

        return array_values(array_unique(array_column($body['data'], 'establishment')));
    }

    /** Un support appairé à un droit sur une zone, et un passage validé. */
    private function seed(
        string $establishmentName,
        string $code,
        StatutProjectionDroit $statut = StatutProjectionDroit::Valide,
        StatutSupport $support = StatutSupport::Actif,
        ?\DateTimeImmutable $until = null,
        bool $withZone = true,
        TypeDroitAcces $type = TypeDroitAcces::Abonnement,
        ?\DateTimeImmutable $passedAt = null,
    ): void {
        $em = $this->em();
        $establishment = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $establishmentName]);
        self::assertInstanceOf(Etablissement::class, $establishment);

        $label = 'Bassin '.bin2hex(random_bytes(3));
        $site = (new Espace())->setNom($label)->setEtablissement($establishment)->setType('salle');
        $zone = (new EspaceAcces())->setLibelle('Zone '.$label)->setEspaceSocle($site)->setEtablissement($establishment);
        $right = (new DroitAcces())->setSourceType($type)->setStatutProjection($statut)->setEtablissement($establishment)
            ->setFenetreDebut(new \DateTimeImmutable('-30 days'))->setFenetreFin($until ?? new \DateTimeImmutable('+30 days'));
        if ($withZone) {
            $right->addAuthorisedSpace($zone);
        }
        $card = (new Support())->setIdentifiant($code)->setType(TypeSupport::Qr)->setStatut($support)->setEtablissement($establishment);
        $pairing = (new Appairage())->setSupport($card)->setDroit($right)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($establishment);
        $passage = (new Passage())->setSupport($card)->setDroit($right)->setEspace($zone)->setResultat(ResultatPassage::Valide)
            ->setEtablissement($establishment)->setHorodatage($passedAt ?? new \DateTimeImmutable('-1 hour'));

        foreach ([$site, $zone, $right, $card, $pairing, $passage] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }
}
