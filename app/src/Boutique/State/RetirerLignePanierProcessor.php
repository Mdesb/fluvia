<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /boutique/paniers/{id}/lignes/{ligneId}/retirer (§4.3 spec) : retire une ligne à tout instant
 * avant paiement, libère immédiatement le compteur temporaire associé (créneau timed-entry inclus).
 * Résolution manuelle du panier (`read: false`, comble des manques boutique) : avec **deux**
 * variables d'URI (`{id}`/`{ligneId}`), le provider Doctrine par défaut ne résolvait pas
 * fiablement `PanierEnLigne` (seul `id` correspond à une propriété de l'entité).
 *
 * @implements ProcessorInterface<mixed, PanierEnLigne>
 */
final class RetirerLignePanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierTarificationHandler $tarification,
        private readonly PanierProprietaireGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        $panier = $this->resoudrePanier($uriVariables['id'] ?? null);
        $this->guard->verifier($panier);

        $id = PanierProprietaireGuard::estUuid($uriVariables['ligneId'] ?? null);
        $ligne = $id !== null ? $this->em->getRepository(LignePanierEnLigne::class)->find($id) : null;
        if (!$ligne instanceof LignePanierEnLigne || $ligne->getPanier()?->getId()->toRfc4122() !== $panier->getId()->toRfc4122()) {
            throw new NotFoundHttpException('Ligne de panier introuvable.');
        }

        $panier->removeLigne($ligne);
        $this->em->remove($ligne);
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
