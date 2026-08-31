<?php

declare(strict_types=1);

namespace App\Tests\Finance;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\DataFixtures\StockFixtures;
use App\Stock\Entity\Fournisseur;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests d'API du lot FIN-2 (`App\Finance\SupplierInvoice`) : schéma recréé + fixtures socle +
 * offre + vente + compta + stock + finance rechargées avant chaque test (même patron que
 * `ComptaApiTestCase`/`StockApiTestCase`).
 */
abstract class FinanceApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, ComptaFixtures::class, StockFixtures::class, FinanceFixtures::class] as $classe) {
            $container->get($classe)->load($em);
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

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement B */
    protected function adminSurB(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]];

        return [$client, $entete, $idB];
    }

    /**
     * Utilisateur affecté **uniquement** sur l'établissement donné, avec les codes `finance.<action>`
     * demandés — sert à reproduire l'IDOR cross-tenant (D8), même patron que
     * `StockApiTestCase::operateurStockSur()`.
     *
     * @param list<string> $actions
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function operateurFinanceSur(string $nomEtab, array $actions): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtab]);
        self::assertNotNull($etab, sprintf('Établissement « %s » introuvable.', $nomEtab));

        $suffixe = bin2hex(random_bytes(4));

        $role = (new Role())->setNom('Opérateur Finance ' . $nomEtab . ' ' . $suffixe);
        foreach ($actions as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'finance', 'action' => $action]);
            self::assertNotNull($permission, sprintf('Permission finance.%s introuvable (FinanceFixtures).', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = 'operateur.finance.' . $suffixe . '@itcotation.com';
        $motDePasse = 'Operateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Opérateur Finance ' . $suffixe)->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $affectation = (new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etab);
        $em->persist($affectation);

        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => (string) $etab->getId()]];

        return [$client, $entete, (string) $etab->getId()];
    }

    protected function idEtablissement(string $nom): string
    {
        return (string) $this->entite(Etablissement::class, ['nom' => $nom])->getId();
    }

    protected function idProfilExploitant(): string
    {
        return (string) $this->entite(ProfilExploitant::class, ['siren' => ComptaFixtures::PROFIL_SIREN])->getId();
    }

    protected function profilExploitant(): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['siren' => ComptaFixtures::PROFIL_SIREN]);
    }

    protected function idFournisseur(string $raisonSociale): string
    {
        return (string) $this->entite(Fournisseur::class, ['raisonSociale' => $raisonSociale])->getId();
    }

    protected function idTauxTva(string $taux): string
    {
        return (string) $this->entite(TauxTva::class, ['profilExploitant' => $this->profilExploitant()->getId(), 'taux' => $taux])->getId();
    }

    protected function idMoyenPaiement(string $code): string
    {
        return (string) $this->entite(MoyenPaiement::class, ['code' => $code])->getId();
    }

    protected function idAdmin(): string
    {
        return (string) $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL])->getId();
    }

    /**
     * Crée une facture fournisseur brouillon, avec une ligne unique (montant/taux/nature paramétrables).
     *
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $ligneSurcharge
     *
     * @return array<string, mixed> facture (JSON)
     */
    protected function creerFactureAvecLigne(
        Client $client,
        array $entete,
        string $idFournisseur,
        array $ligneSurcharge = [],
        string $numero = 'FACT-2026-0001',
    ): array {
        $facture = $client->request('POST', '/api/supplier_invoices', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'supplier' => '/api/stock_fournisseurs/' . $idFournisseur,
                'supplierInvoiceNumber' => $numero,
                'invoiceDate' => '2026-08-01',
                'dueDate' => '2026-09-01',
            ],
        ])->toArray();

        $ligne = array_merge([
            'supplierInvoice' => '/api/supplier_invoices/' . $facture['id'],
            'description' => 'Fournitures diverses',
            'quantity' => '100.000',
            'unitPriceExclTax' => '4.00',
            'vatRate' => '/api/taux_tvas/' . $this->idTauxTva('20.00'),
            'expenseNatureCode' => FinanceFixtures::EXPENSE_NATURE_MAPPEE,
        ], $ligneSurcharge);

        $client->request('POST', '/api/supplier_invoice_lines', $entete + ['json' => $ligne]);

        return $client->request('GET', '/api/supplier_invoices/' . $facture['id'], $entete)->toArray();
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
