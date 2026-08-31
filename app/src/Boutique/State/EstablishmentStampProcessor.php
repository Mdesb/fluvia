<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Service\VitrineResolver;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'une entité `Boutique` créée par l'API vient de la **session serveur**,
 * jamais du corps de la requête. Même patron que
 * `App\Reservation\State\EstablishmentStampProcessor` (`claude-G`), recopié plutôt qu'importé : D2
 * interdit l'appel direct de module à module.
 *
 * **Les deux entités visées n'avaient pas le même défaut, et c'est la découverte de ce lot.**
 *
 * `Vitrine` exposait `etablissement` dans `vitrine:write` : l'appelant choisissait à quel
 * établissement rattacher sa vitrine — un point d'entrée **public** (`PUBLIC_ACCESS` en lecture),
 * ce qui rend le champ d'autant moins acceptable en écriture.
 *
 * `PartenaireOTA`, lui, ne l'exposait **nulle part** — et rien ne le posait non plus, alors que la
 * colonne est `NOT NULL` et qu'aucun processor n'était branché sur son `Post`. Ce n'était donc pas
 * une faille de périmètre mais une création **impossible** : toute tentative finissait en violation
 * d'intégrité. Le même estampilleur répare les deux, pour la même raison de fond — l'établissement
 * d'une création est une donnée du serveur, et le serveur doit la poser.
 *
 * **Où ce code s'exécute.** La validation tourne entre la désérialisation et l'écriture : un
 * `Assert\NotNull` sur le champ échouerait en 422 avant que ce processor ne soit atteint. C'est le
 * constat que `claude-G` a payé de deux essais ; je ne le refais pas, je retire l'assertion de
 * `Vitrine` en même temps que le groupe d'écriture. L'invariant reste tenu par trois choses plus
 * solides : ce service, qui refuse plutôt que de deviner ; la colonne `NOT NULL` ; et le garde global
 * D41.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class EstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
        private readonly VitrineResolver $resolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (($data instanceof Vitrine || $data instanceof PartenaireOTA) && null === $data->getEtablissement()) {
            $etablissement = $this->contexte->etablissementActif();
            if (null === $etablissement) {
                // On ne devine pas : une vitrine rattachée au hasard gouvernerait ensuite le
                // cloisonnement de tout ce qui s'y accroche — catalogue, paniers, ventes en ligne.
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cette création (D41).',
                );
            }

            $data->setEtablissement($etablissement);
        }

        // UNE VITRINE NEUVE RECOIT UNE ADRESSE LISIBLE, SANS QUE PERSONNE N'AIT A Y PENSER.
        //
        // Laisser le champ vide jusqu'a ce que l'exploitant le remplisse produirait exactement l'etat
        // qu'on corrige : une boutique en ligne dont la seule URL connue porte un UUID. Le defaut est
        // fabrique depuis le nom de l'etablissement, et reste modifiable -- c'est son adresse.
        if ($data instanceof Vitrine && ($data->getSlug() === null || trim($data->getSlug()) === '')) {
            $data->setSlug($this->resolver->slugLibre($data->getEtablissement()?->getNom() ?? 'boutique', $data));
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
