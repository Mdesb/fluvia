<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\CommercialActivity;
use App\Crm\Entity\Opportunity;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'une affaire vient de la **session serveur**, jamais du corps.
 *
 * Même patron que `App\Boutique\State\EstablishmentStampProcessor`, **recopié plutôt qu'importé** :
 * D2 interdit l'appel direct de module à module.
 *
 * **Ce qu'un champ écrivable aurait ouvert ici.** L'appelant choisirait dans quel pipeline atterrit son
 * affaire — donc où elle est comptée. Et comme le pipeline se lit par établissement, il lui suffirait
 * ensuite d'en créer une chez le voisin pour lire, en retour, **le prévisionnel commercial de ce
 * voisin**. Une donnée qu'aucun exploitant n'accepterait de partager.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class CrmEstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Meme regle pour les deux : l'appelant ne choisit pas dans quel etablissement atterrit
        // ce qu'il ecrit, sinon il lirait par la meme voie le previsionnel et les echanges d'un
        // voisin.
        if (($data instanceof Opportunity || $data instanceof CommercialActivity)
            && $data->getEstablishment() === null
        ) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                // On ne devine pas : une affaire rattachée au hasard fausserait le prévisionnel d'un
                // établissement qui ne l'a jamais ouverte.
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cette écriture (D41).',
                );
            }

            $data->setEstablishment($etablissement);
        }

        // L'AUTEUR EST CELUI QUI ECRIT, PAS CELUI QU'ON DESIGNE.
        //
        // Un champ ecrivable permettrait de noter un appel au nom d'un collegue — et six mois plus
        // tard, personne ne saurait plus qui a reellement parle au client. Une trace dont l'auteur se
        // choisit n'est plus une trace.
        $utilisateur = $this->security->getUser();
        if ($data instanceof CommercialActivity
            && $data->getAuthor() === null
            && $utilisateur instanceof Utilisateur
        ) {
            $data->setAuthor($utilisateur);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
