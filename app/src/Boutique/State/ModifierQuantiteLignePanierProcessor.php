<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\DisponibiliteAffichageHandler;
use App\Vente\Service\LecteurCorps;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /boutique/paniers/{id}/lignes/{ligneId}/quantite (§4.3 spec, comble des manques boutique) :
 * modifie la quantité d'une ligne existante sans retrait + ré-ajout côté front. Corps :
 * { "quantite": int ≥ 1 }. Revérifie la disponibilité (RG-M3-08) en tenant compte de la réservation
 * déjà portée par la ligne elle-même (même moteur que `DisponibiliteAffichageHandler`, aucune
 * disponibilité recalculée à la main). Résolution manuelle du panier (`read: false`) : même correctif
 * que `RetirerLignePanierProcessor` (deux variables d'URI, provider Doctrine par défaut peu fiable).
 *
 * @implements ProcessorInterface<mixed, PanierEnLigne>
 */
final class ModifierQuantiteLignePanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierTarificationHandler $tarification,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly DisponibiliteAffichageHandler $disponibilite,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        $panier = $this->resoudrePanier($uriVariables['id'] ?? null);
        $this->guard->verifier($panier);

        if ($panier->getStatut() !== StatutPanier::Ouvert) {
            throw new ConflictHttpException('Ce panier n\'est plus ouvert (expiré ou déjà transformé en commande).');
        }

        $id = PanierProprietaireGuard::estUuid($uriVariables['ligneId'] ?? null);
        $ligne = $id !== null ? $this->em->getRepository(LignePanierEnLigne::class)->find($id) : null;
        if (!$ligne instanceof LignePanierEnLigne || $ligne->getPanier()?->getId()->toRfc4122() !== $panier->getId()->toRfc4122()) {
            throw new NotFoundHttpException('Ligne de panier introuvable.');
        }

        $corps = $this->lecteur->corps();
        $quantite = $corps['quantite'] ?? null;
        if (!\is_int($quantite) || $quantite < 1) {
            throw new UnprocessableEntityHttpException('« quantite » est requise et doit être un entier ≥ 1.');
        }

        $produit = $ligne->getProduit();
        $creneau = $ligne->getCreneau();
        if ($creneau !== null) {
            // La disponibilité affichée exclut déjà la réservation de cette ligne : on la rajoute pour
            // connaître la disponibilité « propre à cette ligne » avant d'appliquer la nouvelle quantité.
            $resteYCompris = $this->disponibilite->disponibilitePourCreneau($creneau) + $ligne->getQuantite();
            if ($quantite > $resteYCompris) {
                throw new ConflictHttpException('Plus de place disponible sur ce créneau (« Reste : n »).');
            }
        } elseif ($produit !== null) {
            $resteSansLigne = $this->disponibilite->disponibilitePourProduit($produit);
            if ($resteSansLigne !== null) {
                $resteYCompris = $resteSansLigne + $ligne->getQuantite();
                if ($quantite > $resteYCompris) {
                    throw new ConflictHttpException('Stock insuffisant pour ce produit (RG-M1-10).');
                }
            }
        }

        $ligne->setQuantite($quantite);
        $this->em->flush();

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($panier);

        return $panier;
    }

    private function resoudrePanier(mixed $reference): PanierEnLigne
    {
        $id = PanierProprietaireGuard::estUuid($reference);
        $panier = $id !== null ? $this->em->getRepository(PanierEnLigne::class)->find($id) : null;
        if (!$panier instanceof PanierEnLigne) {
            throw new NotFoundHttpException('Panier introuvable.');
        }

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($panier);

        return $panier;
    }
}
