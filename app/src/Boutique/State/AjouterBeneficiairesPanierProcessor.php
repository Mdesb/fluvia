<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\BeneficiaireProprieteGuard;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\Entity\Beneficiaire;
use App\Vente\Service\LecteurCorps;
use App\Boutique\Service\PanierTarificationHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /boutique/paniers/{id}/beneficiaires — étape 2 du tunnel (US-L8-05, RG-M4-02, CA-7). Affecte
 * un bénéficiaire à chaque article. Corps :
 * { "lignes": [{ "ligneId": uuid, "beneficiaireRef"?: iri|uuid, "beneficiaireSimple"?: {...} }] }.
 *
 * @implements ProcessorInterface<PanierEnLigne, PanierEnLigne>
 */
final class AjouterBeneficiairesPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierTarificationHandler $tarification,
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
        private readonly BeneficiaireProprieteGuard $beneficiaireGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $corps = $this->lecteur->corps();
        $lignesInput = \is_array($corps['lignes'] ?? null) ? $corps['lignes'] : [];

        foreach ($lignesInput as $entree) {
            if (!\is_array($entree)) {
                continue;
            }
            $ligneId = PanierProprietaireGuard::estUuid($entree['ligneId'] ?? null);
            $ligne = $this->trouverLigne($data, $ligneId);
            if ($ligne === null) {
                continue;
            }

            $beneficiaireRef = isset($entree['beneficiaireRef']) ? $this->resoudreBeneficiaire($entree['beneficiaireRef']) : null;
            /** @var array<string, mixed>|null $beneficiaireSimple */
            $beneficiaireSimple = \is_array($entree['beneficiaireSimple'] ?? null) ? $entree['beneficiaireSimple'] : null;

            // L'autorisation parentale n'est exigée que si LE PRODUIT l'exige (#101, décision CP-1) :
            // c'est un choix métier de l'établissement, pas une règle du RGPD. Un mineur sur un
            // produit qui ne l'exige pas passe sans case.
            $produitExige = $ligne->getProduit()?->isParentalConsentRequired() ?? false;

            if ($beneficiaireRef instanceof Beneficiaire) {
                // Revue de sécurité — faille majeure : un bénéficiaire référencé doit appartenir au
                // foyer du payeur identifié du panier (RG-M4-02), sinon fuite de PII d'un tiers.
                $this->beneficiaireGuard->verifier($data, $beneficiaireRef);
                $ligne->setBeneficiaireRef($beneficiaireRef)->setBeneficiaireSimple(null);
                $ligne->setAutorisationParentaleRequise($produitExige && ($beneficiaireRef->getClient()?->estMineur() ?? false));
            } elseif ($beneficiaireSimple !== null) {
                $ligne->setBeneficiaireRef(null)->setBeneficiaireSimple($beneficiaireSimple);
                $dateStr = \is_string($beneficiaireSimple['dateNaissance'] ?? null) ? $beneficiaireSimple['dateNaissance'] : null;
                $mineur = $dateStr !== null && new \DateTimeImmutable($dateStr) > new \DateTimeImmutable('-18 years');
                $ligne->setAutorisationParentaleRequise($produitExige && $mineur);
            } else {
                continue;
            }

            // ⚠ UNE AUTORISATION VAUT POUR UN BÉNÉFICIAIRE, PAS POUR UNE LIGNE. L'écran renvoie les
            // trois appels à chaque tentative (#101, §4) : sans cette remise à zéro, l'autorisation
            // donnée pour un enfant restait acquise à la ligne après qu'on y eut mis un autre enfant.
            // L'appel `consentement` qui suit la repose si la case est cochée.
            $ligne->setAutorisationParentaleHorodatage(null);
        }

        $this->em->flush();

        // ⚠ LE PANIER REPART AVEC SES PRIX. Sans cette ligne, la réponse d'une mutation ne
        // porte ni `total` ni `prixUnitaire` — seul `PanierAvecTotalProvider` (le GET) enrichit —
        // et le frontal, qui garde cette réponse en état, affichait un panier sans aucun montant
        // jusqu'au prochain rechargement. Mesuré à l'écran : « Total 8,00 € » disparaissait au
        // premier clic sur « − », remplacé par « le montant total sera calculé à l'étape de
        // paiement », qui se lit comme une politique et non comme un raté.
        // `calculer()` est pur : les trois champs sont transitoires, sans `#[ORM\Column]`.
        $this->tarification->calculer($data);

        return $data;
    }

    private function trouverLigne(PanierEnLigne $panier, ?Uuid $id): ?LignePanierEnLigne
    {
        if ($id === null) {
            return null;
        }
        foreach ($panier->getLignes() as $ligne) {
            if ($ligne->getId()->equals($id)) {
                return $ligne;
            }
        }

        return null;
    }

    private function resoudreBeneficiaire(mixed $reference): ?Beneficiaire
    {
        $id = PanierProprietaireGuard::estUuid($reference);
        if ($id === null) {
            return null;
        }
        $beneficiaire = $this->em->getRepository(Beneficiaire::class)->find($id);

        return $beneficiaire instanceof Beneficiaire ? $beneficiaire : null;
    }
}
