<?php

declare(strict_types=1);

namespace App\Tests\Finance;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\PeriodeComptableResolver;
use App\DataFixtures\SocleFixtures;
use App\Finance\DataFixtures\TreasuryFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests d'API du lot FIN-4 (`App\Finance\Treasury`) : réutilise le schéma + les fixtures de
 * `FinanceApiTestCase` (Socle/Offre/Vente/Compta/Stock/Finance), y ajoute `TreasuryFixtures`
 * (permissions `finance.treasury_*`) et des helpers pour créer un compte bancaire, un import, une ligne
 * de relevé et une écriture 512 **scellée** (via `DirectLedgerEntryBuilder`, réutilisé tel quel — aucun
 * second moteur d'écritures propre aux tests).
 */
abstract class TreasuryApiTestCase extends FinanceApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $fixture = $container->get(TreasuryFixtures::class);
        \assert($fixture instanceof TreasuryFixtures);
        $fixture->load($em);

        self::ensureKernelShutdown();
    }

    protected function idCompteBanque512(): string
    {
        return (string) $this->entite(CompteComptable::class, ['profilExploitant' => $this->profilExploitant()->getId(), 'numero' => '512000'])->getId();
    }

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $surcharge
     *
     * @return array<string, mixed> compte bancaire (JSON)
     */
    protected function creerCompteBancaire(
        Client $client,
        array $entete,
        array $surcharge = [],
    ): array {
        $corps = array_merge([
            'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            'ledgerAccount' => '/api/compte_comptables/' . $this->idCompteBanque512(),
            'ibanClear' => 'FR7630006000011234567890189',
            'bic' => 'AGRIFRPP',
            'label' => 'Compte courant principal',
            'openingBalance' => '1000.00',
            'openingBalanceDate' => '2026-08-01',
        ], $surcharge);

        return $client->request('POST', '/api/bank_accounts', $entete + ['json' => $corps])->toArray(false);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed> import (JSON)
     */
    protected function creerImportManuel(Client $client, array $entete, string $idCompte): array
    {
        return $client->request('POST', '/api/bank_statement_imports', $entete + [
            'json' => [
                'bankAccount' => '/api/bank_accounts/' . $idCompte,
                'format' => 'manual',
            ],
        ])->toArray(false);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed> ligne (JSON)
     */
    protected function creerLigneManuelle(
        Client $client,
        array $entete,
        string $idImport,
        string $date,
        string $label,
        string $amount,
        ?string $reference = null,
    ): array {
        return $client->request('POST', '/api/bank_statement_lines', $entete + [
            'json' => [
                'statementImport' => '/api/bank_statement_imports/' . $idImport,
                'operationDate' => $date,
                'label' => $label,
                'amount' => $amount,
                'reference' => $reference,
            ],
        ])->toArray(false);
    }

    /**
     * Écriture 512 **scellée** (débit ou crédit, contrepartie 706100) via `DirectLedgerEntryBuilder`
     * (réutilisé tel quel, aucun second moteur d'écritures propre aux tests) — retourne la `LigneEcriture`
     * portée par le compte 512.
     */
    protected function creerLigneEcritureBancaireScellee(
        string $montantDecimal,
        string $sens,
        \DateTimeImmutable $date,
        string $libelle = 'Encaissement test',
    ): LigneEcriture {
        $em = $this->em();
        $profil = $this->profilExploitant();
        /** @var Journal $journal */
        $journal = $this->entite(Journal::class, ['profilExploitant' => $profil->getId(), 'code' => 'BNQ']);
        /** @var CompteComptable $compte512 */
        $compte512 = $this->entite(CompteComptable::class, ['profilExploitant' => $profil->getId(), 'numero' => '512000']);
        /** @var CompteComptable $compteProduit */
        $compteProduit = $this->entite(CompteComptable::class, ['profilExploitant' => $profil->getId(), 'numero' => '706100']);
        /** @var TauxTva $taux */
        $taux = $this->entite(TauxTva::class, ['profilExploitant' => $profil->getId(), 'taux' => '20.00']);

        $periodeResolver = static::getContainer()->get(PeriodeComptableResolver::class);
        \assert($periodeResolver instanceof PeriodeComptableResolver);
        $periode = $periodeResolver->resoudreOuCreer($profil, $date);

        $montantCentimes = (int) round(((float) $montantDecimal) * 100);

        $lignes = $sens === 'debit'
            ? [
                new DirectLedgerEntryLine($compte512, $montantCentimes, 0, $taux, $libelle),
                new DirectLedgerEntryLine($compteProduit, 0, $montantCentimes, $taux, $libelle),
            ]
            : [
                new DirectLedgerEntryLine($compteProduit, $montantCentimes, 0, $taux, $libelle),
                new DirectLedgerEntryLine($compte512, 0, $montantCentimes, $taux, $libelle),
            ];

        $builder = static::getContainer()->get(DirectLedgerEntryBuilder::class);
        \assert($builder instanceof DirectLedgerEntryBuilder);
        $ecriture = $builder->construire($profil, $journal, $periode, $date, $libelle, $lignes);
        $em->flush();

        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getCompte()?->getId()->equals($compte512->getId())) {
                return $ligne;
            }
        }

        throw new \LogicException('Ligne 512 introuvable sur l\'écriture construite.');
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    protected function entetePatch(array $entete): array
    {
        $entete['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $entete;
    }
}
