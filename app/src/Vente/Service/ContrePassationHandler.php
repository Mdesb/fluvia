<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Port\AppairageAccesInterface;
use App\Vente\Port\PorteMonnaieVirtuelInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Contre-passation (RG-M2-07 / CA-13) : annulation et remboursement produisent un Avoir tracé
 * (horodaté, motivé, opérateur, vente d'origine), sans jamais supprimer de ligne d'origine. L'avoir
 * est scellé dans la chaîne NF525. Une annulation après impression invalide le support émis côté
 * Accès (US-L2-09). Aucun remboursement automatique : il passe par une demande explicite.
 */
final class ContrePassationHandler
{
    public function __construct(
        private readonly GenerateurNumero $generateur,
        private readonly ScellementHandler $scellement,
        private readonly AppairageAccesInterface $appairage,
        private readonly PanierCalculateur $calc,
        // Frontière M4 (RG-M4-03, CA-9, §2.3 plan-crm.md) : nullable, même précaution que
        // `PaiementHandler` — ne casse aucun test M2 existant si aucun port n'est câblé.
        private readonly ?PorteMonnaieVirtuelInterface $pmv = null,
    ) {
    }

    public function annuler(Vente $vente, string $motif, Utilisateur $auteur): Avoir
    {
        $this->exigerValidee($vente);
        $avoir = $this->creerAvoir($vente, $vente->getTotal(), $motif, $auteur, 'annulation');

        // Annulation après impression : dévalidation du support émis côté Accès (US-L2-09).
        if ($vente->isImprime()) {
            foreach ($vente->getSupports() as $support) {
                $this->appairage->invalider($support);
            }
            $avoir->setSupportInvalide(true);
        }

        $this->recrediterPmv($vente);
        $vente->setStatut(StatutVente::Annulee);

        return $avoir;
    }

    public function rembourser(Vente $vente, ?string $montant, string $motif, Utilisateur $auteur): Avoir
    {
        $this->exigerValidee($vente);

        $montantAvoir = $montant !== null ? number_format((float) $montant, 2, '.', '') : $vente->getTotal();
        if ($this->calc->centimes($montantAvoir) <= 0 || $this->calc->centimes($montantAvoir) > $this->calc->centimes($vente->getTotal())) {
            throw new UnprocessableEntityHttpException('Montant de remboursement invalide (0 < montant ≤ total).');
        }

        $avoir = $this->creerAvoir($vente, $montantAvoir, $motif, $auteur, 'remboursement');
        // Recrédit PMV (RG-M4-03) : le cahier ne détaille pas la ventilation d'un remboursement
        // partiel entre moyens — ⚠ HYPOTHÈSE, simplification retenue : recrédit intégral de la part
        // PMV de la vente (comme pour une annulation complète), quel que soit le montant partiel
        // demandé ; à affiner si un besoin de ventilation proportionnelle par moyen se confirme.
        $this->recrediterPmv($vente);
        $vente->setStatut(StatutVente::AvoirEmis);

        return $avoir;
    }

    /** Recrédite intégralement la part PMV d'une vente annulée/remboursée (RG-M4-03, CA-9). */
    private function recrediterPmv(Vente $vente): void
    {
        if ($this->pmv === null) {
            return;
        }
        $clientId = $vente->getClient();
        if ($clientId === null) {
            return;
        }
        foreach ($vente->getPaiements() as $paiement) {
            \assert($paiement instanceof Paiement);
            if ($paiement->getMoyenCode() === 'pmv') {
                $this->pmv->crediter($clientId, $paiement->getMontant(), $vente->getId(), 'remboursement_vente');
            }
        }
    }

    private function creerAvoir(Vente $vente, string $montant, string $motif, Utilisateur $auteur, string $nature): Avoir
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour une contre-passation (RG-M2-07).');
        }

        $avoir = (new Avoir())
            ->setNumero($this->generateur->numeroAvoir())
            ->setVenteOrigine($vente)
            ->setMontant($montant)
            ->setMotif($motif)
            ->setAuteur($auteur)
            ->setNature($nature)
            ->setEtablissement($vente->getEtablissement());

        $pdv = $vente->getSession()?->getPointDeVente();
        if ($pdv !== null) {
            $this->scellement->sceller(new OperationAScellerDto(
                $pdv,
                TypeOperationScellee::Avoir,
                'Avoir',
                $avoir->getId(),
                [
                    'avoir' => (string) $avoir->getId(),
                    'numero' => $avoir->getNumero(),
                    'venteOrigine' => (string) $vente->getId(),
                    'montant' => $montant,
                    'nature' => $nature,
                    'motif' => $motif,
                ],
            ));
        }

        return $avoir;
    }

    private function exigerValidee(Vente $vente): void
    {
        if (!\in_array($vente->getStatut(), [StatutVente::Validee, StatutVente::AvoirEmis], true)) {
            throw new ConflictHttpException('Seule une vente validée peut être annulée/remboursée (contre-passation).');
        }
    }
}
