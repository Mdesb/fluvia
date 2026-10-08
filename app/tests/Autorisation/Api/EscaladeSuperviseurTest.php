<?php

declare(strict_types=1);

namespace App\Tests\Autorisation\Api;

use App\Audit\Entity\EntreeAudit;
use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\Autorisation\Enum\StatutEscalade;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Tests\Autorisation\AutorisationApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Flux d'escalade superviseur (`GestionnaireEscalade`, endpoints `/demandes-escalade/{id}/{approuver,
 * rejeter}`) — CA-3, CA-4, CA-9, RG-AUTZ-13 (séparation des tâches), jeton à usage unique,
 * divergence de rejeu.
 *
 * NB : ces scénarios font alterner plusieurs clients de test (caissier ↔ superviseur). L'assertion
 * statique `self::assertResponseStatusCodeSame()` (BrowserKitAssertionsTrait) suit le DERNIER client
 * créé via `createClient()`, pas nécessairement celui sur lequel `->request()` vient d'être appelé —
 * ce fichier vérifie donc systématiquement `$reponse->getStatusCode()` sur l'objet retourné par
 * chaque requête plutôt que l'assertion statique, pour rester correct en contexte multi-client.
 */
final class EscaladeSuperviseurTest extends AutorisationApiTestCase
{
    /** CA-3 — Approbation par un superviseur habilité : statut approuvee, EntreeAudit qui/quand/montant ; rejeu → AUTORISE, Avoir créé, sans recomparaison au plafond. */
    public function testCa3ApprobationPuisRejeuAutorise(): void
    {
        $this->configurerLimite('vente.annuler', '100.00', PerimetreAutorisation::Global, true);

        [$clientCaissier, $enteteCaissier] = $this->connecte('caissier-ca3@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($clientCaissier, $enteteCaissier);
        $venteId = $this->venteValideeMontant($clientCaissier, $enteteCaissier, $session['id'], '250.00');

        $reponse = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(403, $reponse->getStatusCode());
        $jeton = $reponse->toArray(false)['demandeEscalade'];

        $demande = $this->em()->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $jeton]);
        self::assertInstanceOf(DemandeEscalade::class, $demande);
        $demandeId = (string) $demande->getId();

        [$clientSuperviseur, $enteteSuperviseur] = $this->connecte('superviseur-ca3@test.itcotation.com', role: $this->roleSuperviseur());
        $reponseApprobation = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demandeId . '/approuver', $enteteSuperviseur + ['json' => []]);
        self::assertSame(201, $reponseApprobation->getStatusCode());
        $approbation = $reponseApprobation->toArray(false);
        self::assertSame('approuvee', $approbation['statut']);
        self::assertSame('/api/utilisateurs/' . $this->idUtilisateur('superviseur-ca3@test.itcotation.com'), $approbation['superviseur']);

        $entrees = $this->em()->getRepository(EntreeAudit::class)->findBy(['action' => 'escalade.approuvee', 'cibleId' => $demandeId]);
        self::assertNotEmpty($entrees, 'EntreeAudit escalade.approuvee attendue.');
        self::assertSame('superviseur-ca3@test.itcotation.com', $entrees[0]->getValeurApres()['superviseur'] ?? null);
        self::assertSame('250.00', $entrees[0]->getValeurApres()['montant'] ?? null);

        // Rejeu de l'opération d'origine en référençant le jeton approuvé : AUTORISE, Avoir créé.
        $reponseRejeu = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + [
            'json' => ['motif' => 'Erreur de saisie', 'demandeEscalade' => $jeton],
        ]);
        self::assertSame(201, $reponseRejeu->getStatusCode());
        $avoir = $reponseRejeu->toArray(false);
        self::assertSame('annulee', $avoir['statutVente']);
        self::assertSame('250.00', $avoir['montant']);
    }

    /** CA-4 — Rejet avec motif : statut rejetee, rejeu bloqué, nouvelle tentative crée une NOUVELLE DemandeEscalade distincte. */
    public function testCa4RejetBloqueRejeuEtNouvelleTentativeCreeNouvelleDemande(): void
    {
        $this->configurerLimite('vente.annuler', '100.00', PerimetreAutorisation::Global, true);

        [$clientCaissier, $enteteCaissier] = $this->connecte('caissier-ca4@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($clientCaissier, $enteteCaissier);
        $venteId = $this->venteValideeMontant($clientCaissier, $enteteCaissier, $session['id'], '250.00');

        $reponse1 = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(403, $reponse1->getStatusCode());
        $jeton1 = $reponse1->toArray(false)['demandeEscalade'];
        $demande1 = $this->em()->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $jeton1]);
        self::assertInstanceOf(DemandeEscalade::class, $demande1);

        [$clientSuperviseur, $enteteSuperviseur] = $this->connecte('superviseur-ca4@test.itcotation.com', role: $this->roleSuperviseur());
        $reponseRejet = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demande1->getId() . '/rejeter', $enteteSuperviseur + [
            'json' => ['motif' => 'Montant jugé excessif'],
        ]);
        self::assertSame(201, $reponseRejet->getStatusCode());
        self::assertSame('rejetee', $reponseRejet->toArray(false)['statut']);

        // Rejeu avec le jeton rejeté : refusé (n'est pas approuvée).
        $reponseRejeuBloque = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + [
            'json' => ['motif' => 'Erreur de saisie', 'demandeEscalade' => $jeton1],
        ]);
        self::assertSame(403, $reponseRejeuBloque->getStatusCode());

        // Nouvelle tentative (sans jeton) : nouvelle DemandeEscalade distincte.
        $reponse2 = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(403, $reponse2->getStatusCode());
        $jeton2 = $reponse2->toArray(false)['demandeEscalade'];
        self::assertNotSame($jeton1, $jeton2);

        self::assertSame(2, $this->em()->getRepository(DemandeEscalade::class)->count([]));
    }

    /** RG-AUTZ-13 — Un superviseur ne peut pas approuver sa propre demande, même habilité. */
    public function testAutoApprobationRefusee(): void
    {
        // Le demandeur détient lui-même autorisation.approuver (cas explicite RG-AUTZ-13).
        $role = $this->roleCaissierSuperviseur();
        [$client, $entete] = $this->connecte('caissier-approuve-soi@test.itcotation.com', role: $role);
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'caissier-approuve-soi@test.itcotation.com']);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);
        $this->configurerLimitePourUtilisateur('vente.annuler', $utilisateur, '100.00', PerimetreAutorisation::Global, true);

        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '250.00');

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(403, $reponse->getStatusCode());
        $jeton = $reponse->toArray(false)['demandeEscalade'];
        $demande = $this->em()->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $jeton]);
        self::assertInstanceOf(DemandeEscalade::class, $demande);

        $demandeId = (string) $demande->getId();
        $reponseAuto = $client->request('POST', '/api/demandes-escalade/' . $demandeId . '/approuver', $entete + ['json' => []]);
        self::assertSame(403, $reponseAuto->getStatusCode());

        // Re-fetch (l'EntityManager est réinitialisé entre deux requêtes du client de test) plutôt
        // qu'un `refresh()` sur une entité potentiellement détachée.
        $demandeApres = $this->em()->getRepository(DemandeEscalade::class)->find($demandeId);
        self::assertInstanceOf(DemandeEscalade::class, $demandeApres);
        self::assertSame(StatutEscalade::EnAttente, $demandeApres->getStatut());
    }

    /** Jeton d'escalade à usage unique (§0 n°6 plan) : un deuxième rejeu du même jeton approuvé est refusé (409). */
    public function testJetonApprouveUsageUnique(): void
    {
        $this->configurerLimite('vente.annuler', '100.00', PerimetreAutorisation::Global, true);

        [$clientCaissier, $enteteCaissier] = $this->connecte('caissier-jeton@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($clientCaissier, $enteteCaissier);
        $venteId = $this->venteValideeMontant($clientCaissier, $enteteCaissier, $session['id'], '250.00');

        $reponse = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(403, $reponse->getStatusCode());
        $jeton = $reponse->toArray(false)['demandeEscalade'];
        $demande = $this->em()->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $jeton]);
        self::assertInstanceOf(DemandeEscalade::class, $demande);

        [$clientSuperviseur, $enteteSuperviseur] = $this->connecte('superviseur-jeton@test.itcotation.com', role: $this->roleSuperviseur());
        $reponseApprobation = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demande->getId() . '/approuver', $enteteSuperviseur + ['json' => []]);
        self::assertSame(201, $reponseApprobation->getStatusCode());

        // Premier rejeu : AUTORISE.
        $reponsePremierRejeu = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + [
            'json' => ['motif' => 'Erreur de saisie', 'demandeEscalade' => $jeton],
        ]);
        self::assertSame(201, $reponsePremierRejeu->getStatusCode());

        // Deuxième rejeu du MÊME jeton (vente désormais annulée, mais le contrôle du jeton intervient
        // avant l'appel au handler de contre-passation) : refusé 409.
        $reponseDeuxiemeRejeu = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/annuler', $enteteCaissier + [
            'json' => ['motif' => 'Erreur de saisie', 'demandeEscalade' => $jeton],
        ]);
        self::assertSame(409, $reponseDeuxiemeRejeu->getStatusCode());
    }

    /** Rejeu avec un montant différent de la demande approuvée → refusé (§4.6 spec). */
    public function testRejeuMontantDivergentRefuse(): void
    {
        $this->configurerLimite('vente.rembourser', '100.00', PerimetreAutorisation::Global, true);

        [$clientCaissier, $enteteCaissier] = $this->connecte('caissier-divergent@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($clientCaissier, $enteteCaissier);
        $venteId = $this->venteValideeMontant($clientCaissier, $enteteCaissier, $session['id'], '250.00');

        $reponse = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/rembourser', $enteteCaissier + ['json' => ['motif' => 'Divergent', 'montant' => '250.00']]);
        self::assertSame(403, $reponse->getStatusCode());
        $jeton = $reponse->toArray(false)['demandeEscalade'];
        $demande = $this->em()->getRepository(DemandeEscalade::class)->findOneBy(['jeton' => $jeton]);
        self::assertInstanceOf(DemandeEscalade::class, $demande);

        [$clientSuperviseur, $enteteSuperviseur] = $this->connecte('superviseur-divergent@test.itcotation.com', role: $this->roleSuperviseur());
        $reponseApprobation = $clientSuperviseur->request('POST', '/api/demandes-escalade/' . $demande->getId() . '/approuver', $enteteSuperviseur + ['json' => []]);
        self::assertSame(201, $reponseApprobation->getStatusCode());

        // Rejeu avec un montant différent (partiel 50.00 au lieu des 250.00 approuvés) : refusé.
        $reponseDivergente = $clientCaissier->request('POST', '/api/ventes/' . $venteId . '/rembourser', $enteteCaissier + [
            'json' => ['motif' => 'Montant différent', 'montant' => '50.00', 'demandeEscalade' => $jeton],
        ]);
        self::assertSame(403, $reponseDivergente->getStatusCode());
    }

    /** CA-9 — Aucun superviseur habilité : la demande reste en_attente puis expire via la commande planifiée ; l'opération reste bloquée. */
    public function testCa9AucunSuperviseurExpirationParCommande(): void
    {
        $this->configurerLimite('vente.annuler', '100.00', PerimetreAutorisation::PropreEtablissement, true);

        [$client, $entete] = $this->connecte('caissier-ca9@test.itcotation.com');
        $session = $this->ouvrirSessionCaissier($client, $entete);
        $venteId = $this->venteValideeMontant($client, $entete, $session['id'], '250.00');

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/annuler', $entete + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(403, $reponse->getStatusCode());

        $demande = $this->em()->getRepository(DemandeEscalade::class)->findOneBy([]);
        self::assertInstanceOf(DemandeEscalade::class, $demande);
        self::assertSame(StatutEscalade::EnAttente, $demande->getStatut());
        $demandeId = (string) $demande->getId();

        // Force l'expiration dans le passé pour ne pas dépendre du délai réel (15 min par défaut).
        $demande->setDateExpiration(new \DateTimeImmutable('-1 minute'));
        $this->em()->flush();

        $application = new Application(self::$kernel);
        $command = $application->find('autorisation:escalades:expirer');
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        $demandeApres = $this->em()->getRepository(DemandeEscalade::class)->find($demandeId);
        self::assertInstanceOf(DemandeEscalade::class, $demandeApres);
        self::assertSame(StatutEscalade::Expiree, $demandeApres->getStatut());

        $entrees = $this->em()->getRepository(EntreeAudit::class)->findBy(['action' => 'escalade.expiree', 'cibleId' => $demandeId]);
        self::assertNotEmpty($entrees);

        // Aucune dérogation automatique : la vente reste validée (jamais annulée).
        $vente = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray();
        self::assertSame('validee', $vente['statut']);
    }

    private function idUtilisateur(string $email): string
    {
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return (string) $utilisateur->getId();
    }

    private function roleCaissierSuperviseur(): Role
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Caissier Superviseur Test']);
        if ($role instanceof Role) {
            return $role;
        }

        $permVente = $em->getRepository(Permission::class)->findOneBy(['module' => 'vente', 'action' => '*']);
        $permCaisse = $em->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => '*']);
        $permApprouver = $em->getRepository(Permission::class)->findOneBy(['module' => 'autorisation', 'action' => 'approuver']);

        $role = (new Role())->setNom('Caissier Superviseur Test');
        foreach ([$permVente, $permCaisse, $permApprouver] as $permission) {
            if ($permission instanceof Permission) {
                $role->addPermission($permission);
            }
        }
        $em->persist($role);
        $em->flush();

        return $role;
    }

    private function configurerLimite(string $operationCode, string $plafond, PerimetreAutorisation $perimetre, bool $escaladeAuDela): void
    {
        $em = $this->em();
        $operation = $em->getRepository(OperationSensible::class)->find($operationCode);
        self::assertInstanceOf(OperationSensible::class, $operation);
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $limite = (new LimiteAutorisation())
            ->setOperation($operation)
            ->setRole($this->roleCaissier())
            ->setEtablissement($etabA)
            ->setPlafondMontant($plafond)
            ->setPerimetre($perimetre)
            ->setEscaladeAuDela($escaladeAuDela)
            ->setAuteur($admin);
        $em->persist($limite);
        $em->flush();
    }

    private function configurerLimitePourUtilisateur(string $operationCode, Utilisateur $utilisateur, string $plafond, PerimetreAutorisation $perimetre, bool $escaladeAuDela): void
    {
        $em = $this->em();
        $operation = $em->getRepository(OperationSensible::class)->find($operationCode);
        self::assertInstanceOf(OperationSensible::class, $operation);
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $limite = (new LimiteAutorisation())
            ->setOperation($operation)
            ->setUtilisateur($utilisateur)
            ->setEtablissement($etabA)
            ->setPlafondMontant($plafond)
            ->setPerimetre($perimetre)
            ->setEscaladeAuDela($escaladeAuDela)
            ->setAuteur($admin);
        $em->persist($limite);
        $em->flush();
    }
}
