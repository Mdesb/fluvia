<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Promotion;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Défaut de rattachement d'une `Promotion` créée sans site : l'établissement actif.
 *
 * **Le piège qu'il ferme.** Depuis le cloisonnement de `Promotion`, la lecture se fait par jointure
 * interne : une promotion rattachée à zéro établissement est invisible **pour tout le monde**, y
 * compris son auteur, qui vient pourtant de la créer avec succès. Sans ce défaut, l'oubli du champ
 * produit une ressource fantôme et un 201 rassurant.
 *
 * **Ce n'est pas D41 et il ne faut pas les confondre.** D41 retire au client le droit de choisir
 * l'établissement — c'est le cas de `Reservation\Ressource`, dont le champ a quitté le groupe
 * d'écriture. Ici, le choix des sites reste **légitimement** au client : une promotion porte sur des
 * produits, et un produit est commercialisé site par site, parfois sur plusieurs. On ne lui retire
 * rien ; on lui donne un défaut sensé quand il ne dit rien. Le garde global de D41 continue par
 * ailleurs de refuser tout site hors de son périmètre.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class PromotionScopeStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Promotion && $data->getEtablissements()->isEmpty()) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : précisez au moins un site pour cette promotion.'
                );
            }
            $data->addEtablissement($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
