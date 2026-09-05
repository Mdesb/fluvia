<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\QualificationEncadrant;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'etablissement de `Casier` et de `QualificationEncadrant` vient de la **session serveur**,
 * jamais du corps de la requete.
 *
 * ⚠ LA QUALIFICATION A ETE AJOUTEE LE 05/09 PARCE QUE SA CREATION RENDAIT 500. Son etablissement
 * est `NOT NULL` en base et hors du groupe d'ecriture, et rien ne le posait : tout
 * `POST /api/qualification_encadrants` mourait au `flush` sur
 * « Column 'etablissement_id' cannot be null ». Observe en executant la route
 * (`SupervisorQualificationCreationTest`), pas deduit de sa forme.
 *
 * Ce n'etait pas cosmetique : c'etait la SEULE issue au refus RG-PISC-02.
 * `ValiderCreneauBassinHandler` refuse de valider un creneau exigeant un encadrement sans
 * affectation qualifiee a diplome valide — et le message « aucun encadrant qualifie a diplome
 * valide affecte a ce creneau » n'avait donc aucune sortie.
 *
 * ⚠ ET LES DEUX AUTRES ENTITES DU MODULE QUI PORTENT UN ETABLISSEMENT N'EN ONT PAS BESOIN : `Bassin`
 * et `Poss` le DERIVENT de l'espace qu'on leur donne (`setEspace()`, `setEspaceAcces()`). Verifie en
 * executant `POST /api/bassins`, qui rend bien 201. La ressemblance de forme entre les trois etait
 * trompeuse — une qualification, elle, n'a aucun parent porteur : son seul candidat serait
 * l'encadrant, or un `Utilisateur` peut etre affecte a plusieurs etablissements.
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
        if (($data instanceof Casier || $data instanceof QualificationEncadrant)
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
