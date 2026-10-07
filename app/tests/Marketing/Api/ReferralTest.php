<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Caisse\Entity\PointDeVente;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Marketing\Entity\LoyaltyRule;
use App\Marketing\Entity\LoyaltyTier;
use App\Marketing\Entity\ReferralProgram;
use App\Tests\Marketing\MarketingApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * LE PARRAINAGE — ces tests protègent le programme contre lui-même.
 *
 * Un parrainage mal borné devient une usine à faux comptes en quelques jours, et les points sont
 * déjà versés quand on s'en aperçoit. Chaque test ci-dessous ferme une de ces portes.
 */
final class ReferralTest extends MarketingApiTestCase
{
    /**
     * Les fixtures posent un barème, des paliers et un programme de DÉMONSTRATION.
     *
     * Un test qui s'appuie dessus ne mesure pas ce qu'il croit : il mesure la démo, et il tombera le
     * jour où quelqu'un changera un seuil pour faire une capture d'écran. On repart donc d'un
     * établissement sans programme, et chaque test pose exactement ce dont il a besoin.
     *
     * Les contraintes d'unicité rendaient déjà le mélange impossible — c'était leur travail, et
     * c'est ainsi qu'on l'a su.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        foreach ([LoyaltyRule::class, LoyaltyTier::class, ReferralProgram::class] as $classe) {
            foreach ($em->getRepository($classe)->findBy(['establishment' => $this->etablissementA()]) as $demo) {
                $em->remove($demo);
            }
        }
        $em->flush();
    }

    /**
     * **On ne récompense pas une inscription, seulement un achat encaissé.**
     *
     * C'est LA décision du module. Payer à l'inscription revient à payer quiconque sait créer une
     * adresse e-mail.
     */
    public function testLaRecompenseAttendUnAchatReel(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $filleul = $this->nouveauClient('Filleul sans achat');

        $lien = $this->declarer($appelant, $entete, $parrain, $filleul);

        $refus = $appelant->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $entete);
        self::assertSame(422, $refus->getStatusCode(), 'Rien n’a été acheté : rien à récompenser.');

        // Le filleul passe en caisse. Rien d'autre ne change.
        $this->vente($filleul, 'now', '25.00');

        $verse = $appelant->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $entete)
            ->toArray();
        self::assertSame(200, $verse['pointsVerses']);
    }

    /**
     * **Les points du parrainage arrivent dans le solde de fidélité, pas dans un second compteur.**
     *
     * Deux monnaies obligeraient le client à comprendre laquelle sert à quoi.
     */
    public function testLesPointsDuParrainageArriventDansLeSoldeDeFidelite(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->bareme(1);
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $filleul = $this->nouveauClient('Filleul acheteur');

        $avant = $appelant->request('GET', '/api/marketing/fidelite/' . $parrain->getId(), $entete)
            ->toArray()['solde'];

        $lien = $this->declarer($appelant, $entete, $parrain, $filleul);
        $this->vente($filleul, 'now', '25.00');
        $appelant->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $entete);

        $apres = $appelant->request('GET', '/api/marketing/fidelite/' . $parrain->getId(), $entete)
            ->toArray();

        self::assertSame($avant + 200, $apres['solde']);
        self::assertStringContainsString(
            'Parrainage',
            $apres['historique'][0]['motif'] ?? '',
            'Le motif doit être lisible par le client : il demandera d’où viennent ces points.',
        );
    }

    /** **On ne se parraine pas soi-même.** */
    public function testOnNeSeParrainePasSoiMeme(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();

        $code = $appelant->request('GET', '/api/marketing/parrainage/code/' . $parrain->getId(), $entete)
            ->toArray()['code'];

        $reponse = $appelant->request('POST', '/api/marketing/parrainages', $entete + [
            'json' => ['code' => $code, 'refereeRef' => (string) $parrain->getId()],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    /**
     * **On ne parraine pas quelqu'un qui achète déjà.**
     *
     * Ce n'est pas un parrainage, c'est une remise offerte sur une relation qui existait avant.
     */
    public function testUnClientDejaAcheteurNePeutPasEtreFilleul(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $ancien = $this->nouveauClient('Client de longue date');
        $this->vente($ancien, '-2 months', '80.00');

        $code = $appelant->request('GET', '/api/marketing/parrainage/code/' . $parrain->getId(), $entete)
            ->toArray()['code'];

        $reponse = $appelant->request('POST', '/api/marketing/parrainages', $entete + [
            'json' => ['code' => $code, 'refereeRef' => (string) $ancien->getId()],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function fuseaux(): iterable
    {
        // À toute heure, l'un des deux (UTC+14, UTC-11) n'a pas le jour UTC.
        yield 'UTC+14' => ['Pacific/Kiritimati'];
        yield 'UTC-11' => ['Pacific/Pago_Pago'];
    }

    /**
     * **« Déjà client » et « depuis le parrainage » se comptent en jours de l'établissement.**
     *
     * Mesuré le 07/10/2026 : le jour du parrainage était le jour UTC. Un achat de la veille au soir
     * pouvait passer pour un achat du jour et faire parrainer un client acquis ; un achat du matin
     * pouvait passer pour un achat d'avant et refuser un vrai filleul.
     */
    #[DataProvider('fuseaux')]
    public function testLeJourDuParrainageEstCeluiDeLEtablissement(string $fuseau): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->etablissementA()->setFuseauHoraire($fuseau);
        $this->em()->flush();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $local = static fn (string $quand): string => '@' . (new \DateTimeImmutable($quand, new \DateTimeZone($fuseau)))->getTimestamp();

        $ancien = $this->nouveauClient('Client de la veille');
        $this->vente($ancien, $local('yesterday 23:59'), '30.00');
        $code = $appelant->request('GET', '/api/marketing/parrainage/code/' . $parrain->getId(), $entete)->toArray()['code'];
        $refus = $appelant->request('POST', '/api/marketing/parrainages', $entete + [
            'json' => ['code' => $code, 'refereeRef' => (string) $ancien->getId()],
        ]);
        self::assertSame(422, $refus->getStatusCode(), 'Un achat d’hier à 23:59 fait un client déjà acquis.');

        $filleul = $this->nouveauClient('Filleul du matin');
        $this->vente($filleul, $local('today 00:01'), '25.00');
        $lien = $this->declarer($appelant, $entete, $parrain, $filleul);
        $verse = $appelant->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $entete);
        self::assertSame(200, $verse->toArray(false)['pointsVerses'] ?? null, 'L’achat d’aujourd’hui à 00:01 compte pour la récompense.');
    }

    /** **Un filleul ne se parraine qu'une fois — et c'est la base qui le tient, pas une lecture.** */
    public function testUnFilleulNeSeParraineQuUneFois(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $filleul = $this->nouveauClient('Filleul unique');

        $this->declarer($appelant, $entete, $parrain, $filleul);

        $code = $appelant->request('GET', '/api/marketing/parrainage/code/' . $parrain->getId(), $entete)
            ->toArray()['code'];
        $reponse = $appelant->request('POST', '/api/marketing/parrainages', $entete + [
            'json' => ['code' => $code, 'refereeRef' => (string) $filleul->getId()],
        ]);

        self::assertSame(409, $reponse->getStatusCode());
    }

    /** **Une récompense ne se verse pas deux fois** — un double-clic suffirait. */
    public function testUneRecompenseNeSeVersePasDeuxFois(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $filleul = $this->nouveauClient('Filleul payé une fois');

        $lien = $this->declarer($appelant, $entete, $parrain, $filleul);
        $this->vente($filleul, 'now', '25.00');

        $appelant->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $entete);
        self::assertResponseIsSuccessful();

        $reponse = $appelant->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $entete);
        self::assertSame(409, $reponse->getStatusCode());
    }

    /** **Le code est stable** : le redemander ne le change pas, sinon celui qui circule ne vaut plus rien. */
    public function testLeCodeEstStableDUnAppelALAutre(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $parrain = $this->parrainAvecAchat();

        $url = '/api/marketing/parrainage/code/' . $parrain->getId();
        $premier = $appelant->request('GET', $url, $entete)->toArray()['code'];
        $second = $appelant->request('GET', $url, $entete)->toArray()['code'];

        self::assertSame($premier, $second);
        self::assertSame(8, \strlen($premier));
        self::assertDoesNotMatchRegularExpression('/[IO01]/', $premier, 'Un code doit se dicter au téléphone.');
    }

    /** **Le parrainage d'un autre établissement est introuvable, pas interdit.** */
    public function testLeParrainageDUnAutreEtablissementEstIntrouvable(): void
    {
        [$appelant, $entete] = $this->adminSurA();
        $this->programme(200, '10.00');
        $parrain = $this->parrainAvecAchat();
        $filleul = $this->nouveauClient('Filleul du groupe A');
        $lien = $this->declarer($appelant, $entete, $parrain, $filleul);

        [$appelantB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $appelantB->request('POST', '/api/marketing/parrainages/' . $lien . '/recompenser', $enteteB);

        self::assertSame(404, $reponse->getStatusCode());
    }

    // --- outillage --------------------------------------------------------------------------------

    /** @param array<string, mixed> $entete */
    private function declarer(object $appelant, array $entete, Client $parrain, Client $filleul): string
    {
        $code = $appelant->request('GET', '/api/marketing/parrainage/code/' . $parrain->getId(), $entete)
            ->toArray()['code'];

        $lien = $appelant->request('POST', '/api/marketing/parrainages', $entete + [
            'json' => ['code' => $code, 'refereeRef' => (string) $filleul->getId()],
        ])->toArray();

        self::assertResponseIsSuccessful();

        return (string) $lien['id'];
    }

    private function programme(int $points, string $minimum): void
    {
        $em = $this->em();
        $em->persist(
            (new ReferralProgram())
                ->setEstablishment($this->etablissementA())
                ->setRewardPoints($points)
                ->setMinimumPurchase($minimum)
        );
        $em->flush();
    }

    private function bareme(int $pointsParEuro): void
    {
        $em = $this->em();
        $em->persist(
            (new LoyaltyRule())
                ->setEstablishment($this->etablissementA())
                ->setPointsPerEuro($pointsParEuro)
                ->setValidFrom(new \DateTimeImmutable('-1 year'))
        );
        $em->flush();
    }

    /** Le parrain est un client existant : c'est le cas normal, on parraine quand on est déjà venu. */
    private function parrainAvecAchat(): Client
    {
        $client = $this->em()->getRepository(Client::class)
            ->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    private function nouveauClient(string $nom): Client
    {
        $em = $this->em();
        $modele = $this->parrainAvecAchat();

        $client = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($modele->getGroupe())
            ->setEtablissementCreation($this->etablissementA())
            ->setNom($nom)
            ->setPrenom('Test');
        $em->persist($client);
        $em->flush();

        return $client;
    }

    private function vente(Client $client, string $quand, string $montant): void
    {
        $em = $this->em();
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => 'Caisse parrainage'])
            ?? (new PointDeVente())
                ->setLibelle('Caisse parrainage')
                ->setEtablissement($this->etablissementA())
                ->setMoyensAutorises(['especes']);
        $em->persist($pdv);

        $em->persist(
            (new Vente())
                ->setNumero('V-' . bin2hex(random_bytes(6)))
                ->setPointDeVente($pdv)
                ->setEtablissement($this->etablissementA())
                ->setClient($client->getId())
                ->setDate(new \DateTimeImmutable($quand))
                ->setStatut(StatutVente::Validee)
                ->setTotal($montant)
        );
        $em->flush();
    }
}
