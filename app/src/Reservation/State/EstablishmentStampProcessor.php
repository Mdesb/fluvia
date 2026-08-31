<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Ressource;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'une entité créée par l'API vient de la **session serveur**, jamais du corps
 * de la requête.
 *
 * `Activite`, `RegleAnnulation` et `Ressource` acceptaient `etablissement` en écriture : le client
 * choisissait donc à quel établissement rattacher ce qu'il créait. Un garde global refuse désormais
 * ces écritures hors périmètre — mais un champ qu'il faut garder n'aurait jamais dû être ouvert.
 * Le garde protège ; la conception doit aussi être juste.
 *
 * **Ou ce code s'execute, et les deux essais qu'il a fallu.** La validation s'execute **entre** la
 * deserialisation et l'ecriture. Un premier essai posait l'etablissement dans un `processor` :
 * trop tard, l'`Assert\NotNull` du champ echouait en 422 avant qu'il ne soit atteint. Un second
 * essai le posait dans un `provider` : jamais appele, parce qu'une operation `Post` a `read: false`
 * et que le fournisseur d'une operation n'est consulte qu'a la lecture. Les deux ont ete constates,
 * pas supposes.
 *
 * Retenu : le `processor`, et l'`Assert\NotNull` retiree du champ. Elle protegeait d'un client qui
 * **omettait** l'etablissement ; il ne peut desormais plus l'envoyer du tout, donc elle ne protegeait
 * plus que d'une erreur du serveur — et de celle-la, trois choses plus solides s'occupent : ce
 * service, qui refuse plutot que de deviner ; la colonne `NOT NULL` ; et le garde global D41.
 *
 * **Ce que cela change pour l'appelant : rien de légitime.** Il envoyait l'établissement de son
 * propre contexte, exactement celui qu'on lui pose désormais. Ce qui disparaît, c'est la possibilité
 * d'en envoyer un autre.
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
        if (($data instanceof Activite || $data instanceof RegleAnnulation || $data instanceof Ressource)
            && $data->getEtablissement() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                // Sans contexte d'établissement, on ne devine pas : mieux vaut refuser que rattacher
                // au hasard une ressource qui gouvernera ensuite le cloisonnement de tout ce qui s'y
                // accroche — créneaux, réservations, disponibilités.
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cette création (D41).'
                );
            }
            $data->setEtablissement($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
