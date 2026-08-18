<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Garde « établissement précis » (§4.1 plan, ⚠ HYPOTHÈSE §4.2 spec, analogue à
 * `VerificateurPlafondDroits`, code socle non modifié) : un titulaire de `autorisation.gerer`/
 * `.approuver` ne peut agir sur une `LimiteAutorisation`/`DemandeEscalade` que sur l'établissement
 * réellement soumis dans le corps de la requête, pas seulement sur le contexte actif
 * (`X-Etablissement`) — empêche un administrateur d'établissement A de configurer/traiter sur un
 * établissement B où il n'a pas le droit, même s'il le possède sur A.
 */
final class VerificateurPerimetreLimite
{
    public function __construct(
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function verifier(Utilisateur $auteur, Etablissement $etablissementCible, string $action = 'gerer'): void
    {
        $codes = $this->calculateur->codesEffectifs($auteur, $etablissementCible->getId());
        if (!$this->calculateur->autorise($codes, 'autorisation', $action)) {
            throw new AccessDeniedException(sprintf(
                "Vous ne possédez pas « autorisation.%s » sur cet établissement précisément.",
                $action
            ));
        }
    }
}
