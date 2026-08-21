<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Service\LettrageHandler;
use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Rapprochement bancaire (§0.6 du plan — décision d'architecture centrale de ce lot, ⚠ **validation A en
 * cours**, à confirmer avant merge avec le propriétaire de `App\Compta`).
 *
 * **Treasury ne recomptabilise jamais** : aucune `EcritureComptable` nouvelle n'est créée ici. La seule
 * écriture en base du noyau Compta est un **lettrage** (`compta_lettrage_ecriture`) de lignes déjà
 * scellées, posé via les méthodes publiques existantes de `LettrageHandler` — inchangé.
 *
 * Tension mécanique documentée explicitement (§0.6/§7 point 1 du plan) : `LettrageHandler::
 * lettrerGroupe()` exige **au moins 2 lignes** et un équilibre débit/crédit strict entre elles — un
 * mécanisme conçu pour FIN-2/FIN-3 (créance/dette face à son règlement), pas pour le cas courant de la
 * Trésorerie (**une seule** ligne 512 déjà scellée face à **un fait bancaire externe non comptable**,
 * qui n'est jamais lui-même une `LigneEcriture`). Avec une seule ligne réelle et aucune seconde ligne à
 * lui opposer, `lettrerGroupe()` échoue mécaniquement par construction (`count < 2`).
 *
 * Résolution retenue :
 * - **Cas courant (1 seule ligne candidate confirmée)** — `LettrageHandler::lettrer()` (méthode simple,
 *   existante, inchangée — accepte une seule ligne, ne vérifie aucun équilibre) **puis**
 *   `LettrageEcriture::setReconciliationCode()` posé explicitement (setter public existant, aucune
 *   modification de `App\Compta`). **Garde explicite ajoutée par ce lot** (absente de `lettrer()`, qui
 *   ne la porte pas) : refus 409 si une `LettrageEcriture` existe déjà pour cette `LigneEcriture` — même
 *   contrôle que celui que `lettrerGroupe()` fait en interne, reproduit ici.
 * - **Cas rare (2+ lignes 512 confirmées ensemble, Σdébit === Σcrédit, ex. remise groupée)** —
 *   `lettrerGroupe()` appelé **littéralement**, tel que le décrit RG-TRE-04.
 */
final class BankReconciliationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LettrageHandler $lettrageHandler,
        private readonly EventBus $eventBus,
    ) {
    }

    /** @param list<LigneEcriture> $ledgerLines */
    public function confirmer(BankStatementLine $ligne, array $ledgerLines, Utilisateur $auteur): BankStatementLine
    {
        if ($ligne->getStatus() === BankStatementLineStatus::Reconciled) {
            throw new ConflictHttpException('Cette ligne de relevé est déjà rapprochée.');
        }
        if ($ledgerLines === []) {
            throw new UnprocessableEntityHttpException('Aucune ligne d\'écriture à rapprocher (RG-TRE-04).');
        }

        $bankAccount = $ligne->getStatementImport()?->getBankAccount();
        if ($bankAccount === null) {
            throw new UnprocessableEntityHttpException('Ligne de relevé sans compte bancaire.');
        }

        if (\count($ledgerLines) === 1) {
            $ligneEcriture = $ledgerLines[0];
            $this->refuserSiDejaLettree($ligneEcriture);

            $reconciliationCode = Uuid::v4()->toRfc4122();

            $this->em->wrapInTransaction(function () use ($ligne, $ligneEcriture, $auteur, $reconciliationCode, $bankAccount): void {
                $lettrage = $this->lettrageHandler->lettrer($ligneEcriture, $auteur);
                $lettrage->setReconciliationCode($reconciliationCode);

                $ligne->setStatus(BankStatementLineStatus::Reconciled);
                $ligne->setMatchedLedgerLine($ligneEcriture);
                $ligne->setReconciliationCode($reconciliationCode);
                $this->em->persist($ligne);
                $this->em->flush();

                $this->publier($ligne, $bankAccount, $ligneEcriture, $reconciliationCode, $auteur);
            });

            return $ligne;
        }

        // Cas rare (§0.6) : `lettrerGroupe()` appelé littéralement, comme le décrit RG-TRE-04.
        // Elle lève elle-même 422 (déséquilibré) ou 409 (déjà lettré/non scellé) — pas de garde redondante.
        $ligneCorrespondante = $this->trouverLigneDuCompteBancaire($ledgerLines, $bankAccount);

        $this->em->wrapInTransaction(function () use ($ligne, $ledgerLines, $auteur, $ligneCorrespondante, $bankAccount): void {
            $lettrages = $this->lettrageHandler->lettrerGroupe($ledgerLines, $auteur);
            $reconciliationCode = $lettrages[0]->getReconciliationCode();
            \assert(\is_string($reconciliationCode));

            $ligne->setStatus(BankStatementLineStatus::Reconciled);
            $ligne->setMatchedLedgerLine($ligneCorrespondante);
            $ligne->setReconciliationCode($reconciliationCode);
            $this->em->persist($ligne);
            $this->em->flush();

            $this->publier($ligne, $bankAccount, $ligneCorrespondante, $reconciliationCode, $auteur);
        });

        return $ligne;
    }

    private function refuserSiDejaLettree(LigneEcriture $ligneEcriture): void
    {
        $dejaLettree = $this->em->getRepository(LettrageEcriture::class)->findOneBy(['ligne' => $ligneEcriture->getId()]);
        if ($dejaLettree !== null) {
            throw new ConflictHttpException(sprintf('La ligne d\'écriture %s est déjà lettrée (§0.6).', $ligneEcriture->getId()));
        }
    }

    /** @param list<LigneEcriture> $ledgerLines */
    private function trouverLigneDuCompteBancaire(array $ledgerLines, BankAccount $bankAccount): LigneEcriture
    {
        $compteBanque = $bankAccount->getLedgerAccount();
        if ($compteBanque !== null) {
            foreach ($ledgerLines as $candidate) {
                if ($candidate->getCompte()?->getId()->equals($compteBanque->getId())) {
                    return $candidate;
                }
            }
        }

        return $ledgerLines[0];
    }

    private function publier(BankStatementLine $ligne, BankAccount $bankAccount, LigneEcriture $ligneEcriture, string $reconciliationCode, Utilisateur $auteur): void
    {
        $etablissement = $bankAccount->getEstablishment();
        \assert($etablissement !== null);

        $this->eventBus->publish(new DomainEvent(
            'treasury.reconciliation_completed',
            new EventTenant($etablissement->getId()),
            new EventSubject('BankStatementLine', (string) $ligne->getId()),
            [
                'bankAccountId' => (string) $bankAccount->getId(),
                'ledgerLineId' => (string) $ligneEcriture->getId(),
                'amountCents' => $this->centimes($ligne->getAmount()),
                'reconciliationCode' => $reconciliationCode,
            ],
            new EventActor($auteur->getId()),
        ));
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
