<?php

declare(strict_types=1);

namespace App\Website;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Website` — le site public de l'ÉDITEUR (ED-10).
 *
 * ⚠ **À NE PAS CONFONDRE AVEC `Boutique\Entity\Vitrine`**, qui est la boutique en ligne d'un
 * établissement CLIENT. Ici, c'est le site qui vend la plateforme : sa page d'accueil et son blog.
 * Les deux mots français se ressemblent, les deux objets n'ont aucun rapport, et le nom anglais de
 * ce module sert aussi à les séparer.
 *
 * **`capability()` rend `null` : ce n'est pas un module vendable.** Aucun exploitant ne l'active, il
 * ne se facture pas, il ne s'expose à personne. C'est un outil de l'éditeur pour l'éditeur — même
 * nature que `Dms`, qui rend `null` pour la même raison.
 *
 * **Les permissions sont déclarées mais l'accès ne repose pas sur elles.** Toutes les écritures
 * passent par `/editor/website/**`, gardé par {@see \App\Subscription\Security\EditorOnly} : le
 * périmètre se dérive de l'établissement actif, jamais d'un identifiant reçu. Les permissions
 * existent pour le jour où plusieurs personnes écriront sur ce site sans être toutes administratrices.
 *
 * **Aucun événement.** Publier un article n'intéresse aucun autre module aujourd'hui, et déclarer un
 * événement sans preneur ajouterait une ligne au contrat que personne ne consomme — le dépôt en
 * compte déjà vingt-cinq.
 */
final class WebsiteModule implements ModuleManifest
{
    public function id(): string
    {
        return 'website';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        // ⚠ FAMILLE `editor.`, PAS `website.`, ET C'EST VOLONTAIRE. Le préfixe d'une permission
        // désigne ici l'ÉCRAN qui la demande, pas le module qui la porte : `editor.manage_offer`,
        // `editor.read_billing`, `editor.support_access` gardent déjà les autres écrans de
        // l'administration de l'éditeur. Une permission `website.write` isolée dans cette famille
        // aurait obligé qui distribue les droits à comprendre pourquoi celle-là se nomme autrement.
        //
        // Une seule, et pas trois. Séparer lire/écrire/publier suppose plusieurs personnes aux rôles
        // distincts ; il n'y en a qu'une. Trois droits inutilisés, ce sont trois cases à cocher dont
        // personne ne connaît l'effet le jour où quelqu'un les découvre.
        return ['editor.manage_website'];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }

    /**
     * Les écrans que ce module apporte.
     *
     * `/editeur/site` est servi par la branche « éditeur » du frontal, pas par le back-office des
     * exploitants : le site de l'éditeur n'a rien à faire dans l'application d'une piscine, et son
     * code n'a pas à être téléchargé par un caissier.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return ['/editeur/site'];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
