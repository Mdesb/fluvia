<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'un segment vient de la **session serveur**, jamais du corps.
 *
 * Même patron que les estampilleurs des autres modules, **recopié plutôt qu'importé** : D2 interdit
 * l'appel direct de module à module.
 *
 * **Ce qu'un champ écrivable aurait ouvert ici, et c'est pire qu'ailleurs.** Un segment est une
 * *description de clients*. Laisser l'appelant choisir l'établissement de rattachement lui
 * permettrait de créer un segment au nom d'un voisin, puis d'en lire l'aperçu — c'est-à-dire de
 * compter, et d'échantillonner, la clientèle de ce voisin.
 *
 * Le cloisonnement de la RÉSOLUTION (`SegmentResolver`) l'en empêcherait de toute façon : il borne
 * les clients au périmètre de l'appelant, pas à l'établissement du segment. Mais deux verrous valent
 * mieux qu'un quand ce qui est en jeu est un fichier de clients.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class MarketingEstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // ── POURQUOI CE N'EST PLUS « SI C'EST UN SEGMENT » ───────────────────────────────────
        //
        // Ça l'était jusqu'au 28/08, et `Campaign` utilisait pourtant le même processeur : son
        // établissement restait donc nul sur une colonne `NOT NULL`, et créer une campagne depuis
        // l'écran répondait 500. Aucun test ne l'avait vu — le seul qui poste une campagne attend
        // un refus de validation, et n'atteint jamais l'enregistrement.
        //
        // La règle porte désormais sur la CAPACITÉ et non sur une classe nommée : toute entité du
        // module qui sait recevoir un établissement en reçoit un. Celle qu'on ajoutera demain est
        // tamponnée sans que personne ait à s'en souvenir.
        if ($this->sePrendUnEtablissement($data) && $data->getEstablishment() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                // On ne devine pas : un objet rattaché au hasard apparaîtrait dans la liste d'un
                // établissement qui ne l'a jamais créé.
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cet enregistrement (D41).',
                );
            }

            $data->setEstablishment($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }

    /**
     * L'objet appartient-il au module et sait-il porter un établissement ?
     *
     * Le contrôle de namespace n'est pas décoratif : sans lui, ce processeur tamponnerait n'importe
     * quelle entité qu'on lui passerait, y compris celles dont un autre module tient le
     * rattachement sur un autre axe.
     */
    private function sePrendUnEtablissement(mixed $data): bool
    {
        return \is_object($data)
            && str_starts_with($data::class, 'App\\Marketing\\Entity\\')
            && method_exists($data, 'getEstablishment')
            && method_exists($data, 'setEstablishment');
    }
}
