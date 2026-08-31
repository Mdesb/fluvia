<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\GardeDernierAdministrateur;
use App\Securite\Service\RoleAPrivileges;
use App\Securite\Service\VerificateurPlafondDroits;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Garde de l'`Affectation` (§2.6/§2.7 plan-backoffice.md) :
 * - **Post** : plafond d'attribution (RG-M8-09, CA-10) — l'auteur ne peut affecter que des droits
 *   ≤ aux siens sur l'établissement cible ; garde MFA (RG-M8-06, CA-4) — refuse (422) l'affectation
 *   à un rôle à privilèges si `beneficiaire.mfaActif === false`.
 * - **Delete** : garde dernier administrateur (RG-M8-07, CA-11).
 *
 * @implements ProcessorInterface<Affectation, Affectation|null>
 */
final class AffectationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Affectation, Affectation> $persistProcessor
     * @param ProcessorInterface<Affectation, null>         $removeProcessor
     */
    /**
     * La garde MFA sur l'affectation d'un rôle à privilèges — voir son explication dans `process()`.
     *
     * ⚠ `false` DEPUIS LE 31/08, DÉCISION DE MAXIME. Une constante plutôt qu'un bloc commenté :
     * un contrôle mis en commentaire disparaît de la lecture, du diff et de la recherche, et
     * personne ne sait plus qu'il a existé. Celui-ci reste compilé, lisible, et se rétablit en
     * remettant `true`.
     */
    private const MFA_EXIGE_POUR_ROLE_A_PRIVILEGES = false;

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
        private readonly GardeDernierAdministrateur $gardeDernierAdmin,
        private readonly VerificateurPlafondDroits $plafond,
        private readonly RoleAPrivileges $roleAPrivileges,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Affectation);

        if ($operation instanceof DeleteOperationInterface) {
            $this->gardeDernierAdmin->verifierSuppressionAffectation($data);

            return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
        }

        $role = $data->getRole();
        $etablissement = $data->getEtablissement();
        $beneficiaire = $data->getUtilisateur();

        if ($role !== null && $etablissement !== null) {
            $auteur = $this->security->getUser();
            if ($auteur instanceof Utilisateur) {
                $this->plafond->verifier($auteur, $etablissement, $role->getPermissions());
            }
        }

        // ── ⚠ GARDE SUSPENDUE LE 31/08, PAS SUPPRIMÉE ─────────────────────────────────────────
        //
        // **Ce qu'elle exigeait** : un rôle à privilèges — celui qui porte une permission du module
        // `securite` ou le joker `*` — ne s'affecte qu'à un bénéficiaire dont le MFA est actif.
        // Écrite délibérément, et bonne dans son principe.
        //
        // **Pourquoi elle est en attente.** Aucun écran n'active le MFA. Les points d'entrée
        // serveur existent et fonctionnent — `MfaTest` les emprunte pour activer puis affecter —
        // mais l'interface ne les appelle nulle part. Mesuré le 31/08 : 6 rôles à privilèges,
        // 0 utilisateur avec MFA actif, et `grep -i mfa frontend/src` ne rend que deux affichages
        // en lecture seule dans les paramètres.
        //
        // Conséquence exacte : **on ne peut nommer aucun administrateur, chez aucun client**, et le
        // message d'erreur demandait de faire une chose que personne ne pouvait faire. Le joker
        // couvre même « Lecture seule », qui porte `*.lire`.
        //
        // ⚠ **ET LE CONTOURNEMENT ÉVIDENT EST LE PIRE CHEMIN.** Activer le MFA par l'API pour
        // satisfaire la garde enfermerait le compte dehors : le serveur répond alors
        // `{mfaRequis: true, jetonPreAuth}` et l'écran de connexion ne sait pas relever ce défi.
        //
        // **Décision de Maxime du 31/08**, entre trois voies — lever puis construire, tout
        // construire d'abord, ou retirer définitivement : lever maintenant, construire ensuite.
        // Relayée par allaccess-b8, qui la lui a posée.
        //
        // **À RÉTABLIR quand le parcours MFA existe côté écran** : activation, confirmation, et le
        // second facteur à la connexion. Remettre `true` ci-dessous suffit — c'est tout ce qu'il y
        // aura à faire, et `MfaTest::testCa4GardeMfaSuspendueEnAttenteDUnEcran` porte le test à
        // remettre dans l'autre sens.
        //
        // ⚠ **CE QUI N'EST PAS LEVÉ** : l'exigence du second facteur À LA CONNEXION. Un compte qui
        // porte `mfaActif` — aucun aujourd'hui, mais un semis ou un appel d'API peut en créer —
        // continue d'être mis au défi par le serveur. Ce qui est suspendu est la PRÉCONDITION à
        // l'affectation, pas la VÉRIFICATION à l'entrée.
        if (self::MFA_EXIGE_POUR_ROLE_A_PRIVILEGES
            && $role !== null && $beneficiaire !== null
            && $this->roleAPrivileges->estAPrivileges($role) && !$beneficiaire->isMfaActif()
        ) {
            throw new UnprocessableEntityHttpException(
                "Ce rôle est à privilèges et exige le MFA : activez le MFA du bénéficiaire avant de l'affecter."
            );
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
