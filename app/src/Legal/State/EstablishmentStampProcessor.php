<?php

declare(strict_types=1);

namespace App\Legal\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Legal\Entity\LegalDocument;
use App\Legal\Entity\LegalIdentity;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'un document légal vient de la **session serveur**, jamais du corps.
 *
 * Même patron que `App\Boutique\State\EstablishmentStampProcessor`, **recopié plutôt qu'importé** :
 * D2 interdit l'appel direct de module à module.
 *
 * **Ce que le garde-fou a évité ici, et ce n'est pas théorique.** Ma première version exposait
 * `establishment` dans les groupes d'écriture, et l'écran envoyait l'IRI de l'établissement actif.
 * Ça marchait. Mais l'appelant choisissait alors **de qui sont les mentions légales** : n'importe
 * quel gestionnaire pouvait publier des CGV sur la boutique d'un autre exploitant — c'est-à-dire
 * écrire, sous le nom d'un tiers, le document qui l'engage envers ses clients.
 *
 * > **Le pire endroit où laisser choisir l'établissement, c'est celui qui produit un texte opposable.**
 *
 * **L'assertion `NotNull` a été retirée en même temps, et il faut savoir pourquoi.** La validation
 * tourne **entre** la désérialisation et l'écriture : un `Assert\NotNull` sur le champ échouerait en
 * 422 avant que ce processeur ne soit atteint. Constat déjà payé par `claude-G` sur `Vitrine` ; on ne
 * le refait pas. L'invariant reste tenu par trois choses plus solides : ce service, qui **refuse
 * plutôt que de deviner** ; la colonne `NOT NULL` ; et le garde global D41.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class EstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (($data instanceof LegalIdentity || $data instanceof LegalDocument)
            && $data->getEstablishment() === null
        ) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                // On ne devine pas. Un document rattaché au hasard s'afficherait sur la boutique d'un
                // autre exploitant, et il l'engagerait.
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher ce document (D41).',
                );
            }

            $data->setEstablishment($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
