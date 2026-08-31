<?php

declare(strict_types=1);

namespace App\Tests\Patinoire;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\DataFixtures\PatinoireFixtures;
use App\Patinoire\Entity\ParcPatins;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\DdlHorsMapping;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API de la verticale Patinoire : schéma recréé et fixtures socle + CRM + patinoire
 * rechargées avant chaque test (patron `App\Tests\Padel\PadelApiTestCase`, code réel lu).
 */
abstract class PatinoireApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        DdlHorsMapping::appliquer($em);

        foreach ([SocleFixtures::class, CrmFixtures::class, PatinoireFixtures::class] as $classe) {
            $fixture = $container->get($classe);
            $fixture->load($em);
        }

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement A */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function agentSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, PatinoireFixtures::AGENT_EMAIL, PatinoireFixtures::AGENT_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function technicienSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, PatinoireFixtures::TECHNICIEN_EMAIL, PatinoireFixtures::TECHNICIEN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} */
    protected function gestionnaireGlaceSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, PatinoireFixtures::GESTIONNAIRE_GLACE_EMAIL, PatinoireFixtures::GESTIONNAIRE_GLACE_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    /** Bénéficiaire de démonstration (payeur de la famille Dupont, `CrmFixtures`). */
    protected function idBeneficiairePayeur(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($client, 'Payeur CRM démo introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire payeur démo introuvable.');

        return (string) $beneficiaire->getId();
    }

    /** Second bénéficiaire de démonstration (enfant de la famille Dupont, `CrmFixtures`). */
    protected function idBeneficiaireEnfant(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(CrmClient::class)->findOneBy(['prenom' => CrmFixtures::ENFANT_PRENOM]);
        self::assertNotNull($client, 'Enfant CRM démo introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire enfant démo introuvable.');

        return (string) $beneficiaire->getId();
    }

    /** Troisième bénéficiaire de démonstration (conjoint de la famille Dupont, `CrmFixtures`). */
    protected function idBeneficiaireConjoint(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(CrmClient::class)->findOneBy(['prenom' => CrmFixtures::CONJOINT_PRENOM]);
        self::assertNotNull($client, 'Conjoint CRM démo introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire conjoint démo introuvable.');

        return (string) $beneficiaire->getId();
    }

    protected function idParcPatins(int $pointure): string
    {
        return (string) $this->entite(ParcPatins::class, ['pointure' => $pointure])->getId();
    }

    /**
     * Extrait l'identifiant d'une relation sérialisée, qu'elle soit une IRI brute (string) ou un objet
     * partiel JSON-LD `{"@id", "@type", "id"}` (comportement observé du normalizer selon le contexte).
     */
    protected function idDeRelation(mixed $relation): string
    {
        if (\is_array($relation)) {
            return (string) ($relation['id'] ?? $relation['@id'] ?? '');
        }

        return (string) $relation;
    }

    /**
     * Retrouve, dans une collection Hydra non filtrée, le membre dont la relation `location` (IRI)
     * pointe vers l'id donné. ⚠ Contournement volontaire : le `SearchFilter` d'API Platform sur une
     * association dont l'identifiant est un UUID (`Symfony\Bridge\Doctrine\Types\UuidType`, colonne
     * BINARY(16)) lie le paramètre de requête comme une chaîne brute (`Doctrine\ORM\Query\
     * ParameterTypeInferer` ne connaît pas `Symfony\Component\Uid\Uuid`), donc la comparaison
     * `location_id = :uuidString` ne correspond jamais en base — filtrage reproduit côté test.
     *
     * @param array<string, mixed> $collection
     *
     * @return array<string, mixed>|null
     */
    protected function membreParLocation(array $collection, string $idLocation): ?array
    {
        foreach ($collection['member'] ?? [] as $item) {
            if (!\is_array($item) || !isset($item['location'])) {
                continue;
            }
            if (str_ends_with($this->idDeRelation($item['location']), $idLocation)) {
                return $item;
            }
        }

        return null;
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
        $entite = $criteres === [] ? $em->getRepository($classe)->findOneBy([]) : $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }
}
