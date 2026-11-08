<?php

declare(strict_types=1);

namespace App\Kiosk\Service;

use App\Kiosk\Entity\Kiosk;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\ResolveurPrix;
use App\Vente\Service\AjoutLigneHandler;
use App\Vente\Service\CounterSellability;
use App\Vente\Tpe\ExternalTerminal;
use App\Vente\Tpe\TerminalRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * CE QUE LA BORNE PROPOSE : une tuile par couple (produit, tarif), avec son prix du jour.
 *
 * Un produit y figure s'il est, à la fois :
 * - au catalogue de ce site (`CounterSellability::isSoldAtSite`, D92) ;
 * - publié et porteur du canal « borne » (`CounterSellability::offerRefusal`, même règle qu'au guichet,
 *   sur un autre canal) ;
 * - ANONYME : un produit nominatif exige un bénéficiaire (RG-M2-04) que personne n'identifie à une
 *   borne. Il reste vendu au guichet ;
 * - dans la liste de la borne, quand elle en a une.
 *
 * Un tarif y figure s'il est VISIBLE sur le canal « borne » et a un prix aujourd'hui
 * (`ResolveurPrix`, RG-M1-07). C'est le levier de l'exploitant : un tarif réduit qui exige un
 * justificatif se règle « guichet » et n'apparaît pas à la borne.
 *
 * L'ajout de ligne refait les mêmes contrôles (`AjoutLigneHandler`, canal « borne ») : cette liste est
 * ce qu'on MONTRE, jamais ce qu'on CROIT. Une offre retirée entre l'affichage et le paiement est
 * refusée à l'ouverture de la commande, avant tout débit.
 */
final class KioskCatalogue
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CounterSellability $sellability,
        private readonly ResolveurPrix $prices,
        private readonly AjoutLigneHandler $lines,
        private readonly TerminalRegistry $terminals,
    ) {
    }

    /**
     * Le terminal de la borne est-il PILOTÉ ? Le terminal externe attend qu'un caissier lise le
     * résultat et le déclare : à une borne, personne ne le ferait, et la vente resterait tenue.
     * Sans terminal piloté, la borne est hors service — elle le dit au lieu d'encaisser.
     */
    public function terminalReady(Kiosk $kiosk): bool
    {
        $pdv = $kiosk->getPointOfSale();
        if ($pdv === null) {
            return false;
        }
        try {
            return $this->terminals->forPointOfSale($pdv)->connector() !== ExternalTerminal::CONNECTOR;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{offer: string, product: string, tariff: string, label: string, tariffLabel: string, price: string, card: bool}>
     */
    public function offers(Kiosk $kiosk): array
    {
        $site = $kiosk->getEtablissement();
        if ($site === null) {
            return [];
        }
        $today = new \DateTimeImmutable();

        $ids = $kiosk->getProductIds();
        if ($ids !== []) {
            $products = [];
            foreach ($ids as $id) {
                $p = Uuid::isValid($id) ? $this->em->getRepository(Produit::class)->find(Uuid::fromString($id)) : null;
                if ($p instanceof Produit) {
                    $products[] = $p;
                }
            }
        } else {
            $products = $this->em->getRepository(Produit::class)->findBy(['statut' => StatutProduit::Publie]);
        }

        $offers = [];
        foreach ($products as $product) {
            if (!$this->sellability->isSoldAtSite($product, $site)
                || $this->sellability->offerRefusal($product, Canal::Borne) !== null
                || $this->lines->requiresBeneficiary($product)) {
                continue;
            }
            $tariffs = [];
            foreach ($product->getGrilles() as $grid) {
                $tariff = $grid->getTypeTarif();
                if ($tariff instanceof TypeTarif) {
                    $tariffs[(string) $tariff->getId()] = $tariff;
                }
            }
            uasort($tariffs, static fn (TypeTarif $a, TypeTarif $b): int => [$a->getOrdreAffichage(), $a->getNom()] <=> [$b->getOrdreAffichage(), $b->getNom()]);
            foreach ($tariffs as $tariff) {
                $price = $this->prices->resoudre($product, $tariff, $today, Canal::Borne, null);
                if ($price === null) {
                    continue;
                }
                $offers[] = [
                    'offer' => self::offerKey($product->getId(), $tariff->getId()),
                    'product' => (string) $product->getId(),
                    'tariff' => (string) $tariff->getId(),
                    'label' => self::label($product),
                    'tariffLabel' => $tariff->getNom(),
                    'price' => $price,
                    'card' => $product->getCarte() !== null,
                ];
            }
        }

        return $offers;
    }

    public static function offerKey(Uuid $product, Uuid $tariff): string
    {
        return $product->toRfc4122() . '|' . $tariff->toRfc4122();
    }

    private static function label(Produit $product): string
    {
        $labels = $product->getLibelle();
        $label = $labels['fr'] ?? (reset($labels) ?: null);

        return \is_string($label) && $label !== '' ? $label : $product->getCode();
    }
}
