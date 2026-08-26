<?php

declare(strict_types=1);

namespace App\Platform\Scoping;

/**
 * Portée d'une ligne de référentiel : le **socle** livré par la plateforme, ou l'**ajout local** d'un
 * établissement (D51).
 *
 * **Pourquoi un discriminant explicite et non un `etablissement` nul.** D51 laissait la forme ouverte
 * et suggérait `null = socle`. Un `null` porterait alors **deux sens** : « cette ligne appartient au
 * socle » et « personne n'a encore renseigné l'établissement ». Le second arrive tout seul — un
 * import, un processeur qui oublie l'estampille, une migration qui ajoute la colonne. Une ligne locale
 * mal remplie deviendrait donc du socle, **visible par tous les établissements, en silence**.
 *
 * Avec `Local` et un établissement manquant, la ligne n'est visible de personne : ça se remarque et se
 * corrige. **Le défaut par défaut ne fuit pas.**
 *
 * Ce n'est pas un patron neuf : `Support\Enum\PorteeArticle` fait déjà exactement cela pour les
 * articles d'aide, avec son extension Doctrine. D51 demandait de vérifier qu'un patron n'existait pas
 * ailleurs avant d'en inventer un — il existait, et celui-ci le généralise plutôt qu'il ne le double.
 */
enum ReferenceScope: string
{
    /** Livré et maintenu par la plateforme. Lisible par tous, écrivable par personne d'autre. */
    case Base = 'socle';

    /** Ajouté par un établissement, visible de lui seul. */
    case Local = 'local';
}
