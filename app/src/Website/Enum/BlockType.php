<?php

declare(strict_types=1);

namespace App\Website\Enum;

/**
 * La forme d'un bloc de contenu de la page d'accueil (ED-10).
 *
 * **Le type est déclaré par le gabarit, pas choisi par le rédacteur.** C'est le gabarit Twig qui sait
 * s'il attend une phrase, une liste ou quatre cartes : lui laisser recevoir n'importe quelle forme
 * produirait une page cassée à la première saisie. Le type vit donc dans {@see \App\Website\Service\HomeBlocks},
 * avec la clé ; la base ne fait que ranger la valeur.
 *
 * **Il n'y a pas de type « HTML libre ».** Un bloc de page d'accueil est un titre, un paragraphe ou
 * une liste — pas un document. Ouvrir du HTML ici obligerait à assainir sur un chemin de plus, pour
 * une richesse dont une page de vente n'a pas besoin. Le corps des articles, lui, en a besoin : il a
 * son propre assainisseur.
 */
enum BlockType: string
{
    /** Une seule ligne : un titre, un libellé de bouton. Rendu tel quel, échappé. */
    case Line = 'line';

    /**
     * Un paragraphe, éventuellement plusieurs.
     *
     * Les sauts de ligne sont rendus comme des sauts de ligne ; aucune balise n'est interprétée.
     */
    case Paragraph = 'paragraph';

    /** Une liste de phrases — les métiers, par exemple. Valeur : une liste de chaînes. */
    case Items = 'items';

    /**
     * Une liste de blocs titre + texte — les quatre cartes de modules, les trois étapes.
     *
     * Valeur : une liste d'objets `{title, text}`. Deux champs, pas plus : le jour où il en faut un
     * troisième, c'est un type de plus, pas un champ optionnel que la moitié des cartes ignore.
     */
    case Cards = 'cards';

    /**
     * Un texte long, en HTML, pour le corps d'une page de module (ED-11).
     *
     * ⚠ **C'EST LE SEUL TYPE QUI ACCEPTE DES BALISES, ET IL EST ASSAINI À L'ÉCRITURE** par le même
     * {@see \App\Website\Service\BodySanitizer} que le corps d'un article. La règle « pas de HTML
     * libre » posée plus haut vaut pour les blocs de la page d'accueil — un titre, un chapô, une
     * liste — où des balises n'ajouteraient rien et ouvriraient un chemin d'assainissement de plus.
     *
     * Une page de module, elle, doit pouvoir porter des sous-titres et des listes : sans eux, vingt
     * pages de deux paragraphes se ressemblent, et un moteur appelle ça du contenu mince.
     */
    case Rich = 'rich';
}
