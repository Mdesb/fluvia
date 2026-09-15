<?php

declare(strict_types=1);

namespace App\Reporting\Notification;

/**
 * Le transport de courriel expédie-t-il réellement ?
 *
 * ── POURQUOI CETTE QUESTION EXISTE ──────────────────────────────────────────────────────────────
 *
 * `MailerInterface::send()` ne lève AUCUNE exception avec un transport `null://`. Déduire « envoyé »
 * de « aucune exception » est donc correct vis-à-vis de l'abstraction du mailer, et faux dans les
 * faits : `ExecuterRapportsCommand` écrivait `statut = Envoye` et `envoyeLe` pour des rapports que
 * personne ne recevait.
 *
 * ⚠ ET CES FAUX SONT INDISCERNABLES DES VRAIS. `envoyeLe` est renseigné dans les deux cas : une fois
 * la ligne en base, aucune requête ne peut plus dire si le destinataire a reçu quelque chose. C'est
 * pour cela que le problème se règle AVANT d'autoriser la commande, et pas après.
 *
 * Cette interface est séparée de son implémentation pour une raison précise : un test doit pouvoir
 * répondre « oui » sous un transport de test qui, lui, n'expédie pas. Sans ce point de bascule, le
 * chemin `Envoye` ne serait couvert par aucun test — et c'est exactement ce qui s'était passé.
 */
interface TransportCourrielInterface
{
    /**
     * `true` si un courriel remis à ce transport a une chance d'atteindre son destinataire.
     *
     * Ce n'est pas une garantie de remise — un serveur peut refuser, une adresse peut être morte.
     * C'est la distinction plus grossière, et la seule qui soit décidable ici : « ce transport
     * tente-t-il quelque chose » contre « ce transport avale tout en silence ».
     */
    public function estReel(): bool;

    /**
     * Pourquoi le transport n'est pas réel, en une phrase destinée à un opérateur, ou `null`
     * quand il l'est.
     *
     * ⚠ Une raison nulle signifie « le transport est réel », jamais « je ne sais pas ». Les deux
     * ne se confondent pas : le second cas n'existe pas ici, parce que la décision se prend sur une
     * chaîne de configuration qui est toujours lisible.
     */
    public function raison(): ?string;
}
