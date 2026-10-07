<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\Audit\Doctrine\AuditWriteSubscriber;
use App\Audit\Entity\EntreeAudit;
use App\Audit\Service\AuditEstablishmentResolver;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Utilisateur;
use App\Tests\Marketing\MarketingApiTestCase;

/**
 * LE JOURNAL D'AUDIT ENTRE DEUX CLIENTS (deux groupes) — relevé le 01/10/2026.
 *
 * Une entrée d'audit sans établissement se lisait « globale ». Or l'audit n'en posait que pour les
 * entités exposant `getEtablissement()` : les fiches clients, les paiements, les connexions… étaient
 * écrits sans, et lisibles de TOUS les clients munis de `securite.lire`. Vu rouge avant le correctif :
 * l'agent du groupe B lisait l'instantané d'un client du groupe A (nom, e-mail).
 */
final class AuditTenantIsolationTest extends MarketingApiTestCase
{
    public function testUneFicheClientNeSeLitPasDansLAuditDUnAutreClient(): void
    {
        $em = $this->em();
        $etabA = $this->etablissementA();
        $client = (new Client())
            ->setType(TypeClient::cases()[0])
            ->setStatut(StatutClient::cases()[0])
            ->setNom('Témoin-audit-' . bin2hex(random_bytes(4)))
            ->setEmail('temoin.audit@example.test')
            ->setEtablissementCreation($etabA)
            ->setGroupe($etabA->getRegion()?->getGroupe());
        $em->persist($client);
        $famille = (new Famille())->setPayeurPrincipal($client)->setGroupe($client->getGroupe());
        $em->persist($famille);
        $em->flush();

        $entrees = $em->getRepository(EntreeAudit::class)->findBy(['cibleId' => [(string) $client->getId(), (string) $famille->getId()]]);
        self::assertCount(2, $entrees, 'La création du client et de la famille doit être auditée.');
        foreach ($entrees as $entree) {
            self::assertEquals($etabA->getId(), $entree->getEtablissement(), $entree->getCibleType() . ' doit être rattaché à l’établissement de création du client.');
        }

        $this->accorderALAgentB('securite', 'lire');
        self::assertFalse($this->lit($this->agentSurGroupeB(), (string) $client->getId()), 'L’agent du groupe B lit la fiche d’un client du groupe A dans l’audit.');
        // Témoin : sans lui, l'absence chez B pourrait tenir à une route qui ne rend rien à personne.
        self::assertTrue($this->lit($this->adminSurA(), (string) $client->getId()), 'Témoin : l’administratrice de A doit lire l’audit de son client.');
    }

    public function testUneEntreeSansEtablissementNEstLueQueParLEditeur(): void
    {
        $em = $this->em();
        $temoin = 'connexion.temoin-' . bin2hex(random_bytes(4));
        $em->persist((new EntreeAudit())->setAuteur('agent.a@example.test')->setAction($temoin)->setCibleType(Utilisateur::class));
        $em->flush();

        // L'éditeur est désigné par le déploiement : ici « Piscine A », comme dans les tests du module
        // Subscription. Remis en l'état ensuite, pour ne pas désigner un éditeur aux tests suivants.
        $avant = [$_ENV['EDITOR_TENANT_ID'] ?? null, $_SERVER['EDITOR_TENANT_ID'] ?? null];
        $_ENV['EDITOR_TENANT_ID'] = $_SERVER['EDITOR_TENANT_ID'] = (string) $this->etablissementA()->getId();
        try {
            $this->accorderALAgentB('securite', 'lire');
            self::assertFalse($this->lit($this->agentSurGroupeB(), $temoin), 'Une entrée sans établissement se lit depuis un client qui n’est pas l’éditeur.');
            // Témoin : le journal de la plateforme reste lisible de l'éditeur, sinon le correctif
            // l'aurait simplement vidé.
            self::assertTrue($this->lit($this->adminSurA(), $temoin), 'Témoin : l’éditeur doit lire les entrées sans établissement.');
        } finally {
            [$env, $server] = $avant;
            if ($env === null) { unset($_ENV['EDITOR_TENANT_ID']); } else { $_ENV['EDITOR_TENANT_ID'] = $env; }
            if ($server === null) { unset($_SERVER['EDITOR_TENANT_ID']); } else { $_SERVER['EDITOR_TENANT_ID'] = $server; }
        }
    }

    /**
     * Une entrée sans établissement propre prend l'établissement ACTIF de son auteur — jamais un
     * établissement qu'il n'atteint pas. La connexion écrit l'audit AVANT que `EstablishmentHeaderListener`
     * ait validé l'en-tête : un agent du groupe B qui se connecte avec l'en-tête de A ne doit rien écrire
     * dans le journal de A.
     */
    public function testUnEnTeteDEtablissementEtrangerNeRattacheRienAuJournalDeCetEtablissement(): void
    {
        $etabA = (string) $this->etablissementA()->getId();
        $connecter = function (string $email, string $motDePasse) use ($etabA): void {
            static::createClient()->request('POST', '/auth', [
                'json' => ['email' => $email, 'motDePasse' => $motDePasse],
                'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $etabA],
            ]);
            self::ensureKernelShutdown();
        };
        $connecter(CrmFixtures::AGENT_B_EMAIL, CrmFixtures::AGENT_B_MDP);
        $connecter(\App\DataFixtures\SocleFixtures::ADMIN_EMAIL, \App\DataFixtures\SocleFixtures::ADMIN_MDP);

        $parAuteur = function (string $email): array {
            $parEtab = ['avec A' => 0, 'autre' => 0];
            foreach ($this->em()->getRepository(EntreeAudit::class)->findBy(['auteur' => $email]) as $entree) {
                $parEtab[(string) $entree->getEtablissement() === $this->etablissementA()->getId()->toRfc4122() ? 'avec A' : 'autre']++;
            }

            return $parEtab;
        };
        $agentB = $parAuteur(CrmFixtures::AGENT_B_EMAIL);
        $adminA = $parAuteur(\App\DataFixtures\SocleFixtures::ADMIN_EMAIL);

        // Témoins : la connexion écrit bien dans l'audit, et le repli sur l'établissement actif fonctionne
        // pour qui l'atteint — sans eux, « rien chez A » pourrait tenir à une connexion qui n'écrit rien.
        self::assertGreaterThan(0, array_sum($agentB), 'Témoin : la connexion de l’agent B doit être auditée.');
        self::assertGreaterThan(0, $adminA['avec A'], 'Témoin : l’administratrice de A connectée sur A y rattache son entrée.');
        self::assertSame(0, $agentB['avec A'], 'L’agent du groupe B a écrit dans le journal de A en posant son en-tête.');
    }

    /**
     * Chaque classe auditée a une décision : accesseur direct, chemin, référence libre, ou « sans
     * établissement » assumé. Une classe ajoutée à l'audit sans décision redeviendrait une fuite, sans
     * erreur ni test rouge — c'est exactement ainsi que 32 classes ont fui.
     */
    public function testChaqueClasseAuditeeADecideDeSonEtablissement(): void
    {
        $surveillees = (new \ReflectionClassConstant(AuditWriteSubscriber::class, 'CLASSES_SURVEILLEES'))->getValue();
        $sansDecision = [];
        foreach ($surveillees as $classe) {
            $direct = method_exists($classe, 'getEtablissement') || method_exists($classe, 'getEstablishment')
                || $classe === \App\Organisation\Entity\Etablissement::class;
            $decide = isset(AuditEstablishmentResolver::PATHS[$classe]) || isset(AuditEstablishmentResolver::FREE_REFERENCES[$classe])
                || \in_array($classe, AuditEstablishmentResolver::WITHOUT_ESTABLISHMENT, true);
            if (!$direct && !$decide) {
                $sansDecision[] = $classe;
            }
        }
        self::assertSame([], $sansDecision, 'Classes auditées sans décision d’établissement (AuditEstablishmentResolver).');

        // Les chemins nomment des getters qui existent : une faute de frappe rendrait `null`, donc
        // une entrée lisible de l'éditeur seul — sûr, mais un historique perdu pour le client.
        foreach (AuditEstablishmentResolver::PATHS as $classe => $chemins) {
            foreach ($chemins as $chemin) {
                self::assertTrue(method_exists($classe, $chemin[0]), sprintf('%s::%s() n’existe pas.', $classe, $chemin[0]));
            }
        }
        foreach (AuditEstablishmentResolver::FREE_REFERENCES as $classe => [$getter, $cible, $chemin]) {
            self::assertTrue(method_exists($classe, $getter), sprintf('%s::%s() n’existe pas.', $classe, $getter));
            self::assertTrue(method_exists($cible, $chemin[0]), sprintf('%s::%s() n’existe pas.', $cible, $chemin[0]));
        }
    }

    /** @param array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} $session */
    private function lit(array $session, string $temoin): bool
    {
        [$client, $entete] = $session;
        $reponse = $client->request('GET', '/api/entree_audits?itemsPerPage=1000', $entete);
        // Un refus rendrait l'absence muette : on mesurerait la permission, pas le cloisonnement.
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
        $affectations = $em->getRepository(Affectation::class)->findBy(['utilisateur' => $agent]);
        self::assertNotEmpty($affectations);
        foreach ($affectations as $affectation) {
            $affectation->getRole()?->addPermission($permission);
        }
        $em->flush();
    }
}
