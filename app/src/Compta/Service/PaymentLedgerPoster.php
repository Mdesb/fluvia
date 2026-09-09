<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\PaymentMethodTreasuryAccount;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\NatureOperation;
use App\Compta\Regime\CompteLookupService;
use App\Compta\Regime\RegimeComptableResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * L'écriture d'encaissement client : débit du compte de trésorerie du moyen, crédit du compte client.
 *
 * ── POURQUOI CE SERVICE EXISTE ──────────────────────────────────────────────────────────────────
 *
 * `NatureOperation::Encaissements` existait, résolvait vers le journal `ENC` (« Journal des
 * encaissements »), et ce journal était semé dans chaque plan comptable — **sans un seul appelant**.
 * Mesuré le 08/09 en préproduction : 0 écriture au journal `ENC`, alors que `FA-2026-00001` portait
 * un débit `411000` de 360,00 €, deux règlements par virement enregistrés, et sa ligne 411 lettrée.
 * Autrement dit : la créance restait au bilan et la trésorerie n'y entrait jamais.
 *
 * ── CE QU'IL NE FAIT PAS ────────────────────────────────────────────────────────────────────────
 *
 * Il ne flush pas, exactement comme {@see DirectLedgerEntryBuilder} dont il n'est qu'un cadrage :
 * la transaction reste à l'appelant, dans la sienne, avec son propre verrou. Il ne lettre pas non
 * plus — le rapprochement des deux lignes 411 appartient à l'appelant, qui seul sait si la pièce est
 * soldée.
 *
 * ── LE MIROIR CÔTÉ FOURNISSEUR ──────────────────────────────────────────────────────────────────
 *
 * `App\Finance\SupplierInvoice\Service\SupplierPaymentHandler` fait déjà ce geste dans l'autre sens
 * (débit 401 / crédit trésorerie). On en reprend les conventions plutôt que d'en inventer :
 * `PeriodeComptableResolver::resoudreOuCreer()` pour la période, et la contrepartie portée par la
 * ligne de tiers.
 *
 * ⚠ MAIS PAS SON TAUX, ET C'EST DÉLIBÉRÉ. `LigneEcriture.tauxTva` est `nullable: false` en base,
 * alors qu'un encaissement ne porte aucune TVA — elle a été collectée à la facture, pas au règlement.
 * Deux chemins du dépôt contournent ça en **empruntant** le taux de la pièce soldée : la ligne client
 * de `EmettreFactureDirecteHandler` prend `$premierTaux`, le règlement fournisseur prend celui de la
 * ligne 401. On ne les suit pas ici, pour deux raisons mesurées :
 *
 *  1. **C'est faux dans tout état qui groupe par taux.** Emprunter 20 % sur une ligne d'encaissement
 *     attribue de la TVA à un mouvement qui n'en porte pas. Le dépôt a déjà un taux pour ce cas —
 *     `TauxTva::LIBELLE_HORS_CHAMP`, « Hors champ (opération non commerciale) » — résolu par
 *     {@see CompteLookupService::tauxHorsChamp()} et semé par profil (`StructureOnboarding`,
 *     `BackfillAccountingProfilesCommand`).
 *  2. **L'emprunt n'est pas toujours possible.** Un impayé né avant ce lot n'a pas de facture, donc
 *     aucune ligne dont emprunter un taux. Un service qui ne sait écrire que quand une pièce existe
 *     aurait laissé ces encaissements-là sans écriture — le défaut même qu'on corrige.
 *
 * ⚠ Un taux à 0 % ne conviendrait pas davantage : six taux à 0 % en base sont « hors champ », et
 * d'autres « exonérés » — deux régimes que la facture ne distingue pas à l'œil et que le FEC oppose.
 */
final class PaymentLedgerPoster
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegimeComptableResolver $regimes,
        private readonly PeriodeComptableResolver $periods,
        private readonly CompteLookupService $lookup,
        private readonly DirectLedgerEntryBuilder $builder,
    ) {
    }

    /**
     * @param CompteComptable $receivableAccount compte de tiers crédité (411 pour un client)
     *
     * @throws UnprocessableEntityHttpException si le montant n'est pas positif, si le moyen de
     *                                          paiement ne porte aucun compte de trésorerie, ou si le
     *                                          taux « hors champ » manque au profil
     */
    public function post(
        ProfilExploitant $profile,
        MoyenPaiement $paymentMethod,
        int $amountCents,
        \DateTimeImmutable $date,
        CompteComptable $receivableAccount,
        string $label,
        ?string $counterpartyType = null,
        ?Uuid $counterpartyId = null,
        ?string $counterpartyLabel = null,
    ): EcritureComptable {
        if ($amountCents <= 0) {
            throw new UnprocessableEntityHttpException('Un encaissement porte un montant strictement positif.');
        }

        // ⚠ LE COMPTE SE LIT PAR (EXPLOITANT, MOYEN), JAMAIS SUR LE MOYEN SEUL. Le référentiel des
        //    moyens de paiement est GLOBAL — aucune colonne de rattachement — tandis que les comptes
        //    sont par exploitant. Lire le compte sur le moyen ferait écrire les encaissements de tous
        //    les établissements dans le grand livre du premier qui l'a configuré (voir l'en-tête de
        //    `PaymentMethodTreasuryAccount`).
        //
        // ⚠ REFUSER, JAMAIS RETOMBER SUR UN COMPTE PAR DÉFAUT. Un encaissement écrit au mauvais compte
        //    ne se voit pas : l'écriture est équilibrée, le lettrage passe, le solde du client revient
        //    à zéro — et l'argent est annoncé là où il n'est pas. Le seul symptôme arriverait au
        //    rapprochement bancaire, des semaines plus tard. Le message nomme le moyen ET l'exploitant,
        //    sans quoi on cherche lequel des onze, sur lequel des profils.
        $mapping = $this->em->getRepository(PaymentMethodTreasuryAccount::class)->findOneBy([
            'businessProfile' => $profile->getId(),
            'paymentMethod' => $paymentMethod->getId(),
        ]);
        $treasuryAccount = $mapping?->getTreasuryAccount();
        if (!$treasuryAccount instanceof CompteComptable) {
            // ⚠ LE MESSAGE NE PROMET PAS D'ÉCRAN. Il en nommait un — « Paramètres › Caisse & moyens de
            //    paiement » — qui ne sait pas encore régler ce compte : la ressource et son écran
            //    arriveront ensemble, plus tard. Un message qui envoie quelqu'un vers un écran qui ne
            //    peut rien faire coûte plus cher que pas de message du tout.
            throw new UnprocessableEntityHttpException(sprintf(
                'Aucun compte de trésorerie n\'est rattaché au moyen de paiement « %s » pour l\'exploitant %s : '
                . 'impossible d\'écrire l\'encaissement.',
                $paymentMethod->getCode(),
                $profile->getSiren() ?? (string) $profile->getId(),
            ));
        }

        $journal = $this->regimes->pour($profile)->journalPour($profile, NatureOperation::Encaissements);

        // `resoudreOuCreer` et non `periodePour` : sur un exercice jamais ouvert, la seconde refuserait
        // tout encaissement. Une période FERMÉE reste refusée — c'est `DirectLedgerEntryBuilder` qui
        // s'en charge (RG-CLOTURE-10), et son refus est celui qu'on veut voir remonter.
        $period = $this->periods->resoudreOuCreer($profile, $date);

        // Voir l'avertissement de classe : un encaissement est hors champ, il n'emprunte pas le taux
        // de la pièce qu'il solde. `tauxHorsChamp()` refuse lui-même si le semis manque au profil.
        $rate = $this->lookup->tauxHorsChamp($profile);

        $lines = [
            new DirectLedgerEntryLine(
                compte: $treasuryAccount,
                debitCentimes: $amountCents,
                creditCentimes: 0,
                tauxTva: $rate,
                libelle: $label,
            ),
            new DirectLedgerEntryLine(
                compte: $receivableAccount,
                debitCentimes: 0,
                creditCentimes: $amountCents,
                tauxTva: $rate,
                libelle: $label,
                counterpartyType: $counterpartyType,
                counterpartyId: $counterpartyId,
                counterpartyLabel: $counterpartyLabel,
            ),
        ];

        return $this->builder->construire($profile, $journal, $period, $date, $label, $lines);
    }
}
