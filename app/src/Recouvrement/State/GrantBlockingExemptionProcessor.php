<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\BlockingExemption;
use App\Recouvrement\Service\BlockingExemptionHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /recouvrement/exemptions — « ce redevable n'est jamais bloqué ».
 *
 * Corps : { "typeRedevable": string, "referenceRedevable": string, "motif": string }.
 *
 * ⚠ LE MOTIF EST REFUSÉ VIDE ICI, ET PAS SEULEMENT EN BASE. Maxime a choisi que le droit exigé soit
 * `recouvrement.forcer_acces`, le même que le forçage, plutôt qu'un droit dédié — donc un agent de
 * caisse peut exempter un client pour toujours. Le motif écrit est le seul verrou qui reste, et un
 * verrou qui ne se manifeste qu'au moment du `flush` produit une erreur que l'écran ne sait pas
 * expliquer. Il est donc vérifié au plus près de la saisie.
 *
 * ⚠ SANS ÉTABLISSEMENT ACTIF, ON N'ÉCRIT PAS. Le sens sûr d'une erreur est celui qui restreint, et
 * doublement quand le geste modifie. Une exemption sans établissement ne serait rattachable à
 * personne dans la lecture cloisonnée : elle existerait sans que quiconque puisse la voir ni la
 * retirer.
 *
 * @implements ProcessorInterface<mixed, BlockingExemption>
 */
final class GrantBlockingExemptionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly BlockingExemptionHandler $handler,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BlockingExemption
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur non authentifié.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new AccessDeniedHttpException('Aucun établissement actif : aucune exemption n’a été posée.');
        }

        $corps = $this->lecteur->corps();
        $type = \is_string($corps['typeRedevable'] ?? null) ? trim($corps['typeRedevable']) : '';
        $reference = \is_string($corps['referenceRedevable'] ?? null) ? trim($corps['referenceRedevable']) : '';
        $motif = \is_string($corps['motif'] ?? null) ? trim($corps['motif']) : '';

        if ($type === '' || $reference === '') {
            throw new UnprocessableEntityHttpException('Il faut désigner un redevable : `typeRedevable` et `referenceRedevable` sont requis.');
        }

        if ($motif === '') {
            throw new UnprocessableEntityHttpException(
                'Le motif est obligatoire : une exemption durable sans raison écrite est indéfendable dans six mois, et c’est la seule garde de ce geste.',
            );
        }

        return $this->handler->accorder($type, $reference, $motif, $etablissement, $utilisateur);
    }
}
