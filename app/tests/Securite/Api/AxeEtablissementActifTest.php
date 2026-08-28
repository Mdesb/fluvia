<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'AXE DU CLOISONNEMENT : L'ÉTABLISSEMENT ACTIF, ET CE QUI LE REND SÛR.
 *
 * Le 28/08, le tableau de bord de GI-ONE FITNESS — site créé le matin même, sans aucune caisse —
 * annonçait « Sessions de caisse ouvertes : 1 » et nommait le guichet de Piscine A. Les extensions
 * Doctrine filtraient sur le PÉRIMÈTRE du lecteur : une administratrice affectée à trois sites
 * voyait les données des trois, sous le titre d'un seul. Ce n'était pas une fuite — elle y avait
 * droit — mais des chiffres justes au mauvais endroit, que rien ne signale.
 *
 * La bascule vers l'établissement actif déplace la protection : la requête ne ferme plus par
 * elle-même, elle filtre sur un en-tête que le CLIENT choisit. Ce test existe parce que cette
 * bascule est sûre à une seule condition, et qu'une condition qu'on vérifie une fois à la main est
 * une condition qu'on perdra.
 *
 * `ContexteEtablissement::idActif()` ne valide rien : il lit `X-Etablissement` et vérifie la syntaxe
 * de l'UUID. C'est un SÉLECTEUR, pas une preuve (D6). Ce qui refuse, c'est
 * `CalculateurDroits::codesEffectifs()`, qui ne retient que les affectations portant SUR
 * l'établissement actif — sans affectation là-bas, aucun code, donc le voter refuse avant que la
 * requête ne soit construite.
 *
 * Les trois tests couvrent les trois façons dont cet équilibre peut se rompre :
 *   - le filtre cesse de filtrer  → on remélange deux établissements ;
 *   - le voter cesse de refuser   → l'en-tête devient un libre-service ;
 *   - l'absence d'en-tête s'ouvre → on rend tout au lieu de rien.
 */
final class AxeEtablissementActifTest extends VenteApiTestCase
{
    private const LIBELLE_B = 'Guichet Patinoire B';

    /**
     * CE QUE MAXIME A VU À L'ÉCRAN.
     *
     * L'administratrice est affectée à A ET à B : les deux sites lui sont permis. La question n'est
     * pas ce qu'elle a le droit de voir, c'est ce qu'elle REGARDE. L'écran titre ses pages du site
     * actif ; les données doivent le suivre.
     */
    public function testUneListeNeMelangePasDeuxEtablissementsPermis(): void
    {
        $this->creerPointDeVenteSurB();

        [$client, $entete] = $this->adminSurA();
        $libelles = $this->libelles($client, $entete);

        // Le test doit constater une lecture réussie avant de conclure de son contenu : une liste
        // vide « ne contient pas » aussi bien qu'une liste correctement filtrée, et un test qui
        // passe pour cette raison ne mesure plus rien.
        self::assertNotEmpty($libelles, 'aucun point de vente rendu : le test ne prouverait rien');
        self::assertNotContains(self::LIBELLE_B, $libelles, 'le guichet de B apparaît sous le titre de A');
    }

    /**
     * L'EN-TÊTE N'EST PAS UNE PREUVE.
     *
     * Le lecteur n'est affecté qu'à A. Il réclame B. Rien ne l'empêche d'écrire cet en-tête — c'est
     * une chaîne dans une requête HTTP. Ce qui doit l'arrêter est le calcul des droits, et le sens
     * sûr de l'erreur est celui qui restreint.
     */
    public function testUnEnteteHorsPerimetreEstRefuseEtNonServi(): void
    {
        $this->creerPointDeVenteSurB();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $client->request('GET', '/api/point_de_ventes', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idB],
        ]);

        // Le refus importe plus que son code : ce qui ne doit jamais arriver, c'est 200 avec les
        // lignes de B.
        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode());
    }

    /**
     * SANS ÉTABLISSEMENT ACTIF, RIEN — ET NON PAS TOUT.
     *
     * Sans en-tête, `codesEffectifs()` unit les droits de toutes les affectations : le voter laisse
     * donc passer. Si l'extension ne fermait pas, la requête sans en-tête deviendrait le moyen le
     * plus simple de tout lire. Une liste vide se remarque ; une liste inter-établissements a
     * seulement l'air plus longue.
     */
    public function testSansEnteteLaListeEstVideEtNonComplete(): void
    {
        $this->creerPointDeVenteSurB();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $libelles = $this->libelles($client, ['auth_bearer' => $token]);

        self::assertSame([], $libelles);
    }

    /** @param array<string, mixed> $entete @return list<string> */
    private function libelles(object $client, array $entete): array
    {
        $reponse = $client->request('GET', '/api/point_de_ventes', $entete)->toArray();

        return array_map(
            static fn (array $pdv): string => (string) ($pdv['libelle'] ?? ''),
            $reponse['member'] ?? $reponse['hydra:member'] ?? [],
        );
    }

    private function creerPointDeVenteSurB(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $pdv = (new PointDeVente())
            ->setLibelle(self::LIBELLE_B)
            ->setEtablissement($etabB);

        $em->persist($pdv);
        $em->flush();
    }
}
