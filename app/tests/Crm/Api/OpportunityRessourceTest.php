<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\Entity\Client as CrmClient;
use App\Crm\Entity\Opportunity;
use App\Crm\Enum\OpportunityStage;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LA RESSOURCE `Opportunity` RÉPONDAIT 500 — COLLECTION, ITEM ET ÉCRITURE.
 *
 *     [Semantical Error] near 'groupe)))':
 *     Class App\Crm\Entity\Opportunity has no field or association named groupe
 *
 * `ASSOCIATION_VERS_GROUPE` donne le chemin vers l'entité porteuse de `groupe`, et `null` y signifie
 * « la ressource le porte elle-même ». `Opportunity` et `CommercialActivity` portent `establishment`.
 * Le DQL visait donc un champ inexistant et Doctrine refusait la requête entière.
 *
 * POURQUOI L'ÉCRAN AVAIT L'AIR DE MARCHER. Le tableau des affaires est servi par
 * `/api/crm/pipeline`, un fournisseur dédié qui interroge le dépôt et contourne cette extension.
 * Il s'affichait parfaitement. Mais ses deux seuls gestes — « Qualifier » et « Perdue » — passent
 * par `PATCH /api/opportunities/{id}` et échouaient à chaque clic.
 *
 * ET POURQUOI AUCUNE MESURE NE L'A VU. La route était ATTEIGNABLE depuis un écran : elle ne figurait
 * donc pas dans les chemins non parcourus. Atteignable et exercée sont deux choses différentes —
 * personne n'y était passé, ni un test ni un clic.
 *
 * `PipelineTest` disait déjà, d'un autre défaut du même écran : « un appel qui n'existe pas se lit
 * exactement comme un appel qui existe ». Une entrée de table qui désigne un champ inexistant aussi.
 */
final class OpportunityRessourceTest extends CrmApiTestCase
{
    public function testLaCollectionRepondEtNeMelangePasLesEtablissements(): void
    {
        $this->affaire(SocleFixtures::ETAB_A_NOM, 'Affaire du site A');
        $this->affaire(SocleFixtures::ETAB_B_NOM, 'Affaire du site B');

        [$client, $entete] = $this->adminSurA();
        $reponse = $client->request('GET', '/api/opportunities', $entete);

        self::assertSame(200, $reponse->getStatusCode(), 'la collection des affaires ne répond pas');

        $titres = array_map(
            static fn (array $o): string => (string) ($o['title'] ?? ''),
            $reponse->toArray()['member'] ?? $reponse->toArray()['hydra:member'] ?? [],
        );

        self::assertContains('Affaire du site A', $titres);
        self::assertNotContains('Affaire du site B', $titres, 'une affaire du site voisin apparaît sous le titre de A');
    }

    /**
     * LE GESTE DE L'ÉCRAN, ET NON SEULEMENT SA LECTURE.
     *
     * « Qualifier » et « Perdue » sont les deux seules actions de l'écran Affaires, et toutes deux
     * passent par ce `PATCH`. Un test qui ne lirait que la collection laisserait repasser le défaut
     * exactement là où il faisait mal.
     */
    public function testQualifierUneAffaireAboutit(): void
    {
        $this->affaire(SocleFixtures::ETAB_A_NOM, 'Affaire à qualifier');

        [$client, $entete] = $this->adminSurA();
        $collection = $client->request('GET', '/api/opportunities', $entete)->toArray();
        $membres = $collection['member'] ?? $collection['hydra:member'] ?? [];
        self::assertNotEmpty($membres, 'aucune affaire lisible : la suite du test ne prouverait rien');

        // L'operateur plus ne fusionne PAS les tableaux imbriques : ecrire $entete plus un tableau headers
        // conserve les en-tetes de $entete et jette les nouveaux -- le Content-Type disparaissait et
        // l'API repondait 415. Les en-tetes sont donc composes explicitement.
        $entetePatch = $entete;
        $entetePatch['headers']['Content-Type'] = 'application/merge-patch+json';
        $entetePatch['json'] = ['stage' => OpportunityStage::Qualified->value];

        $client->request('PATCH', '/api/opportunities/' . $membres[0]['id'], $entetePatch);

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * UNE AFFAIRE HORS PÉRIMÈTRE EST INTROUVABLE, JAMAIS INTERDITE (D6).
     *
     * Sans cette vérification, remplacer le filtre par « tout le monde voit tout » passerait les
     * deux tests précédents.
     */
    public function testUneAffaireDUnAutreEtablissementEstIntrouvable(): void
    {
        $this->affaire(SocleFixtures::ETAB_B_NOM, 'Affaire du site B');

        [$client, $entete] = $this->adminSurA();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $depuisB = $client->request('GET', '/api/opportunities', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idB],
        ])->toArray();
        $membres = $depuisB['member'] ?? $depuisB['hydra:member'] ?? [];
        self::assertNotEmpty($membres, 'affaire introuvable depuis son propre site : le test ne prouverait rien');

        $client->request('GET', '/api/opportunities/' . $membres[0]['id'], $entete);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    private function affaire(string $nomEtablissement, string $titre): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        /** @var Etablissement $etablissement */
        $etablissement = $this->entite(Etablissement::class, ['nom' => $nomEtablissement]);
        /** @var CrmClient $payeur */
        $payeur = $this->entite(CrmClient::class, ['email' => \App\Crm\DataFixtures\CrmFixtures::PAYEUR_EMAIL]);

        $em->persist(
            (new Opportunity())
                ->setEstablishment($etablissement)
                ->setCustomer($payeur)
                ->setTitle($titre)
                ->setStage(OpportunityStage::ToQualify)
                ->setEstimatedAmount('100.00')
        );
        $em->flush();
    }
}
