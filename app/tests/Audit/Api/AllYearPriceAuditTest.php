<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\Audit\Entity\EntreeAudit;
use App\Crm\DataFixtures\CrmFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\TypeProduit;
use App\Offre\Entity\TypeTarif;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Utilisateur;
use App\Tests\Marketing\MarketingApiTestCase;

/**
 * L'audit d'un prix « toute l'année » (case de grille SANS saison) porte l'établissement de son auteur.
 *
 * Le chemin `GrilleTarifaire → saison → établissement` n'aboutit pas sans saison : l'entrée sortait
 * avec `etablissement = NULL`, lisible du seul éditeur — le client perdait l'historique de ses prix.
 */
final class AllYearPriceAuditTest extends MarketingApiTestCase
{
    public function testAllYearPriceAuditEntriesCarryTheAuthorEstablishment(): void
    {
        static::getContainer()->get(OffreFixtures::class)->load($this->em());
        self::ensureKernelShutdown();

        $etabA = $this->etablissementA();
        $admin = $this->adminSurA();
        [$client, $entete] = $admin;
        $type = $this->em()->getRepository(TypeProduit::class)->findOneBy(['code' => OffreFixtures::TYPE_ENTREE]);
        $tarif = $this->em()->getRepository(TypeTarif::class)->findOneBy(['nom' => OffreFixtures::TARIF_PLEIN]);
        self::assertNotNull($type);
        self::assertNotNull($tarif);

        $produit = $client->request('POST', '/api/produits', $entete + ['json' => [
            'libelle' => ['fr' => 'Entrée audit toute l\'année'],
            'type' => '/api/type_produits/' . $type->getId(),
            'canaux' => ['guichet'],
            'etablissements' => ['/api/etablissements/' . $etabA->getId()],
        ]])->toArray();
        self::assertResponseStatusCodeSame(201);

        $grille = $client->request('POST', '/api/grille_tarifaires', $entete + ['json' => [
            'produit' => '/api/produits/' . $produit['id'],
            'typeTarif' => '/api/type_tarifs/' . $tarif->getId(),
            'prix' => '10.00',
        ]])->toArray();
        self::assertResponseStatusCodeSame(201);

        $client->request('PATCH', '/api/grille_tarifaires/' . $grille['id'], [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['prix' => '12.00'],
        ]);
        self::assertResponseIsSuccessful();

        $entrees = $this->em()->getRepository(EntreeAudit::class)->findBy([
            'cibleType' => GrilleTarifaire::class,
            'cibleId' => (string) $grille['id'],
        ]);
        self::assertGreaterThanOrEqual(2, \count($entrees), 'La création et la modification de la case doivent être auditées.');
        foreach ($entrees as $entree) {
            self::assertEquals($etabA->getId(), $entree->getEtablissement(), $entree->getAction() . ' : l’entrée doit porter l’établissement de l’auteur.');
        }

        $this->accorderALAgentB('securite', 'lire');
        self::assertFalse($this->lit($this->agentSurGroupeB(), (string) $grille['id']), 'Un agent d’un autre groupe lit l’audit d’un prix de A.');
        // Témoin : sans lui, l'absence chez B pourrait tenir à une route qui ne rend rien à personne.
        self::assertTrue($this->lit($this->adminSurA(), (string) $grille['id']), 'Témoin : l’administratrice de A doit lire l’audit de son prix.');
    }

    /** @param array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} $session */
    private function lit(array $session, string $temoin): bool
    {
        [$client, $entete] = $session;
        $reponse = $client->request('GET', '/api/entree_audits?itemsPerPage=1000', $entete);
        self::assertLessThan(400, $reponse->getStatusCode(), 'La collection de l’audit doit répondre.');

        return str_contains($reponse->getContent(false), $temoin);
    }

    private function accorderALAgentB(string $module, string $action): void
    {
        $em = $this->em();
        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action])
            ?? (new Permission())->setModule($module)->setAction($action);
        $em->persist($permission);
        $agent = $em->getRepository(Utilisateur::class)->findOneBy(['email' => CrmFixtures::AGENT_B_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $agent);
        foreach ($em->getRepository(Affectation::class)->findBy(['utilisateur' => $agent]) as $affectation) {
            $affectation->getRole()?->addPermission($permission);
        }
        $em->flush();
    }
}
