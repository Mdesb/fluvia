<?php

declare(strict_types=1);

namespace App\Dms\Security;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Défense en profondeur (RG-DMS-02/03, D8) — copie du patron
 * `App\Stock\Security\PerimetreEtablissementVerificateur` (pas d'appel direct : `App\Dms` est
 * transverse, `dependencies(): []`, n'importe jamais `App\Stock`).
 *
 * **Différence délibérée avec le patron copié** : `verify()` lève **`NotFoundHttpException` (404)**,
 * jamais `AccessDeniedHttpException` (403) — RG-DMS-03 exige l'indiscernabilité « existe mais interdit »
 * vs « n'existe pas » (CA-2), contrairement à `PerimetreEtablissementVerificateur` (Stock) qui répond
 * 403. Appelé explicitement au début de chaque processor/contrôleur non-`GetCollection`/`Get`, y
 * compris quand l'opération API Platform a déjà `read: true` (pas de confiance implicite dans le
 * provider par défaut).
 */
final class DmsScopeGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function isInScope(?Etablissement $etablissement): bool
    {
        if ($etablissement === null) {
            return false;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $idActif = $this->contexte->idActif();
        if ($idActif !== null && $idActif->equals($etablissement->getId())) {
            return true;
        }

        return $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId()) !== [];
    }

    /**
     * @throws NotFoundHttpException si l'établissement est absent ou hors du périmètre de l'appelant —
     *                                réponse **404 uniforme**, jamais 403 (RG-DMS-03, CA-2).
     */
    public function verify(
        ?Etablissement $etablissement,
        string $message = 'dms.error.document_not_found',
    ): Etablissement {
        if (!$this->isInScope($etablissement)) {
            throw new NotFoundHttpException($message);
        }

        return $etablissement;
    }
}
