<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Scoping\ReferenceScope;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Un référentiel « socle + ajout local » : **l'établissement ajoute, il ne modifie pas le socle**
 * (D51, D41).
 *
 * **Ce que ce processeur empêche, et ce n'est pas théorique.** Une table partagée que n'importe quel
 * établissement peut écrire est un trou transfrontière : A renomme un `TypeTarif`, **et le tarif de B
 * change**. Silencieux et immédiat, sans qu'aucune requête de A ne mentionne B. Garder une liste
 * partagée engage donc à la rendre non écrivable par un établissement — sans quoi « partagé » signifie
 * « modifiable par tout le monde ».
 *
 * **Deux gestes, deux traitements :**
 * - **Créer** — la ligne naît **locale**, rattachée à l'établissement actif, du même geste
 *   (`rattacherA()` pose les deux champs ensemble, il n'y a pas de chemin qui n'en pose qu'un).
 *   Quiconque a `offre.gerer_socle` crée au contraire dans le socle, et c'est la plateforme.
 * - **Modifier ou supprimer une ligne du socle** — refusé, **en 404 et non en 403**. La ligne est
 *   visible de tous par construction, donc un 403 n'apprendrait rien sur son existence ; mais un 404
 *   dit la seule chose vraie du point de vue de l'appelant : *cette ligne-là n'est pas à vous*. Une
 *   ligne locale d'un autre établissement n'arrive de toute façon jamais ici — l'extension de lecture
 *   l'a déjà écartée.
 *
 * @implements ProcessorInterface<object, object|null>
 */
final class LocalReferenceProcessor implements ProcessorInterface
{
    /**
     * Le droit de la plateforme sur le socle.
     *
     * **Il ne vit pas sous `offre.*`, et c'est le test qui l'a imposé.** L'administrateur d'un groupe
     * porte la permission joker `offre.*` — un `offre.gerer_socle` lui aurait donc été accordé
     * *automatiquement*, et chaque établissement se serait retrouvé maître du socle commun sans que
     * personne ne l'ait décidé. Le joker d'un module ne doit jamais pouvoir contenir un droit qui
     * dépasse ce module.
     *
     * Personne ne le détient aujourd'hui : le socle est semé par les jeux de données et les
     * migrations. C'est volontaire — le jour où la plateforme voudra l'éditer par l'API, il faudra
     * décider **à qui** on donne ce droit, et ce n'est pas une décision à prendre par défaut.
     */
    public const DROIT_SOCLE = 'plateforme.gerer_socle';

    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $remove,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $securite,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $plateforme = $this->securite->isGranted('PERM', self::DROIT_SOCLE);

        if ($operation instanceof DeleteOperationInterface) {
            $this->refuserSurLeSocle($data, $plateforme);

            return $this->remove->process($data, $operation, $uriVariables, $context);
        }

        if ($operation instanceof Post) {
            if (!$plateforme) {
                $etablissement = $this->contexte->etablissementActif();
                if ($etablissement === null) {
                    throw new UnprocessableEntityHttpException('Établissement actif requis pour ajouter au référentiel.');
                }
                $data->rattacherA($etablissement);
            }

            return $this->persist->process($data, $operation, $uriVariables, $context);
        }

        // Modification : on interroge l'état **en base** et non l'objet reçu. Le second a déjà été
        // hydraté depuis le corps de la requête ; s'y fier laisserait un appelant se déclarer local
        // pour obtenir le droit de modifier une ligne du socle.
        $this->refuserSurLeSocle($this->em->getUnitOfWork()->getOriginalEntityData($data), $plateforme);

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }

    private function refuserSurLeSocle(mixed $etat, bool $plateforme): void
    {
        if ($plateforme) {
            return;
        }

        $portee = \is_array($etat) ? ($etat['portee'] ?? null) : (\is_object($etat) && method_exists($etat, 'getPortee') ? $etat->getPortee() : null);

        if ($portee === ReferenceScope::Base || $portee === ReferenceScope::Base->value) {
            throw new NotFoundHttpException('Cette entrée appartient au socle : elle n\'est pas modifiable depuis un établissement.');
        }
    }
}
