<?php

declare(strict_types=1);

namespace App\Tests\Finance;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\ExpenseReportFixtures;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\RattachementEmploye;
use App\Personnel\Enum\TypeContrat;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests d'API du lot FIN-3 (`App\Finance\ExpenseReport`) : réutilise le schéma + les fixtures
 * de `FinanceApiTestCase` (Socle/Offre/Vente/Compta/Stock/Finance), y ajoute `ExpenseReportFixtures`
 * (permissions `finance.expense_report_*`, `OperationSensible`, journal `NDF`, comptes 421/625) et des
 * helpers pour construire des salariés/comptables/superviseurs de test avec fiche `Employe` +
 * `RattachementEmploye` (nécessaire à `EMPLOYE_SOI`, indisponible dans `FinanceApiTestCase`).
 */
abstract class ExpenseReportApiTestCase extends FinanceApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $fixture = $container->get(ExpenseReportFixtures::class);
        \assert($fixture instanceof ExpenseReportFixtures);
        $fixture->load($em);

        self::ensureKernelShutdown();
    }

    /**
     * Crée un salarié complet (`Utilisateur` + `Employe` lié + `RattachementEmploye` actif sur
     * l'établissement donné, rôle « Salarié Note de frais Test ») et authentifie un client dessus.
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id de l'`Employe`
     */
    protected function salarie(string $email, string $etablissementNom = SocleFixtures::ETAB_A_NOM, ?Role $role = null): array
    {
        $em = $this->em();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $etablissementNom]);
        self::assertInstanceOf(Etablissement::class, $etab);

        $roleEffectif = $role ?? $em->getRepository(Role::class)->findOneBy(['nom' => ExpenseReportFixtures::ROLE_SALARIE]);
        self::assertInstanceOf(Role::class, $roleEffectif);

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom($email)->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, 'aaa'));
        $em->persist($utilisateur);
        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($roleEffectif)->setEtablissement($etab));

        $employe = (new Employe())
            ->setUtilisateur($utilisateur)
            ->setNom('Salarie')
            ->setPrenom('Test')
            ->setPoste('Agent')
            ->setTypeContrat(TypeContrat::Cdi)
            ->setDateEntree(new \DateTimeImmutable('2020-01-01'));
        $em->persist($employe);

        $em->persist((new RattachementEmploye())
            ->setEmploye($employe)
            ->setEtablissement($etab)
            ->setDebut(new \DateTimeImmutable('2020-01-01')));

        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, $email, 'aaa');
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => (string) $etab->getId()]];

        return [$client, $entete, (string) $employe->getId()];
    }

    /**
     * Salarié **sans** compte `Utilisateur` (§4.3 spec, point ouvert) : fiche `Employe` autonome,
     * jamais authentifiable — utilisée pour vérifier qu'`EMPLOYE_SOI` refuse par construction.
     */
    protected function employeSansUtilisateur(string $etablissementNom = SocleFixtures::ETAB_A_NOM): Employe
    {
        $em = $this->em();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $etablissementNom]);
        self::assertInstanceOf(Etablissement::class, $etab);

        $employe = (new Employe())
            ->setNom('SansCompte')->setPrenom('Employe')->setPoste('Agent d\'entretien')
            ->setTypeContrat(TypeContrat::Cdi)->setDateEntree(new \DateTimeImmutable('2020-01-01'));
        $em->persist($employe);
        $em->persist((new RattachementEmploye())->setEmploye($employe)->setEtablissement($etab)->setDebut(new \DateTimeImmutable('2020-01-01')));
        $em->flush();

        return $employe;
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function comptableSurA(string $email = 'expense.comptable@itcotation.com'): array
    {
        [$client, $entete] = $this->utilisateurAvecRole($email, ExpenseReportFixtures::ROLE_COMPTABLE);

        return [$client, $entete];
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function superviseurSurA(string $email): array
    {
        return $this->utilisateurAvecRole($email, ExpenseReportFixtures::ROLE_SUPERVISEUR);
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function utilisateurAvecRole(string $email, string $nomRole): array
    {
        $em = $this->em();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => $nomRole]);
        self::assertInstanceOf(Role::class, $role);

        $existant = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        if (!$existant instanceof Utilisateur) {
            $utilisateur = (new Utilisateur())->setEmail($email)->setNom($email)->setActif(true);
            $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, 'aaa'));
            $em->persist($utilisateur);
            $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etab));
            $em->flush();
        }

        $client = static::createClient();
        $token = $this->jeton($client, $email, 'aaa');
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => (string) $etab->getId()]];

        return [$client, $entete];
    }

    /** Configure une `LimiteAutorisation` de test sur `finance.expense_report_approve`, rôle « Salarié ». */
    protected function configurerLimite(string $plafond, PerimetreAutorisation $perimetre, bool $escaladeAuDela, ?Role $role = null): void
    {
        $em = $this->em();
        $operation = $em->getRepository(OperationSensible::class)->find(ExpenseReportFixtures::OPERATION_CODE);
        self::assertInstanceOf(OperationSensible::class, $operation);
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabA);
        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $admin);
        $roleEffectif = $role ?? $em->getRepository(Role::class)->findOneBy(['nom' => ExpenseReportFixtures::ROLE_SALARIE]);
        self::assertInstanceOf(Role::class, $roleEffectif);

        // Upsert plutôt qu'une création systématique : un test qui reconfigure la limite en cours de
        // scénario (ex. CA-6, reopen -> nouveau cycle) ne doit pas laisser deux `LimiteAutorisation`
        // pour le même (opération, rôle, établissement) — `ResolveurLimiteAutorisation` n'a aucune
        // garantie d'ordre entre deux limites à plafond/périmètre identiques (§1 du plan Autorisation).
        $limite = $em->getRepository(LimiteAutorisation::class)->findOneBy([
            'operation' => $operation,
            'role' => $roleEffectif,
            'etablissement' => $etabA,
        ]) ?? new LimiteAutorisation();
        $limite
            ->setOperation($operation)
            ->setRole($roleEffectif)
            ->setEtablissement($etabA)
            ->setPlafondMontant($plafond)
            ->setPerimetre($perimetre)
            ->setEscaladeAuDela($escaladeAuDela)
            ->setAuteur($admin);
        $em->persist($limite);
        $em->flush();
    }

    /**
     * Crée une note de frais brouillon avec une ligne unique (justificatif renseigné par défaut).
     *
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $ligneSurcharge
     *
     * @return array<string, mixed> note (JSON)
     */
    protected function creerNoteAvecLigne(Client $client, array $entete, string $idEmploye, array $ligneSurcharge = [], string $montantTtc = '40.00'): array
    {
        $note = $client->request('POST', '/api/expense_reports', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'employee' => '/api/employes/' . $idEmploye,
            ],
        ])->toArray();

        $ligne = array_merge([
            'expenseReport' => '/api/expense_reports/' . $note['id'],
            'expenseNatureCode' => ExpenseReportFixtures::EXPENSE_NATURE_MAPPED,
            'expenseDate' => '2026-08-01',
            'amountInclTax' => $montantTtc,
            'vatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
            'receiptUrl' => 'https://storage.example.com/receipts/test.pdf',
            'description' => 'Dépense de test',
        ], $ligneSurcharge);

        $client->request('POST', '/api/expense_lines', $entete + ['json' => $ligne]);

        return $client->request('GET', '/api/expense_reports/' . $note['id'], $entete)->toArray();
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
