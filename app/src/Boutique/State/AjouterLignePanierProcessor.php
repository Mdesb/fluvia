<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Security\BeneficiaireProprieteGuard;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\ProduitEtablissementGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Crm\Entity\Beneficiaire;
use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Reservation\Entity\Creneau;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/paniers/{id}/lignes (US-L8-02/03, RG-M3-02/03, CA-2). Un produit timed-entry impose
 * le choix d'un créneau disponible avant l'ajout. Corps :
 * { "produit": iri|uuid, "quantite"?: int, "creneau"?: iri|uuid, "beneficiaireRef"?: iri|uuid,
 *   "beneficiaireSimple"?: {nom,prenom,dateNaissance?}, "champsPersonnalises"?: {...} }
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class AjouterLignePanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly DisponibiliteAffichageHandler $disponibilite,
        private readonly ProduitEtablissementGuard $etablissementGuard,
        private readonly BeneficiaireProprieteGuard $beneficiaireGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        if ($data->getStatut() !== StatutPanier::Ouvert) {
            throw new ConflictHttpException('Ce panier n\'est plus ouvert (expiré ou déjà transformé en commande).');
        }

        $corps = $this->lecteur->corps();
        $produit = $this->resoudre(Produit::class, $corps['produit'] ?? null);
        if (!$produit instanceof Produit) {
            throw new UnprocessableEntityHttpException('« produit » est requis et doit référencer un produit existant.');
        }
        if ($produit->getStatut() !== StatutProduit::Publie || !$produit->aCanal(Canal::EnLigne)) {
            throw new UnprocessableEntityHttpException('Produit non publié ou non visible au canal en ligne (RG-M1-07/09).');
        }
        // Revue de sécurité — faille bloquante : le produit doit être rattaché à l'établissement du
        // panier (cloisonnement établissement, sinon un produit d'un tiers exploitant est achetable).
        $etablissementPanier = $data->getEtablissement() ?? $data->getVitrine()?->getEtablissement();
        $this->etablissementGuard->verifier($produit, $etablissementPanier);

        $quantite = \is_int($corps['quantite'] ?? null) ? max(1, $corps['quantite']) : 1;

        $creneauRef = $corps['creneau'] ?? null;
        $creneau = $creneauRef !== null ? $this->resoudre(Creneau::class, $creneauRef) : null;

        if ($this->disponibilite->estTimedEntry($produit)) {
            if (!$creneau instanceof Creneau) {
                // RG-M3-02 / CA-2 : ajout refusé sans créneau pour un produit timed-entry.
                throw new UnprocessableEntityHttpException('RG-M3-02 : un créneau doit être choisi pour ce produit avant l\'ajout au panier.');
            }
            if ($creneau->getPublicReserve() !== null && $creneau->getPublicReserve() !== '') {
                throw new UnprocessableEntityHttpException('Créneau réservé à un public spécifique, non réservable en ligne (RG-M5-08).');
            }
            if ($this->disponibilite->disponibilitePourCreneau($creneau) < $quantite) {
                throw new ConflictHttpException('Plus de place disponible sur ce créneau (« Reste : n »).');
            }
        } else {
            $reste = $this->disponibilite->disponibilitePourProduit($produit);
            if ($reste !== null && $reste < $quantite) {
                throw new ConflictHttpException('Stock insuffisant pour ce produit (RG-M1-10).');
            }
        }

        $beneficiaireRef = isset($corps['beneficiaireRef']) ? $this->resoudre(Beneficiaire::class, $corps['beneficiaireRef']) : null;
        if ($beneficiaireRef instanceof Beneficiaire) {
            // Revue de sécurité — faille majeure : un bénéficiaire référencé doit appartenir au foyer
            // du payeur identifié du panier (RG-M4-02), sinon fuite de PII d'un tiers.
            $this->beneficiaireGuard->verifier($data, $beneficiaireRef);
        }
        /** @var array<string, mixed>|null $beneficiaireSimple */
        $beneficiaireSimple = \is_array($corps['beneficiaireSimple'] ?? null) ? $corps['beneficiaireSimple'] : null;

        $ligne = new LignePanierEnLigne();
        $ligne->setProduit($produit)
            ->setQuantite($quantite)
            ->setCreneau($creneau)
            ->setBeneficiaireRef($beneficiaireRef instanceof Beneficiaire ? $beneficiaireRef : null)
            ->setBeneficiaireSimple($beneficiaireSimple)
            ->setChampsPersonnalises(\is_array($corps['champsPersonnalises'] ?? null) ? $corps['champsPersonnalises'] : null)
            ->setExpirationA($data->getDateExpiration())
            ->setAutorisationParentaleRequise($this->estMineur($beneficiaireRef, $beneficiaireSimple));

        $data->addLigne($ligne);
        $this->em->persist($ligne);
        $this->em->flush();

        return $data;
    }

    /** @param array<string, mixed>|null $beneficiaireSimple */
    private function estMineur(?Beneficiaire $beneficiaireRef, ?array $beneficiaireSimple): bool
    {
        if ($beneficiaireRef instanceof Beneficiaire) {
            return $beneficiaireRef->getClient()?->estMineur() ?? false;
        }
        $dateStr = \is_string($beneficiaireSimple['dateNaissance'] ?? null) ? $beneficiaireSimple['dateNaissance'] : null;
        if ($dateStr === null) {
            return false;
        }

        return new \DateTimeImmutable($dateStr) > new \DateTimeImmutable('-18 years');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T|null
     */
    private function resoudre(string $classe, mixed $reference): ?object
    {
        $id = PanierProprietaireGuard::estUuid($reference);
        if ($id === null) {
            return null;
        }

        return $this->em->getRepository($classe)->find($id);
    }
}
