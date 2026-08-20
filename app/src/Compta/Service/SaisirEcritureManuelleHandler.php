<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Saisie d'une écriture manuelle libre — OD (US-L4-11, RG-M6-11, §1/§4.1 spec). Résout le journal, les
 * comptes et les taux de TVA référencés dans le corps de requête et vérifie qu'ils appartiennent tous
 * au **même** `businessProfile` que celui déjà résolu/vérifié (couvert par l'établissement actif) par
 * le processor appelant (§0.5 point 4 — IDOR inter-profils, distinct du contrôle inter-établissements
 * fait en amont), puis délègue la construction/scellement à `DirectLedgerEntryBuilder` (aucun second
 * moteur d'écritures).
 */
final class SaisirEcritureManuelleHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PeriodeComptableResolver $periodes,
        private readonly DirectLedgerEntryBuilder $builder,
    ) {
    }

    /** @param array<string, mixed> $corps */
    public function saisir(ProfilExploitant $profil, array $corps): EcritureComptable
    {
        $journal = $this->resoudreJournal($profil, $corps['journal'] ?? null);
        $date = $this->resoudreDate($corps['date'] ?? null);
        $libelle = $this->chaine($corps['label'] ?? null) ?? $this->chaine($corps['libelle'] ?? null) ?? '';

        $lignesBrutes = $corps['lines'] ?? $corps['lignes'] ?? null;
        if (!\is_array($lignesBrutes) || $lignesBrutes === []) {
            throw new UnprocessableEntityHttpException('Une écriture manuelle doit comporter au moins une ligne.');
        }

        $lignes = [];
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($lignesBrutes as $ligneBrute) {
            if (!\is_array($ligneBrute)) {
                throw new UnprocessableEntityHttpException('Ligne d\'écriture invalide.');
            }

            $compte = $this->resoudreCompte($profil, $ligneBrute['account'] ?? null);
            if (!$compte->isActif()) {
                throw new UnprocessableEntityHttpException(sprintf('Le compte %s est inactif : aucune ligne ne peut y être imputée.', $compte->getNumero()));
            }
            $taux = $this->resoudreTauxTva($profil, $ligneBrute['vatRate'] ?? null);

            $debit = $this->centimes($ligneBrute['debit'] ?? null);
            $credit = $this->centimes($ligneBrute['credit'] ?? null);
            if ($debit > 0 && $credit > 0) {
                throw new UnprocessableEntityHttpException('Une ligne ne peut porter à la fois un débit et un crédit.');
            }
            if ($debit === 0 && $credit === 0) {
                throw new UnprocessableEntityHttpException('Une ligne doit porter un débit ou un crédit strictement positif.');
            }

            $contrepartie = \is_array($ligneBrute['counterparty'] ?? null) ? $ligneBrute['counterparty'] : null;
            $counterpartyId = null;
            if ($contrepartie !== null && \is_string($contrepartie['id'] ?? null) && Uuid::isValid($contrepartie['id'])) {
                $counterpartyId = Uuid::fromString($contrepartie['id']);
            }

            $lignes[] = new DirectLedgerEntryLine(
                compte: $compte,
                debitCentimes: $debit,
                creditCentimes: $credit,
                tauxTva: $taux,
                libelle: $this->chaine($ligneBrute['label'] ?? null) ?? $this->chaine($ligneBrute['libelle'] ?? null),
                counterpartyType: $contrepartie !== null ? $this->chaine($contrepartie['type'] ?? null) : null,
                counterpartyId: $counterpartyId,
                counterpartyLabel: $contrepartie !== null ? $this->chaine($contrepartie['label'] ?? null) : null,
            );

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        // CA-2 : rejet 422 explicite ici, avant toute construction/persist d'`EcritureComptable`
        // (aucune écriture partielle n'est persistée — testé par comptage avant/après).
        if ($totalDebit !== $totalCredit) {
            throw new UnprocessableEntityHttpException('Écriture déséquilibrée : la somme des débits doit égaler la somme des crédits.');
        }

        // §0.3 : contrairement au moteur ventes, la saisie manuelle exige une période déjà ouverte
        // (aucune création silencieuse) — le Comptable doit d'abord ouvrir son exercice/mois.
        $periode = $this->periodes->resoudre($profil, $date);
        if ($periode === null) {
            throw new UnprocessableEntityHttpException('Aucune période comptable ouverte ne couvre cette date : ouvrez d\'abord l\'exercice/mois concerné.');
        }

        $ecriture = $this->builder->construire($profil, $journal, $periode, $date, $libelle, $lignes);
        $this->em->flush();

        return $ecriture;
    }

    private function resoudreJournal(ProfilExploitant $profil, mixed $reference): Journal
    {
        $id = $this->idDepuisReference($reference);
        $journal = $id !== null ? $this->em->getRepository(Journal::class)->find(Uuid::fromString($id)) : null;
        if (!$this->appartientAuProfil($journal?->getProfilExploitant(), $profil)) {
            throw new UnprocessableEntityHttpException('Journal introuvable ou n\'appartenant pas au profil exploitant.');
        }

        /** @var Journal $journal */
        return $journal;
    }

    private function resoudreCompte(ProfilExploitant $profil, mixed $reference): CompteComptable
    {
        $id = $this->idDepuisReference($reference);
        $compte = $id !== null ? $this->em->getRepository(CompteComptable::class)->find(Uuid::fromString($id)) : null;
        if (!$this->appartientAuProfil($compte?->getProfilExploitant(), $profil)) {
            throw new UnprocessableEntityHttpException('Compte comptable introuvable ou n\'appartenant pas au profil exploitant.');
        }

        /** @var CompteComptable $compte */
        return $compte;
    }

    private function resoudreTauxTva(ProfilExploitant $profil, mixed $reference): TauxTva
    {
        $id = $this->idDepuisReference($reference);
        $taux = $id !== null ? $this->em->getRepository(TauxTva::class)->find(Uuid::fromString($id)) : null;
        if (!$this->appartientAuProfil($taux?->getProfilExploitant(), $profil)) {
            throw new UnprocessableEntityHttpException('Taux de TVA introuvable ou n\'appartenant pas au profil exploitant.');
        }

        /** @var TauxTva $taux */
        return $taux;
    }

    /** IDOR inter-profils (§0.5 point 4) : un utilisateur autorisé sur SON profil ne peut pas injecter un compte/journal/taux d'un AUTRE profil. */
    private function appartientAuProfil(?ProfilExploitant $candidat, ProfilExploitant $profil): bool
    {
        return $candidat !== null && $candidat->getId()->equals($profil->getId());
    }

    private function resoudreDate(mixed $valeur): \DateTimeImmutable
    {
        if (!\is_string($valeur) || $valeur === '') {
            throw new UnprocessableEntityHttpException('La date de l\'écriture est obligatoire.');
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException('Date d\'écriture invalide.');
        }
    }

    private function centimes(mixed $valeur): int
    {
        if ($valeur === null || $valeur === '') {
            return 0;
        }
        if (!\is_numeric($valeur)) {
            throw new UnprocessableEntityHttpException('Montant invalide.');
        }

        return (int) round(((float) $valeur) * 100);
    }

    private function chaine(mixed $valeur): ?string
    {
        return \is_string($valeur) && $valeur !== '' ? $valeur : null;
    }

    private function idDepuisReference(mixed $reference): ?string
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $id = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($id) ? $id : null;
    }
}
