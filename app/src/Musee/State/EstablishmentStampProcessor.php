<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Musee\Entity\Guide;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'etablissement de `Guide` vient de la **session serveur**, jamais du corps de la requete.
 *
 * Repris de `App\Reservation\State\EstablishmentStampProcessor`, y compris ses deux enseignements, qui
 * ne sont pas devinables : la validation s'execute **entre** la deserialisation et l'ecriture, donc
 * poser l'etablissement dans un `provider` ne marche pas — une operation `Post` est en `read: false`
 * et ne consulte aucun fournisseur — et un `Assert\NotNull` reste sur le champ ferait echouer la
 * creation en 422 avant que ce service ne soit atteint. L'assertion est donc retiree du champ.
 *
 * Ce qu'elle protegeait — un client qui **omettait** l'etablissement — n'existe plus : il ne peut plus
 * l'envoyer du tout. Restent trois protections plus solides : ce service, qui refuse plutot que de
 * deviner ; la colonne `NOT NULL` ; et le garde global de D41.
 *
 * **Ce que cela change pour l'appelant : rien de legitime.** Il envoyait l'etablissement de son propre
 * contexte, exactement celui qu'on lui pose desormais. Ce qui disparait, c'est la possibilite d'en
 * envoyer un autre.
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
        if ($data instanceof Guide
            && $data->getEtablissement() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException(
                    'Aucun etablissement actif : impossible de rattacher cette creation (D41).'
                );
            }
            $data->setEtablissement($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
