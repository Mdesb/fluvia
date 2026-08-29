<?php

declare(strict_types=1);

namespace App\Subscription\Security;

use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Le contrôle d'accès de toute l'administration éditeur, en un seul endroit (ED-6, RG-ED-01).
 *
 * **Une identité de tenant, pas une permission.** Une permission se délègue, s'hérite, se recopie
 * dans un rôle modèle et finit par atterrir chez quelqu'un qu'on n'avait pas prévu — c'est la forme
 * exacte des seize IDOR trouvés ici. L'appartenance au tenant éditeur, elle, ne se recopie pas :
 * l'établissement actif de la session est l'éditeur, ou il ne l'est pas.
 *
 * **Et depuis le 29/08, une permission EN PLUS — pas à la place.** L'affirmation ci-dessus tient :
 * l'appartenance au tenant reste une identité, et c'est bien pour cela qu'elle passe en premier. Ce
 * qui s'ajoute répond à une autre question. L'identité dit « vous êtes dans la maison » ; la
 * permission dit « quelles pièces ». Un employé de l'éditeur est chez lui sans être autorisé
 * partout : un agent d'assistance n'a pas à lire la facturation des abonnements.
 *
 * **404 et non 403, et c'est pourquoi l'ordre compte.** Un 403 confirme à un client curieux que cet
 * écran existe et qu'il concerne l'éditeur ; un 404 ne lui apprend rien. Même discipline que le
 * reste du cloisonnement (D3). La permission, elle, refuse en 403 — et c'est juste, puisque celui
 * qui le reçoit est déjà dans la maison. Poser la permission avant l'identité rendrait 403 à
 * l'inconnu : c'est ce qu'une première version faisait, en la posant dans `security:`, qu'API
 * Platform évalue avant le provider.
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
        private readonly Security $securite,
    ) {
    }

    /**
     * ⚠ DEUX CONTRÔLES, ET L'ORDRE EST LA MOITIÉ DU SUJET.
     *
     * L'identité du tenant d'abord — elle dit « vous êtes dans la maison », et son refus est un 404
     * qui n'apprend rien. La permission ensuite — elle dit « quelles pièces », et son refus est un
     * 403, ce qui est juste : celui qui le reçoit est déjà chez lui, lui apprendre qu'une pièce
     * existe ne lui apprend rien qu'il ignore.
     *
     * Inverser les deux ferait recevoir un 403 à un exploitant curieux, donc lui confirmerait que
     * l'écran existe et qu'il concerne l'éditeur. C'est ce qu'une première version faisait, en
     * posant la garde dans `security:` — API Platform l'évalue avant le provider, donc avant ce
     * contrôle-ci. Les tests l'ont dit en « 403 au lieu de 404 ».
     *
     * @param string|null $permission code exigé À L'INTÉRIEUR du tenant éditeur ; `null` quand la
     *                                seule appartenance suffit
     *
     * @throws NotFoundHttpException  si la session n'est pas celle de l'éditeur
     * @throws AccessDeniedHttpException si elle l'est mais que le droit manque
     */
    public function assertEditor(?string $permission = null): void
    {
        if (!$this->editorTenant->isEditor($this->contexte->etablissementActif())) {
            throw new NotFoundHttpException();
        }

        if (null !== $permission && !$this->securite->isGranted('PERM', $permission)) {
            throw new AccessDeniedHttpException(sprintf(
                'Cet écran de l’administration éditeur demande le droit « %s », que votre profil ne porte pas.',
                $permission,
            ));
        }
    }
}
