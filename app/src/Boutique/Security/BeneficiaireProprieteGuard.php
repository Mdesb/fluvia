<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use App\Boutique\Entity\PanierEnLigne;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Contrôle de propriété du bénéficiaire référencé (revue de sécurité — faille majeure) : un
 * `beneficiaireRef` ne peut être affecté à une ligne de panier que s'il appartient au foyer
 * (`App\Crm\Entity\Famille`, RG-M4-02) du payeur **identifié avec compte** du panier
 * (`PanierEnLigne.compteClient`). Un `beneficiaireRef` n'a de sens que pour un payeur avec compte
 * (§4.5 plan-boutique.md) — un panier invité (sans `compteClient`) ne peut jamais référencer un
 * `Beneficiaire` existant (données d'un tiers potentiellement inconnu) et doit utiliser
 * `beneficiaireSimple`. Sans ce garde, n'importe quel visiteur pouvait rattacher gratuitement sa
 * commande au dossier bénéficiaire d'un tiers (fuite de PII, RG-M4-02).
 */
final class BeneficiaireProprieteGuard
{
    public function verifier(PanierEnLigne $panier, Beneficiaire $beneficiaire): void
    {
        $client = $panier->getCompteClient()?->getClient();
        if ($client === null || !$this->appartientAuFoyer($client, $beneficiaire)) {
            throw new AccessDeniedHttpException('Ce bénéficiaire n\'appartient pas au foyer du payeur identifié.');
        }
    }

    private function appartientAuFoyer(Client $client, Beneficiaire $beneficiaire): bool
    {
        $famille = $beneficiaire->getFamille();
        if ($famille === null) {
            return false;
        }
        if ($famille->getPayeurPrincipal()?->getId()->equals($client->getId()) === true) {
            return true;
        }
        foreach ($famille->getBeneficiaires() as $membre) {
            if ($membre->estActif() && $membre->getClient()?->getId()->equals($client->getId()) === true) {
                return true;
            }
        }

        return false;
    }
}
