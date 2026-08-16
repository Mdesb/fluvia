<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\Entity\Beneficiaire;
use App\Vente\Service\LecteurCorps;
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
        private readonly LecteurCorps $lecteur,
        private readonly PanierProprietaireGuard $guard,
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

            if ($beneficiaireRef instanceof Beneficiaire) {
                $ligne->setBeneficiaireRef($beneficiaireRef)->setBeneficiaireSimple(null);
                $ligne->setAutorisationParentaleRequise($beneficiaireRef->getClient()?->estMineur() ?? false);
            } elseif ($beneficiaireSimple !== null) {
                $ligne->setBeneficiaireRef(null)->setBeneficiaireSimple($beneficiaireSimple);
                $dateStr = \is_string($beneficiaireSimple['dateNaissance'] ?? null) ? $beneficiaireSimple['dateNaissance'] : null;
                $mineur = $dateStr !== null && new \DateTimeImmutable($dateStr) > new \DateTimeImmutable('-18 years');
                $ligne->setAutorisationParentaleRequise($mineur);
            }
        }

        $this->em->flush();

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
