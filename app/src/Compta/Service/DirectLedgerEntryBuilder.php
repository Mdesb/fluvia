<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutEcriture;
use App\Compta\Nf525\ScellementEcritureHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Service interne réutilisable (§0.4/§4.5 spec) : factorise le patron déjà éprouvé par
 * `App\Facturation\Service\EmettreFactureDirecteHandler` (résolution de comptes -> construction des
 * lignes -> `ScellementEcritureHandler::sceller()` -> persist) pour que la saisie manuelle **et**
 * FIN-2/FIN-3 ne dupliquent pas cette mécanique. Aucun second moteur d'écritures : `sceller()` est
 * réutilisé strictement tel quel.
 *
 * Ne flush PAS : le flush reste sous la responsabilité de l'appelant, dans SA transaction (même patron
 * que `EmettreFactureDirecteHandler`). Précondition : l'appelant a déjà verrouillé
 * (`LockMode::PESSIMISTIC_WRITE`) SON PROPRE objet métier (`SupplierInvoice`, `ExpenseReport`…) et
 * résolu tous les comptes AVANT d'appeler cette méthode.
 */
final class DirectLedgerEntryBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementEcritureHandler $scellement,
    ) {
    }

    /**
     * @param list<DirectLedgerEntryLine> $lignes
     *
     * @throws ConflictHttpException             si la période n'est pas ouverte (RG-CLOTURE-10)
     * @throws UnprocessableEntityHttpException si la somme débit ≠ crédit
     */
    public function construire(
        ProfilExploitant $profil,
        Journal $journal,
        PeriodeComptable $periode,
        \DateTimeImmutable $date,
        string $libelle,
        array $lignes,
        ?Uuid $venteOrigine = null,
    ): EcritureComptable {
        if (!$periode->estOuverte()) {
            throw new ConflictHttpException('Écriture impossible : la période comptable n\'est pas ouverte (RG-CLOTURE-10).');
        }

        $ecriture = new EcritureComptable();
        $ecriture->setProfilExploitant($profil);
        $ecriture->setJournal($journal);
        $ecriture->setPeriode($periode);
        $ecriture->setDateEcriture($date);
        $ecriture->setLibelle($libelle);
        // Même statut que `GenerateurEcrituresHandler`/`ExtourneEcritureProcessor` (RG-M6-11, « aucune
        // branche spécifique ») : la constante `Provisoire` du constructeur d'entité n'est en pratique
        // jamais utilisée telle quelle par aucun flux de création réel du dépôt.
        $ecriture->setStatut(StatutEcriture::Controlee);
        if ($venteOrigine !== null) {
            $ecriture->setVenteOrigine($venteOrigine);
        }

        foreach ($lignes as $ligneDto) {
            $ligne = new LigneEcriture();
            $ligne->setCompte($ligneDto->compte);
            $ligne->setDebitCentimes($ligneDto->debitCentimes);
            $ligne->setCreditCentimes($ligneDto->creditCentimes);
            $ligne->setTauxTva($ligneDto->tauxTva);
            $ligne->setLibelle($ligneDto->libelle);
            $ligne->setCounterpartyType($ligneDto->counterpartyType);
            $ligne->setCounterpartyId($ligneDto->counterpartyId);
            $ligne->setCounterpartyLabel($ligneDto->counterpartyLabel);
            $ecriture->addLigne($ligne);
        }

        // Défense en profondeur (§0.4) : l'appelant doit déjà avoir validé l'équilibre en amont.
        if (!$ecriture->estEquilibree()) {
            throw new UnprocessableEntityHttpException('Écriture déséquilibrée : Σdébit ≠ Σcrédit.');
        }

        $this->scellement->sceller($ecriture);
        $this->em->persist($ecriture);

        return $ecriture;
    }
}
