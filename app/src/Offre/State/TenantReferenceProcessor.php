<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Référentiels **entièrement cloisonnés** : saisons et tranches de quotient familial (D51).
 *
 * **Ils n'ont pas de socle, et c'est la décision.** Une saison est décidée par l'exploitant ; une
 * tranche de quotient familial est fixée par la commune ou la CAF. Deux établissements de deux
 * communes ont des grilles différentes, et **un tarif calculé sur la mauvaise grille est une erreur de
 * facturation opposable**. D51 le dit sans détour : *il n'y a pas d'arbitrage à rendre.*
 *
 * D'où l'absence de `portee` ici : il n'y a rien à discriminer, tout est local. L'établissement est
 * **estampillé depuis le contexte serveur**, jamais lu du corps de la requête — c'est D3/D8, et c'est
 * la seule raison pour laquelle un appelant ne peut pas déposer sa tranche de quotient chez le voisin.
 *
 * Une ligne sans établissement — les lignes créées avant ce cloisonnement — n'est visible de
 * **personne**. C'est voulu : la migration les laisse orphelines plutôt que de leur inventer un
 * propriétaire. Une donnée manquante reste visiblement manquante ; une donnée fausse ne se voit pas.
 *
 * @implements ProcessorInterface<object, object|null>
 */
final class TenantReferenceProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $remove,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($operation instanceof DeleteOperationInterface) {
            return $this->remove->process($data, $operation, $uriVariables, $context);
        }

        if ($operation instanceof Post) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                // Fermeture par défaut : sans établissement actif on ne crée rien, plutôt qu'une ligne
                // orpheline que personne ne verrait et que son auteur croirait enregistrée.
                throw new UnprocessableEntityHttpException('Établissement actif requis pour créer cette entrée de référentiel.');
            }
            $data->setEtablissement($etablissement);
        }

        // Modification : rien à estampiller. Une ligne d'un autre établissement n'arrive jamais ici —
        // l'extension de lecture l'a déjà écartée, et l'opération rend 404 avant d'atteindre ce point.
        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
