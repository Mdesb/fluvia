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
use App\Vente\Port\SaleSubscriptionInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
        private readonly SaleSubscriptionInterface $abonnements,
        // Frontière M4 (RG-M4-03, CA-9, §2.3 plan-crm.md) : nullable, même précaution que
        // `PaiementHandler` — ne casse aucun test M2 existant si aucun port n'est câblé.
        private readonly ?PorteMonnaieVirtuelInterface $pmv = null,
    ) {
    }

    public function annuler(Vente $vente, string $motif, Utilisateur $auteur): Avoir
    {
        return $this->sousVerrou($vente, fn (): Avoir => $this->annulerSousVerrou($vente, $motif, $auteur));
    }

    public function rembourser(Vente $vente, ?string $montant, string $motif, Utilisateur $auteur): Avoir
    {
        return $this->sousVerrou($vente, fn (): Avoir => $this->rembourserSousVerrou($vente, $montant, $motif, $auteur));
    }

    /**
     * REMBOURSÉE EN TOTALITÉ, LA VENTE EST DÉFAITE COMME UNE ANNULATION : l'abonnement qu'elle a créé
     * est résilié, par le même chemin (#292, décision de Maxime du 08/10). « En totalité » : ses avoirs
     * cumulés atteignent son total, en un ou plusieurs remboursements. Un remboursement partiel ne
     * résilie rien. Appelée par chaque remboursement après le commit de son avoir, comme l'annulation.
     */
    public function resilierSiRembourseeEnTotalite(Vente $vente, string $motif): void
    {
        $rendu = 0;
        foreach ($this->em->getRepository(Avoir::class)->findBy(['venteOrigine' => $vente]) as $avoir) {
            $rendu += $this->calc->centimes($avoir->getMontant());
        }
        if ($rendu >= $this->calc->centimes($vente->getTotal())) {
            $this->abonnements->terminateSubscriptionsFromSale($vente, sprintf('Vente remboursée (%s)', $motif));
        }
    }

    /**
     * Une contre-passation à la fois par vente (patron transactionnel de `ValiderVenteService`) : la
     * vente est verrouillée et relue avant tout contrôle, puis l'avoir, son scellement et le recrédit
     * PMV sont validés d'un bloc. Sans ce verrou, deux remboursements simultanés lisaient le même
     * « déjà recrédité » et recréditaient chacun le porte-monnaie.
     *
     * @param \Closure(): Avoir $contrePassation
     */
    private function sousVerrou(Vente $vente, \Closure $contrePassation): Avoir
    {
        return $this->em->getConnection()->transactional(function () use ($vente, $contrePassation): Avoir {
            $this->em->refresh($vente, LockMode::PESSIMISTIC_WRITE);
            $avoir = $contrePassation();
            $this->em->persist($avoir);
            $this->em->flush();

            return $avoir;
        });
    }

    private function annulerSousVerrou(Vente $vente, string $motif, Utilisateur $auteur): Avoir
    {
        $this->exigerValidee($vente);
        // Après des remboursements partiels, l'annulation n'émet que le reste (É11 du ticket opposable) —
        // un avoir nul si tout a été rendu (ou plus, par l'ancien code) : il annule la vente et ses
        // billets, il ne rend plus rien.
        $montant = $this->calc->decimal(max(0, $this->calc->centimes($vente->getTotal()) - $this->dejaRembourse($vente)));
        $avoir = $this->creerAvoir($vente, $montant, $motif, $auteur, 'annulation');

        // Annulation après impression : dévalidation du support émis côté Accès (US-L2-09).
        if ($vente->isImprime()) {
            foreach ($vente->getSupports() as $support) {
                $this->appairage->invalider($support);
            }
            $avoir->setSupportInvalide(true);
        }

        $this->recrediterPmv($vente, $montant);
        $vente->setStatut(StatutVente::Annulee);

        return $avoir;
    }

    private function rembourserSousVerrou(Vente $vente, ?string $montant, string $motif, Utilisateur $auteur): Avoir
    {
        $this->exigerValidee($vente);

        // JAMAIS PLUS QUE CE QUI RESTE (É11) : le plafond était le total, à chaque remboursement — deux
        // remboursements de 30,00 sur une vente de 45,00 rendaient 60,00. Sans montant : le reste.
        $deja = $this->dejaRembourse($vente);
        $reste = $this->calc->centimes($vente->getTotal()) - $deja;
        $montantAvoir = $montant !== null ? number_format((float) $montant, 2, '.', '') : $this->calc->decimal($reste);
        if ($this->calc->centimes($montantAvoir) <= 0 || $this->calc->centimes($montantAvoir) > $reste) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Montant de remboursement invalide : il reste %s € remboursables sur cette vente (total %s €, déjà remboursé %s €).',
                $this->calc->decimal(max(0, $reste)),
                $vente->getTotal(),
                $this->calc->decimal($deja),
            ));
        }

        $avoir = $this->creerAvoir($vente, $montantAvoir, $motif, $auteur, 'remboursement');
        $this->recrediterPmv($vente, $montantAvoir);
        $vente->setStatut(StatutVente::AvoirEmis);

        return $avoir;
    }

    /**
     * Recrédit PMV d'une contre-passation (RG-M4-03, CA-9) : le plus petit de l'avoir et de la part PMV
     * non encore recréditée (option A de P-5, plan ticket-opposable — choix à valider par Maxime) ; le
     * reste de l'avoir relève des autres moyens. Jusqu'ici, chaque remboursement, même partiel,
     * recréditait toute la part PMV : 50 € payés, deux remboursements partiels, 100 € rendus.
     */
    private function recrediterPmv(Vente $vente, string $montantAvoir): void
    {
        $clientId = $vente->getClient();
        if ($this->pmv === null || $clientId === null) {
            return;
        }
        $partPmv = 0;
        foreach ($vente->getPaiements() as $paiement) {
            \assert($paiement instanceof Paiement);
            if ($paiement->getMoyenCode() === 'pmv') {
                $partPmv += $this->calc->centimes($paiement->getMontant());
            }
        }
        $reste = $partPmv - $this->calc->centimes($this->pmv->recreditePourVente($clientId, $vente->getId()));
        $credit = min($this->calc->centimes($montantAvoir), $reste);
        if ($credit > 0) {
            $this->pmv->crediter($clientId, $this->calc->decimal($credit), $vente->getId(), 'remboursement_vente');
        }
    }

    /**
     * Ce que les avoirs de la vente ont déjà rendu, en centimes. Lu sous le verrou de la vente
     * (`sousVerrou()`), que prend toute contre-passation : une lecture simple suffit, son instantané
     * (fixé à la première lecture simple de la transaction) date d'après l'attente du verrou.
     */
    private function dejaRembourse(Vente $vente): int
    {
        return $this->calc->centimes((string) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(montant), 0) FROM vente_avoir WHERE vente_origine_id = UNHEX(:v)',
            ['v' => bin2hex($vente->getId()->toBinary())],
        ));
    }

    private function creerAvoir(Vente $vente, string $montant, string $motif, Utilisateur $auteur, string $nature): Avoir
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour une contre-passation (RG-M2-07).');
        }

        $avoir = (new Avoir())
            ->setNumero($this->generateur->numeroAvoir($vente->getPointDeVente()))
            ->setVenteOrigine($vente)
            ->setMontant($montant)
            ->setMotif($motif)
            ->setAuteur($auteur)
            ->setNature($nature)
            ->setEtablissement($vente->getEtablissement());

        // D44-bis — porté par la vente : une vente directe n'a pas de session d'où le déduire.
        $pdv = $vente->getPointDeVente();
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
