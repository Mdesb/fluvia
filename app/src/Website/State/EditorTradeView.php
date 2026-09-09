<?php

declare(strict_types=1);

namespace App\Website\State;

use App\Fonctionnalite\Config\ActivityCapabilities;
use App\Fonctionnalite\Config\PresetVerticale;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Website\ApiResource\EditorTrade;
use App\Website\Entity\Trade;

/**
 * Une ligne de métier, telle que l'écran d'administration la voit.
 *
 * ⚠ **UNE SEULE FOIS, POUR LES DEUX CÔTÉS.** Le fournisseur la rend en lecture, et le processeur la
 * rend après écriture — parce qu'on relit ce qui a été rangé plutôt que de renvoyer ce qui a été
 * reçu. Deux copies divergeraient au premier ajustement, et l'écran montrerait une chose après
 * enregistrement et une autre au rechargement.
 */
final readonly class EditorTradeView
{
    public function __construct(private CatalogueCapacites $capacites)
    {
    }

    public function depuis(Trade $ligne): EditorTrade
    {
        $vue = new EditorTrade();
        $vue->id = $ligne->getId()->toRfc4122();
        $vue->code = $ligne->getCode();
        $vue->slug = $ligne->getSlug();
        $vue->name = $ligne->getName();
        $vue->searchTitle = $ligne->getSearchTitle();
        $vue->lead = $ligne->getLead();
        $vue->position = $ligne->getPosition();
        $vue->status = $ligne->getStatus()->value;

        $activites = [];

        foreach ($ligne->getActivities() as $activite) {
            $activites[] = $activite->getActivity();
        }

        $vue->activities = array_map(static fn (\App\Fonctionnalite\Enum\EstablishmentActivity $a): string => $a->value, $activites);

        /*
         * ⚠ LES MODULES SONT CALCULÉS PAR LA MÊME RÈGLE QUE LA PAGE PUBLIQUE, mot pour mot celle de
         *   `MetierCatalog::depuisLaLigne()`. C'est la seule forme qui vaille : un écran qui
         *   montrerait une liste calculée autrement laisserait quelqu'un cocher des activités en
         *   croyant allumer des modules qui ne s'allumeraient pas sur la page qu'il vend.
         */
        $metier = Metier::tryFrom($ligne->getCode());
        $vue->builtIn = null !== $metier;

        $capacites = null !== $metier
            ? PresetVerticale::capacites($metier)
            : ActivityCapabilities::modulesFor($activites);

        $libelles = [];

        foreach ($capacites as $capacite) {
            $descripteur = $this->capacites->trouve($capacite);

            // Une capacité absente du catalogue serait une incohérence interne ; on la saute plutôt
            // que d'afficher un code technique dans un écran d'administration.
            if (null !== $descripteur && !$descripteur->estVerticale) {
                $libelles[] = $descripteur->libelle;
            }
        }

        sort($libelles);
        $vue->modules = $libelles;

        return $vue;
    }
}
