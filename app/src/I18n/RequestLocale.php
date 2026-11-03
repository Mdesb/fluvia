<?php

declare(strict_types=1);

namespace App\I18n;

use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * La langue de la requête en cours, pour les processeurs qui produisent du texte.
 *
 * Ordre : l'en-tête `Accept-Language` s'il nomme une langue parlée, puis la langue de
 * l'établissement actif (`X-Etablissement`), puis le français.
 *
 * L'en-tête passe d'abord parce que le frontal l'envoie à chaque appel avec la langue qu'il AFFICHE
 * (préférence de l'utilisateur, sinon langue de l'établissement) : le serveur répond donc dans la
 * langue de l'écran sans refaire ce calcul. Un client sans en-tête utile (tâche planifiée, partenaire)
 * retombe sur l'établissement.
 *
 * ⚠ Ce n'est PAS la langue d'un document : voir `Locales::ofEstablishment()`.
 */
final class RequestLocale
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly ContexteEtablissement $establishmentContext,
    ) {
    }

    public function current(): string
    {
        $request = $this->requests->getCurrentRequest();
        $asked = $request !== null ? Locales::fromAcceptLanguage($request->getLanguages()) : null;

        return $asked ?? Locales::ofEstablishment($this->establishmentContext->etablissementActif());
    }
}
