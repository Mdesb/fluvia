<?php

declare(strict_types=1);

namespace App\Subscription\Adapter;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Port\SupportAccessRightsInterface;
use App\Subscription\Service\SupportAccessGuard;

/**
 * CE QU'UN ACCÈS D'ASSISTANCE ACCORDE RÉELLEMENT — la pièce qui manquait à toute la chaîne.
 *
 * ── ⚠ TOUT ÉTAIT CONSTRUIT SAUF CECI, ET RIEN NE LE DISAIT ─────────────────────────────────────
 *
 * `SupportAccess` savait s'ouvrir, se tracer, expirer et se révoquer. `SupportAccessGuard` savait
 * refuser et journaliser. `CalculateurDroits` appelait bien le port. Mais la seule implémentation
 * branchée était `NoSupportAccessRights`, qui n'accorde jamais rien : **ouvrir un accès
 * d'assistance ne changeait strictement rien**, et aucune erreur ne le signalait.
 *
 * C'est la forme la plus complète du défaut qu'on traque : une chaîne entière, cohérente, testée
 * par endroits — dont le dernier maillon manque et dont le silence ressemble à un refus légitime.
 *
 * ── LES DROITS ACCORDÉS : TOUT, ET C'EST UNE DÉCISION DE MAXIME ───────────────────────────────
 *
 * Le 31/08, entre trois options — tout, lecture seule, ou lecture plus les gestes de dépannage.
 *
 * Sa raison, et elle tient : une assistance en lecture seule ne dépanne pas. Elle permet de dire au
 * client quoi cliquer, ce qu'un appel téléphonique fait déjà. Le jour où sa caisse ne s'ouvre pas,
 * quelqu'un doit pouvoir corriger.
 *
 * ⚠ LA CONTREPARTIE ÉTAIT ÉNONCÉE AVANT LE CHOIX, et elle est réelle : un agent de l'éditeur peut
 * encaisser, supprimer, et modifier des données comptables chez un client. Ce qui l'encadre n'est
 * pas une limite de droits — c'est la TRACE : l'ouverture et la révocation sont journalisées contre
 * l'établissement DU CLIENT, avec le nom de l'agent et le motif, et le client les voit dans son
 * propre journal d'audit. Sa seconde décision du même jour.
 *
 * ── `*.*` PLUTÔT QU'UNE LISTE, ET CE N'EST PAS DE LA PARESSE ──────────────────────────────────
 *
 * Une liste de codes serait à tenir à jour : chaque module neuf ajouterait une permission que
 * l'assistance n'aurait pas, et le défaut se manifesterait des mois plus tard par « l'assistance ne
 * peut pas m'aider sur ce module » — sans que personne ne relie la cause à l'effet.
 *
 * Le joker dit ce que Maxime a décidé : les mêmes droits qu'un administrateur, y compris sur ce qui
 * n'existe pas encore. Si un jour la décision change, c'est ICI qu'on écrit la liste, en un seul
 * endroit.
 *
 * ── ⚠ POURQUOI ON N'APPELLE PAS `assertCanRead()` ICI ─────────────────────────────────────────
 *
 * Elle trace chaque appel — et `CalculateurDroits::codesEffectifs()` tourne à CHAQUE REQUÊTE. Le
 * journal du client se remplirait de milliers de lignes « accès utilisé » pour une seule
 * intervention, et la trace qui compte — l'ouverture, avec son motif — s'y noierait.
 *
 * Une trace qu'on ne peut plus lire ne protège personne. On emploie donc `canRead()`, qui décide
 * sans écrire, et la traçabilité repose sur l'ouverture et la révocation, qui sont des gestes
 * délibérés et rares.
 */
final class SupportAccessRights implements SupportAccessRightsInterface
{
    /**
     * Les mêmes droits qu'un administrateur de l'établissement visité.
     *
     * ⚠ Ce joker est reconnu par `CalculateurDroits`, qui l'emploie déjà pour le rôle « Accès
     * total » : `*` en module et `*` en action. Le changer ici ne suffirait donc pas à restreindre
     * l'assistance — il faudrait aussi que le calculateur sache refuser un joker venu d'un accès
     * d'assistance, ce qu'il ne distingue pas aujourd'hui.
     */
    private const CODES_ADMINISTRATEUR = ['*.*'];

    public function __construct(
        private readonly SupportAccessGuard $guard,
    ) {
    }

    /**
     * @return list<string>
     */
    public function grantedCodes(
        Utilisateur $agent,
        Etablissement $etablissement,
        \DateTimeImmutable $maintenant,
    ): array {
        // ⚠ AUCUN CAS DOUTEUX N'EST DISTINGUÉ, ET C'EST LE CONTRAT. Pas d'accès, accès révoqué,
        // accès expiré, agent hors de l'éditeur, module absent : tous rendent le même tableau vide.
        // L'appelant n'a pas à savoir lequel — et lui faire distinguer ces cas l'obligerait à
        // réimplémenter la règle qu'il délègue.
        if (!$this->guard->canRead($agent, $etablissement, $maintenant)) {
            return [];
        }

        return self::CODES_ADMINISTRATEUR;
    }
}
