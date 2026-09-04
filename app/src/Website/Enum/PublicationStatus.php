<?php

declare(strict_types=1);

namespace App\Website\Enum;

/**
 * L'état de publication d'un article (ED-10).
 *
 * **Deux états seulement, et la date fait le troisième.** « Planifié » n'est pas un état : c'est un
 * article `Published` dont la date de publication est dans le futur. Un troisième cas d'énumération
 * aurait demandé quelqu'un ou quelque chose pour le faire basculer à l'heure dite — donc une tâche
 * planifiée de plus, et un article qui reste invisible le jour où elle ne tourne pas. Ici, le temps
 * suffit : la lecture publique compare la date à maintenant, et personne n'a rien à déclencher.
 */
enum PublicationStatus: string
{
    /** Écrit, jamais servi au public, quelle que soit la date. */
    case Draft = 'draft';

    /** Servi au public **à partir de** sa date de publication. */
    case Published = 'published';
}
