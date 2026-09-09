<?php

declare(strict_types=1);

namespace App\Tests\Group;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Group\DataFixtures\GroupFixtures;
use App\Group\Entity\ParticipantGroup;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Tests\SchemaDuHarnais;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API du module transverse `App\Group` : schéma recréé, fixtures socle + offre +
 * compta + vente + CRM + SEPA + réservation + groupes rechargées avant chaque test.
 */
abstract class GroupApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        SchemaDuHarnais::reinitialiser($em);

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, ReservationFixtures::class, GroupFixtures::class,
        ] as $classe) {
            $container->get($classe)->load($em);
        }

        self::ensureKernelShutdown();
    }

    /**
     * Membres d'une collection Hydra, quel que soit le préfixe (`member` récent / `hydra:member`).
     *
     * @param array<string, mixed> $reponse
     *
     * @return list<array<string, mixed>>
     */
    protected static function membres(array $reponse): array
    {
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];

        return \is_array($membres) ? array_values($membres) : [];
    }

    /** @param array<string, mixed> $reponse */
    protected static function total(array $reponse): int
    {
        return (int) ($reponse['totalItems'] ?? $reponse['hydra:totalItems'] ?? 0);
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab A, id A */
    protected function gestionnaireSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, GroupFixtures::GESTIONNAIRE_EMAIL, GroupFixtures::GESTIONNAIRE_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function entetePatch(array $entete): array
    {
        $entete['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $entete;
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    /** L'id réel d'un groupe, lu directement (contourne le cloisonnement) — pour poser des témoins. */
    protected function idGroupe(string $label): string
    {
        return (string) $this->entite(ParticipantGroup::class, ['label' => $label])->getId();
    }

    /** Un créneau de l'établissement A (posé par ReservationFixtures), pour tester l'affectation. */
    protected function unCreneauDeA(): string
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        return (string) $this->entite(Creneau::class, ['etablissement' => $etabA])->getId();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $classe
     * @param array<string, mixed> $criteres
     *
     * @return T
     */
    protected function entite(string $classe, array $criteres): object
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }
}
