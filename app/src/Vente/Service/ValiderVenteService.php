<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Enum\TypeSupport;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Port\AppairageAccesInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Cœur de la validation d'une vente (CA-8/11/12/15, §2/§6 du plan), réutilisé par le guichet et par
 * la resynchro hors-ligne. Refuse si le reste dû > 0 (sauf paiement différé autorisé, RG-M2-03),
 * décrémente le stock atomiquement (§6), scelle l'opération dans la chaîne NF525 (§2), émet et
 * appaire les supports d'accès (CA-12), applique le seuil d'impression (CA-11). Ne flush pas :
 * l'appelant porte la transaction pour garantir l'atomicité scellement/stock.
 */
final class ValiderVenteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierCalculateur $calculateur,
        private readonly DecrementStockHandler $stock,
        private readonly ScellementHandler $scellement,
        private readonly AppairageAccesInterface $appairage,
    ) {
    }

    /**
     * @param array<string, array{type?: string, identifiant?: string}> $supportsOverride indexé par id de ligne
     */
    public function valider(Vente $vente, array $supportsOverride = []): OperationScellee
    {
        if ($vente->getStatut() !== StatutVente::EnCours) {
            throw new ConflictHttpException('Seule une vente en cours peut être validée (NF525).');
        }

        $this->calculateur->recalculerVente($vente);

        // RG-M2-03 — reste dû doit être 0, sauf s'il existe un paiement différé autorisé.
        $reste = $this->calculateur->centimes($vente->getResteAPayer());
        if ($reste > 0 && !$this->aPaiementDiffere($vente)) {
            throw new UnprocessableEntityHttpException('Reste dû non nul : validation impossible sans paiement différé (RG-M2-03).');
        }

        // §6 — décrément de stock atomique (peut lever 422 « stock épuisé »), avant scellement.
        $this->stock->decrementer($vente);

        // CA-12 — émission et appairage des supports pour les lignes concernées.
        foreach ($vente->getLignes() as $ligne) {
            $support = $this->creerSupport($ligne, $supportsOverride[(string) $ligne->getId()] ?? null);
            if ($support === null) {
                continue;
            }
            $vente->addSupport($support);
            $this->em->persist($support);
            $this->appairage->appairer($support); // un échec laisse le support en « echec » (remise bloquée).
        }

        $vente->setStatut(StatutVente::Validee);

        // §2 — scellement NF525 dans la transaction de validation.
        $pdv = $vente->getSession()?->getPointDeVente();
        if ($pdv === null) {
            throw new UnprocessableEntityHttpException('Point de vente introuvable pour le scellement.');
        }
        $operation = $this->scellement->sceller(new OperationAScellerDto(
            $pdv,
            TypeOperationScellee::Vente,
            'Vente',
            $vente->getId(),
            $this->payload($vente),
        ));

        // CA-11 — impression automatique au-dessus du seuil.
        $seuil = $this->calculateur->centimes($pdv->getSeuilImpression());
        if ($this->calculateur->centimes($vente->getTotal()) >= $seuil) {
            $vente->setImprime(true);
        }

        return $operation;
    }

    private function aPaiementDiffere(Vente $vente): bool
    {
        foreach ($vente->getPaiements() as $paiement) {
            if ($paiement->isDiffere()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{type?: string, identifiant?: string}|null $override
     */
    private function creerSupport(LigneVente $ligne, ?array $override): ?BilletSupport
    {
        $produit = $this->em->getRepository(Produit::class)->find($ligne->getProduit());
        if (!$produit instanceof Produit) {
            return null;
        }
        $type = $produit->getType();
        if (!$type instanceof TypeProduit || !$this->emetSupport($type)) {
            return null;
        }

        $support = new BilletSupport();
        $support->setLigne($ligne);
        if (isset($override['type']) && ($enum = TypeSupport::tryFrom($override['type'])) !== null) {
            $support->setType($enum);
        } elseif ($type->aFacette(TypeProduit::FACETTE_CARNET)) {
            $support->setType(TypeSupport::Carte);
        }
        if (isset($override['identifiant']) && \is_string($override['identifiant'])) {
            $support->setIdentifiantSupport($override['identifiant']);
        }
        if ($produit->getCarte() !== null) {
            $support->setNbCompostages($produit->getCarte()->getStockCompostagesInitial());
        }

        return $support;
    }

    private function emetSupport(TypeProduit $type): bool
    {
        return $type->aFacette(TypeProduit::FACETTE_BILLET)
            || $type->aFacette(TypeProduit::FACETTE_CARNET)
            || $type->aFacette(TypeProduit::FACETTE_ACCES);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Vente $vente): array
    {
        $moyens = [];
        foreach ($vente->getPaiements() as $paiement) {
            $moyens[] = ['moyen' => $paiement->getMoyenCode(), 'montant' => $paiement->getMontant()];
        }
        $lignes = [];
        foreach ($vente->getLignes() as $ligne) {
            $lignes[] = [
                'produit' => (string) $ligne->getProduit(),
                'qte' => $ligne->getQuantite(),
                'montant' => $ligne->getMontantLigne(),
            ];
        }

        return [
            'vente' => (string) $vente->getId(),
            'numero' => $vente->getNumero(),
            'date' => $vente->getDate()->format(\DateTimeInterface::ATOM),
            'total' => $vente->getTotal(),
            'totalRemises' => $vente->getTotalRemises(),
            'lignes' => $lignes,
            'paiements' => $moyens,
        ];
    }
}
