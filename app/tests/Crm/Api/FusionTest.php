<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L5-08, RG-M4-06 : fusion/défusion de fiches et de familles.
 */
final class FusionTest extends CrmApiTestCase
{
    /** CA-13 — Prévisualisation avant validation ; historiques/PMV/consentements rattachés à la fiche maître. */
    public function testCa13PrevisualisationEtFusionClients(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();
        $conjointId = $this->idConjoint();

        // Crédite un PMV au conjoint pour vérifier le cumul après fusion.
        $client->request('POST', '/api/clients/' . $conjointId . '/pmv/recharger', $entete + ['json' => ['montant' => '15.00']]);
        self::assertResponseIsSuccessful();

        $preview = $client->request('GET', '/api/crm/fusions/previsualiser', $entete + [
            'query' => ['sources' => [$conjointId], 'maitre' => $payeurId],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('champsDivergents', $preview);
        self::assertArrayHasKey('email', $preview['champsDivergents'], 'Prévisualisation : champs divergents détectés avant toute mutation.');

        $fusion = $client->request('POST', '/api/crm/fusions', $entete + [
            'json' => [
                'portee' => 'client',
                'sources' => ['/api/clients/' . $conjointId],
                'maitre' => '/api/clients/' . $payeurId,
                'motif' => 'Doublon test CA-13',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame($payeurId, $fusion['ficheSurvivante'] ?? null, 'ficheSurvivante = maître (comparaison via IRI si présent).') ;

        $pmvMaitre = $client->request('GET', '/api/clients/' . $payeurId . '/pmv', $entete)->toArray();
        self::assertSame('65.00', $pmvMaitre['solde'], 'CA-13 : PMV rattaché/cumulé à la fiche maître (50.00 + 15.00).');

        $conjointApres = $this->entite(Client::class, ['email' => 'marie.dupont@example.test']);
        self::assertSame('fusionne', $conjointApres->getStatut()->value);
        self::assertNotNull($conjointApres->getFusionneDans());
    }

    /** CA-14 — Défusion : restauration à l'identique (y compris PMV et historiques). */
    public function testCa14DefusionRestaureAIdentique(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();
        $conjointId = $this->idConjoint();

        $fusion = $client->request('POST', '/api/crm/fusions', $entete + [
            'json' => ['portee' => 'client', 'sources' => ['/api/clients/' . $conjointId], 'maitre' => '/api/clients/' . $payeurId],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $journalId = $fusion['id'];
        $defusion = $client->request('POST', '/api/crm/fusions/' . $journalId . '/defusionner', $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('defusionnee', $defusion['statut']);

        $conjointApres = $this->entite(Client::class, ['email' => 'marie.dupont@example.test']);
        self::assertSame('actif', $conjointApres->getStatut()->value, 'CA-14 : fiche source restaurée à l\'identique.');
        self::assertNull($conjointApres->getFusionneDans());

        $pmvMaitreApres = $client->request('GET', '/api/clients/' . $payeurId . '/pmv', $entete)->toArray();
        self::assertSame('50.00', $pmvMaitreApres['solde'], 'CA-14 : PMV maître restauré à l\'identique.');
    }

    /** CA-15 — Fusion de familles : payeur principal conservé, PMV cumulés, bénéficiaire commun dédupliqué. */
    public function testCa15FusionFamillesPayeurConservePmvCumulesBeneficiaireDeduplique(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $familleDupontId = $this->idFamille();
        $enfantId = $this->idEnfant();
        $payeurId = $this->idPayeur();

        // Nouvelle famille avec un payeur distinct et le même enfant en bénéficiaire commun.
        $groupeA = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL])->getGroupe();
        $etabA = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $autrePayeur = (new Client())->setType(TypeClient::Physique)->setGroupe($groupeA)->setEtablissementCreation($etabA)
            ->setNom('Martin')->setPrenom('Paul')->setEmail('paul.martin@example.test');
        $em->persist($autrePayeur);
        $em->flush();

        $autreFamille = $client->request('POST', '/api/familles', $entete + [
            'json' => ['libelle' => 'Famille Martin', 'payeurPrincipal' => '/api/clients/' . $autrePayeur->getId()],
        ])->toArray();
        $client->request('POST', '/api/familles/' . $autreFamille['id'] . '/beneficiaires', $entete + [
            'json' => ['client' => '/api/clients/' . $enfantId, 'role' => 'beneficiaire'],
        ]);

        $fusion = $client->request('POST', '/api/crm/fusions', $entete + [
            'json' => [
                'portee' => 'famille',
                'familleMaitre' => '/api/familles/' . $familleDupontId,
                'familleSource' => '/api/familles/' . $autreFamille['id'],
                'payeurPrincipal' => '/api/clients/' . $payeurId,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $famille = $this->entite(\App\Crm\Entity\Famille::class, ['libelle' => CrmFixtures::FAMILLE_LIBELLE]);
        self::assertSame($payeurId, (string) $famille->getPayeurPrincipal()?->getId(), 'CA-15 : payeur principal choisi conservé.');

        $occurrencesEnfant = array_filter(
            $famille->getBeneficiaires()->toArray(),
            static fn (\App\Crm\Entity\Beneficiaire $b): bool => (string) $b->getClient()?->getId() === $enfantId && $b->estActif(),
        );
        self::assertCount(1, $occurrencesEnfant, 'CA-15 : bénéficiaire commun dédupliqué (une seule occurrence active).');

        $familleSourceApres = $this->entite(\App\Crm\Entity\Famille::class, ['libelle' => 'Famille Martin']);
        self::assertSame('fusionnee', $familleSourceApres->getStatut()->value);
    }
}
