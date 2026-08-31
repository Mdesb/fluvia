<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * UN EXPEDITEUR DE COURRIEL EST-IL REELLEMENT CONFIGURE ?
 *
 * Six services de ce depot composent et « envoient » un courriel — mot de passe oublie, invitation
 * d'utilisateur, liste d'attente, confirmation de commande, relance de panier, rapport planifie. Ils
 * s'executent, ne levent rien, et n'envoient rien : `MAILER_DSN=null://null` avale tout en silence.
 *
 * ⚠ CE SERVICE EXISTE POUR QUE LES ECRANS CESSENT DE PROMETTRE CES ENVOIS — ET SURTOUT POUR QU'ILS
 * CESSENT DE LE PROMETTRE LE JOUR OU L'ENVOI MARCHERA.
 *
 * La tentation etait d'ecrire six phrases « aucun envoi n'est branche » en dur. Ca aurait ete la
 * meme faute que la legende de tri de Supervision : une phrase vraie a l'ecriture, fausse deux
 * minutes apres le correctif, et que rien ne relie a ce qui l'a rendue fausse. Six exemplaires du
 * meme defaut, avec six endroits ou personne ne repassera.
 *
 * Le fait vit donc ici, il est lu a l'execution, et il est publie par `/me` — que tout ecran a
 * forcement recu avant de se rendre (garde-fou « profil charge avant le rendu »).
 *
 * ⚠ ET LE SENS DU DOUTE EST DELIBERE. Une valeur absente ou illisible rend `false` : on annonce
 * « pas d'expediteur » plutot que d'en promettre un. L'erreur dans ce sens fait qu'un bouton reste
 * eteint alors qu'il pouvait servir — visible, signalable, corrigible. L'erreur inverse fait croire
 * a un envoi qui n'a pas lieu, et personne ne s'en apercoit : c'est exactement le defaut qu'on
 * corrige.
 */
final class ExpediteurCourriel
{
    public function __construct(
        #[Autowire(env: 'MAILER_DSN')] private readonly string $dsn = '',
    ) {
    }

    /**
     * ⚠ ON RECONNAIT LES TRANSPORTS QUI N'ENVOIENT PAS, PAS CEUX QUI ENVOIENT.
     *
     * La liste des transports reels de Symfony s'allonge (smtp, sendmail, ses, mailgun, postmark,
     * brevo, scaleway…) : une liste blanche serait fausse le jour ou quelqu'un en branche un qui n'y
     * figure pas, et elle le serait dans le mauvais sens — « pas d'expediteur » sur une instance qui
     * en a un. La liste des transports INERTES, elle, est courte et stable.
     */
    public function estBranche(): bool
    {
        $dsn = trim($this->dsn);

        if ('' === $dsn) {
            return false;
        }

        // `null://null` avale tout. `smtp://localhost` sans serveur echouerait bruyamment, ce qui est
        // un autre probleme — visible, celui-la.
        return !str_starts_with($dsn, 'null://');
    }
}
