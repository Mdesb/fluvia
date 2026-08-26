<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Entity\Fournisseur;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Entity\ReceptionAchat;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'une entité `Stock` créée par l'API vient de la **session serveur**, jamais
 * du corps de la requête. Même patron que `App\Reservation\State\EstablishmentStampProcessor`
 * (`claude-G`), recopié plutôt qu'importé : D2 interdit l'appel direct de module à module.
 *
 * **Cinq entités sur cinq étaient ouvertes** — article, commande d'achat, fournisseur, paramétrage,
 * réception. Ce n'est pas un oubli isolé mais l'idiome du module tel qu'il a été écrit, ce qui est
 * précisément ce qui rend la famille dangereuse : la corriger entité par entité, au fil des lots,
 * laisse toujours la dernière ouverte.
 *
 * **Ce que ça valait ici, concrètement.** Le stock est ce qui sort quand un client consomme. Un
 * appelant capable de rattacher un article ou une réception à l'établissement de son choix pouvait
 * faire entrer de la marchandise dans un stock qui n'est pas le sien, puis l'en faire sortir. La
 * lecture était cloisonnée ; l'écriture désignait sa propre frontière.
 *
 * **Où ce code s'exécute.** La validation tourne entre la désérialisation et l'écriture : un
 * `Assert\NotNull` sur le champ échoue en 422 avant que ce processor ne soit atteint — constat de
 * `claude-G`, payé de deux essais, que je ne refais pas. Les assertions sont donc retirées en même
 * temps que les groupes d'écriture. L'invariant reste tenu par ce service, qui refuse plutôt que de
 * deviner, par la colonne `NOT NULL`, et par le garde global D41.
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
        $concerne = $data instanceof ArticleStock
            || $data instanceof CommandeAchat
            || $data instanceof Fournisseur
            || $data instanceof ParametrageStock
            || $data instanceof ReceptionAchat;

        if ($concerne && null === $data->getEtablissement()) {
            $etablissement = $this->contexte->etablissementActif();
            if (null === $etablissement) {
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cette création (D41).',
                );
            }

            $data->setEtablissement($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
