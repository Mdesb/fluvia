<?php

declare(strict_types=1);

namespace App\Personnel\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;

/**
 * Défense en profondeur (RG-SOCLE-05) contre une écriture ciblant, via un UUID/IRI brut du corps de
 * requête, un établissement différent de celui déclaré actif (`X-Etablissement`). La sécurité de
 * ressource API Platform (`is_granted('PERM', …)`) ne vérifie que les droits sur l'établissement
 * **actif** — elle ne recoupe jamais l'établissement effectivement ciblé par le corps de la requête
 * (`etablissement`, ou l'établissement porté par une entité résolue par `find($uuid)`). Cette classe
 * comble l'écart : cible autorisée si elle correspond à l'établissement actif (déjà validé par la
 * sécurité de ressource), **ou** si l'agent détient la permission demandée directement sur cette
 * cible (`CalculateurDroits`, indépendamment de l'en-tête actif).
 */
final class EtablissementCibleVerificateur
{
    public function __construct(
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function autorise(Etablissement $cible, Utilisateur $agent, string $module, string $action): bool
    {
        $idActif = $this->contexte->idActif();
        if ($idActif !== null && (string) $idActif === (string) $cible->getId()) {
            return true;
        }

        $codes = $this->calculateur->codesEffectifs($agent, $cible->getId());

        return $this->calculateur->autorise($codes, $module, $action);
    }
}
