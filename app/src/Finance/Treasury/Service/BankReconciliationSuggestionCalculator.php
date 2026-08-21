<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Dto\ReconciliationCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Suggestion heuristique de rapprochement (§0.7 du plan, RG-TRE-03) — requête en lecture seule, aucune
 * écriture. **Jamais appliquée automatiquement** (spec §2 « Exclu »).
 *
 * Table de correspondance des signes (§0.7, §7 point 8 du plan — source d'erreur classique documentée
 * explicitement) : `amount > 0` (crédit relevé, entrée d'argent) -> `debitCentimes` (le 512 augmente,
 * convention débit = entrée d'actif bancaire) ; `amount < 0` -> `creditCentimes`.
 *
 * **Montant exact, tolérance nulle** (RG-TRE-03 littéral) : 500,01 € ne matche jamais 500,00 €.
 */
final class BankReconciliationSuggestionCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<ReconciliationCandidate> */
    public function candidats(BankStatementLine $ligne): array
    {
        $compteBancaire = $ligne->getStatementImport()?->getBankAccount();
        $compteComptable = $compteBancaire?->getLedgerAccount();
        if ($compteComptable === null) {
            // Compte bancaire non relié à un compte comptable : aucune suggestion possible (§0.7).
            return [];
        }

        $montantCentimes = $this->centimes($ligne->getAmount());
        if ($montantCentimes === 0) {
            return [];
        }

        $etablissement = $compteBancaire?->getEstablishment();
        $fenetreJours = $etablissement !== null
            ? $this->matchingWindowDays($etablissement->getId())
            : TreasurySettings::DEFAULT_MATCHING_WINDOW_DAYS;

        $dateOperation = $ligne->getOperationDate();
        \assert($dateOperation instanceof \DateTimeInterface);
        $dateDebut = (new \DateTimeImmutable($dateOperation->format('Y-m-d')))->modify(sprintf('-%d days', $fenetreJours));
        $dateFin = (new \DateTimeImmutable($dateOperation->format('Y-m-d')))->modify(sprintf('+%d days', $fenetreJours));

        $qb = $this->em->createQueryBuilder()
            ->select('l', 'e')
            ->from(LigneEcriture::class, 'l')
            ->innerJoin('l.ecriture', 'e')
            ->andWhere('l.compte = :compte')
            ->andWhere('e.dateEcriture BETWEEN :debut AND :fin')
            ->andWhere('e.empreinte != :vide')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . LettrageEcriture::class . ' lt WHERE lt.ligne = l)')
            ->setParameter('compte', $compteComptable->getId(), 'uuid')
            ->setParameter('debut', $dateDebut, 'date_immutable')
            ->setParameter('fin', $dateFin, 'date_immutable')
            ->setParameter('vide', '');

        if ($montantCentimes > 0) {
            $qb->andWhere('l.debitCentimes = :montant')->setParameter('montant', $montantCentimes);
        } else {
            $qb->andWhere('l.creditCentimes = :montant')->setParameter('montant', -$montantCentimes);
        }

        /** @var list<LigneEcriture> $lignesCandidates */
        $lignesCandidates = $qb->getQuery()->getResult();

        $reference = trim(($ligne->getReference() ?? '') . ' ' . $ligne->getLabel());

        $candidats = [];
        foreach ($lignesCandidates as $ligneEcriture) {
            $libelle = $ligneEcriture->getLibelle() ?? $ligneEcriture->getEcriture()?->getLibelle() ?? '';
            $score = 0.0;
            if ($reference !== '' && $libelle !== '') {
                similar_text(mb_strtolower($reference), mb_strtolower($libelle), $score);
            }

            $candidats[] = new ReconciliationCandidate(
                (string) $ligneEcriture->getId(),
                (string) $ligneEcriture->getEcriture()?->getId(),
                (string) $ligneEcriture->getEcriture()?->getDateEcriture()->format('Y-m-d'),
                $montantCentimes > 0 ? $ligneEcriture->getDebitCentimes() : $ligneEcriture->getCreditCentimes(),
                $libelle,
                $score,
            );
        }

        // Corrélation textuelle utilisée pour le tri seulement (RG-TRE-03), jamais comme filtre d'exclusion.
        usort($candidats, static fn (ReconciliationCandidate $a, ReconciliationCandidate $b): int => $b->textScore <=> $a->textScore);

        return $candidats;
    }

    private function matchingWindowDays(Uuid $etablissementId): int
    {
        $settings = $this->em->getRepository(TreasurySettings::class)->findOneBy(['establishment' => $etablissementId]);

        return $settings?->getMatchingWindowDays() ?? TreasurySettings::DEFAULT_MATCHING_WINDOW_DAYS;
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
