<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Entity\RegleConservation;
use App\Crm\Enum\PrivacyRequestOrigin;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #344 — épuration sélective avec aperçu, durée de conservation, purge à échéance, export.
 *
 * DEUX COLLECTIVITÉS, ET UN TÉMOIN QUI DOIT RESTER INTACT. Chaque test pose, dans le groupe B
 * (« Groupe Second Loisirs »), une fiche aussi inactive que celles qu'on épure dans le groupe A.
 * Si le cloisonnement cède, c'est elle qui part — et le test le dit en la relisant en base.
 */
final class PersonalDataPurgeTest extends CrmApiTestCase
{
    /** Aperçu : les inactifs de A, sans le récent, sans le protégé, sans le témoin de B. */
    public function testPreviewListsOnlyInactiveUnprotectedCustomersOfTheActiveGroup(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ids = $this->seed();

        $apercu = $client->request('POST', '/api/rgpd/purge/preview', $entete + ['json' => ['inactiveMonths' => 36]])->toArray();
        self::assertResponseIsSuccessful();

        $vus = array_column($apercu['candidates'], 'id');
        self::assertContains($ids['ancienA'], $vus, 'la fiche inactive depuis cinq ans doit être proposée');
        self::assertNotContains($ids['recentA'], $vus, 'une fiche venue le mois dernier n\'est pas inactive');
        self::assertNotContains($ids['protegeA'], $vus, 'un solde de porte-monnaie positif protège la fiche');
        self::assertNotContains($ids['temoinB'], $vus, 'CLOISONNEMENT : la fiche de l\'autre collectivité ne doit jamais apparaître');
        self::assertSame(1, $apercu['protected'], 'la fiche protégée est comptée à part, pas passée sous silence');
        self::assertSame($apercu['eligible'], \count($vus));

        // L'aperçu n'écrit rien.
        self::assertSame(StatutClient::Actif, $this->relire($ids['ancienA'])->getStatut());
    }

    /** Application : la sélection ne peut que restreindre ; un identifiant de B est ignoré. */
    public function testApplyAnonymisesSelectionAndIgnoresForeignOrProtectedIds(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ids = $this->seed();

        $reponse = $client->request('POST', '/api/rgpd/purge', $entete + ['json' => [
            'inactiveMonths' => 36,
            'clientIds' => [$ids['ancienA'], $ids['temoinB'], $ids['protegeA'], $ids['recentA']],
            'confirm' => true,
        ]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(['requested' => 4, 'anonymised' => 1, 'ignored' => 3], $reponse);

        $ancien = $this->relire($ids['ancienA']);
        self::assertSame(StatutClient::Anonymise, $ancien->getStatut());
        self::assertNull($ancien->getNom());
        self::assertNull($ancien->getEmail());

        // TÉMOIN : la fiche de l'autre collectivité, désignée explicitement, est intacte.
        $temoin = $this->relire($ids['temoinB']);
        self::assertSame(StatutClient::Actif, $temoin->getStatut(), 'CLOISONNEMENT : une fiche de B a été anonymisée depuis A');
        self::assertSame('Temoin', $temoin->getNom());
        self::assertSame(StatutClient::Actif, $this->relire($ids['protegeA'])->getStatut());
        self::assertSame(StatutClient::Actif, $this->relire($ids['recentA'])->getStatut());

        // Le journal d'audit de l'anonymisation ne recopie pas l'identité effacée.
        $audits = $this->em()->getRepository(\App\Audit\Entity\EntreeAudit::class)->findBy(['cibleType' => Client::class, 'cibleId' => $ids['ancienA'], 'action' => 'modification']);
        self::assertNotEmpty($audits, 'témoin : l\'anonymisation est bien journalisée');
        foreach ($audits as $audit) {
            self::assertStringNotContainsString('Ancien', (string) json_encode([$audit->getValeurAvant(), $audit->getValeurApres()]), 'l\'audit de l\'anonymisation recopie le nom effacé');
        }

        // La trace : une demande réalisée d'origine « purge », signée.
        $demandes = $this->em()->getRepository(DemandeRGPD::class)->findBy(['client' => $ancien]);
        self::assertCount(1, $demandes);
        self::assertSame(PrivacyRequestOrigin::Purge, $demandes[0]->getOrigin());
        self::assertNotNull($demandes[0]->getTraitePar());

        // Et elle ne pollue pas la file des demandes des personnes.
        $file = $client->request('GET', '/api/demande_rgpds?origin=request', $entete)->toArray();
        self::assertSame(0, $file['hydra:totalItems'] ?? $file['totalItems']);
        // Témoin positif du filtre : un filtre muet rendrait aussi 1 ici, et 1 plus haut.
        $traces = $client->request('GET', '/api/demande_rgpds?origin=purge', $entete)->toArray();
        self::assertSame(1, $traces['hydra:totalItems'] ?? $traces['totalItems']);
    }

    /** Sans `confirm: true`, rien ne part. */
    public function testApplyRequiresExplicitConfirmation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ids = $this->seed();

        $client->request('POST', '/api/rgpd/purge', $entete + ['json' => ['inactiveMonths' => 36, 'clientIds' => [$ids['ancienA']]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(StatutClient::Actif, $this->relire($ids['ancienA'])->getStatut());
    }

    /** L'administrateur de B épure B, et seulement B : le symétrique du témoin. */
    public function testOtherGroupAdminSeesOnlyItsOwnCustomers(): void
    {
        [$client, $entete] = $this->agentSurGroupeB();
        $ids = $this->seed();

        $apercu = $client->request('POST', '/api/rgpd/purge/preview', $entete + ['json' => ['inactiveMonths' => 36]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame([$ids['temoinB']], array_column($apercu['candidates'], 'id'));
    }

    /** Un établissement actif d'une collectivité où l'on n'est pas affecté ne vaut rien. */
    public function testForeignActiveEstablishmentHeaderIsRefused(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->seed();
        $entete['headers'][\App\Securite\Service\ContexteEtablissement::HEADER] = $this->idEtablissement(CrmFixtures::ETAB_C_NOM);

        $reponse = $client->request('POST', '/api/rgpd/purge/preview', $entete + ['json' => ['inactiveMonths' => 36]]);
        // Refusé dès l'écouteur d'en-tête (404) ; `CustomerGroupGuard` le refuserait ensuite (403).
        self::assertContains($reponse->getStatusCode(), [403, 404], 'un en-tête désignant une autre collectivité ne doit rien ouvrir');
    }

    /** Un agent sans `crm.rgpd_gerer` n'épure pas. */
    public function testAgentWithoutPrivacyPermissionIsRefused(): void
    {
        [$client, $entete] = $this->agentSurA();
        $this->seed();
        $client->request('POST', '/api/rgpd/purge/preview', $entete + ['json' => ['inactiveMonths' => 36]]);
        self::assertResponseStatusCodeSame(403);
    }

    /** Durée de conservation : posée sur le groupe actif, jamais lue du corps. */
    public function testRetentionRuleIsStampedOnTheActiveGroup(): void
    {
        [$client, $entete] = $this->adminSurA();

        $lu = $client->request('GET', '/api/rgpd/retention-rule', $entete)->toArray();
        self::assertFalse($lu['configured']);

        $pose = $client->request('POST', '/api/rgpd/retention-rule', $entete + ['json' => ['months' => 36]])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(36, $pose['months']);

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $luB = $clientB->request('GET', '/api/rgpd/retention-rule', $enteteB)->toArray();
        self::assertFalse($luB['configured'], 'CLOISONNEMENT : la durée de A ne vaut pas pour B');

        $regles = $this->em()->getRepository(RegleConservation::class)->findAll();
        self::assertCount(1, $regles);
        self::assertSame($this->groupeDe(SocleFixtures::ETAB_A_NOM)->getId()->toRfc4122(), $regles[0]->getGroupe()?->getId()->toRfc4122());
    }

    /** Purge à échéance : la règle de A n'anonymise que A ; la simulation n'écrit rien. */
    public function testRetentionCommandAppliesOnlyToTheRuleGroup(): void
    {
        $this->adminSurA(); // démarre le noyau ; la commande tourne sans client HTTP
        $ids = $this->seed();
        $em = $this->em();
        $regle = (new RegleConservation())->setGroupe($this->groupeDe(SocleFixtures::ETAB_A_NOM))->setCategorieDonnee('identite')->setDureeMois(36);
        $em->persist($regle);
        $em->flush();

        $application = new Application(static::$kernel);
        $commande = new CommandTester($application->find('crm:rgpd:appliquer-conservation'));

        $commande->execute(['--dry-run' => true]);
        $commande->assertCommandIsSuccessful();
        $em->clear();
        self::assertSame(StatutClient::Actif, $this->relire($ids['ancienA'])->getStatut(), 'la simulation ne doit rien anonymiser');

        $commande->execute([]);
        $commande->assertCommandIsSuccessful();
        $em->clear();

        self::assertSame(StatutClient::Anonymise, $this->relire($ids['ancienA'])->getStatut());
        self::assertSame(StatutClient::Actif, $this->relire($ids['temoinB'])->getStatut(), 'CLOISONNEMENT : la règle de A a touché B');
        self::assertSame(StatutClient::Actif, $this->relire($ids['recentA'])->getStatut(), 'compter depuis la création anonymiserait un client venu le mois dernier');
        self::assertSame(StatutClient::Actif, $this->relire($ids['protegeA'])->getStatut());
        $trace = $em->getRepository(DemandeRGPD::class)->findBy(['client' => $this->relire($ids['ancienA'])]);
        self::assertSame(PrivacyRequestOrigin::Retention, $trace[0]->getOrigin());
    }

    /** Export : tout sur la personne, en JSON ; 404 pour une fiche de l'autre collectivité. */
    public function testExportReturnsPersonDataAndHidesForeignCustomers(): void
    {
        [$client, $entete] = $this->adminSurA();
        $ids = $this->seed();

        $reponse = $client->request('GET', '/api/clients/' . $this->idPayeur() . '/personal-data-export', $entete);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', $reponse->getHeaders()['content-disposition'][0]);
        $doc = json_decode($reponse->getContent(), true);
        self::assertSame(CrmFixtures::PAYEUR_EMAIL, $doc['person']['email']);
        self::assertArrayHasKey('consents', $doc);
        self::assertArrayHasKey('purchases', $doc);
        self::assertArrayHasKey('memberships', $doc);

        $client->request('GET', '/api/clients/' . $ids['temoinB'] . '/personal-data-export', $entete);
        self::assertResponseStatusCodeSame(404, 'CLOISONNEMENT : la fiche de B ne s\'exporte pas depuis A');
    }

    /** @return array{ancienA: string, recentA: string, protegeA: string, temoinB: string} */
    private function seed(): array
    {
        $em = $this->em();
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabC = $em->getRepository(Etablissement::class)->findOneBy(['nom' => CrmFixtures::ETAB_C_NOM]);
        $cinqAns = new \DateTimeImmutable('-5 years');

        $ancienA = $this->fiche($etabA, 'Ancien', $cinqAns, null);
        $recentA = $this->fiche($etabA, 'Recent', $cinqAns, new \DateTimeImmutable('-1 month'));
        $protegeA = $this->fiche($etabA, 'Protege', $cinqAns, null);
        $temoinB = $this->fiche($etabC, 'Temoin', $cinqAns, null);

        $pmv = (new PorteMonnaieVirtuel())->setClient($protegeA)->setSolde('12.50')->setStatut(StatutPmv::Actif);
        $em->persist($pmv);
        $em->flush();

        return [
            'ancienA' => (string) $ancienA->getId(),
            'recentA' => (string) $recentA->getId(),
            'protegeA' => (string) $protegeA->getId(),
            'temoinB' => (string) $temoinB->getId(),
        ];
    }

    private function fiche(Etablissement $etab, string $nom, \DateTimeImmutable $creation, ?\DateTimeImmutable $visite): Client
    {
        $c = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($etab->getRegion()?->getGroupe())
            ->setEtablissementCreation($etab)
            ->setNom($nom)
            ->setPrenom('Test')
            ->setEmail(strtolower($nom) . '@example.test')
            ->setDateCreation($creation)
            ->setDateDerniereVisite($visite);
        $this->em()->persist($c);

        return $c;
    }

    private function relire(string $id): Client
    {
        $this->em()->clear();
        $c = $this->em()->getRepository(Client::class)->find($id);
        self::assertInstanceOf(Client::class, $c);

        return $c;
    }

    private function groupeDe(string $etab): Groupe
    {
        $g = $this->entite(Etablissement::class, ['nom' => $etab])->getRegion()?->getGroupe();
        self::assertInstanceOf(Groupe::class, $g);

        return $g;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
