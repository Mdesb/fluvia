<?php

declare(strict_types=1);

namespace App\Subscription\Security;

use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Le contrôle d'accès de toute l'administration éditeur, en un seul endroit (ED-6, RG-ED-01).
 *
 * **Une identité de tenant, pas une permission.** Une permission se délègue, s'hérite, se recopie
 * dans un rôle modèle et finit par atterrir chez quelqu'un qu'on n'avait pas prévu — c'est la forme
 * exacte des seize IDOR trouvés ici. L'appartenance au tenant éditeur, elle, ne se recopie pas :
 * l'établissement actif de la session est l'éditeur, ou il ne l'est pas.
 *
 * **404 et non 403.** Un 403 confirme à un client curieux que cet écran existe et qu'il concerne
 * l'éditeur ; un 404 ne lui apprend rien. Même discipline que le reste du cloisonnement (D3).
 *
 * **Un seul endroit, parce qu'une règle recopiée diverge.** Lecture des offres, écriture des offres,
 * liste des abonnements : trois chemins, un seul contrôle. Le jour où il change — délégation à un
 * partenaire, second tenant éditeur — il change une fois.
 */
final class EditorOnly
{
    public function __construct(
        private readonly ContexteEtablissement $contexte,
        private readonly EditorTenantResolver $editorTenant,
    ) {
    }

    /** @throws NotFoundHttpException si la session n'est pas celle de l'éditeur */
    public function assertEditor(): void
    {
        if (!$this->editorTenant->isEditor($this->contexte->etablissementActif())) {
            throw new NotFoundHttpException();
        }
    }
}
