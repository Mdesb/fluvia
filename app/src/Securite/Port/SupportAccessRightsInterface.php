<?php

declare(strict_types=1);

namespace App\Securite\Port;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;

/**
 * Le seul chemin par lequel un agent de l'éditeur obtient des droits sur l'établissement d'un client.
 *
 * **Pourquoi ce port existe, et pourquoi il n'ouvre rien par lui-même.**
 *
 * Le cloisonnement ordinaire refuse déjà à un agent de l'éditeur l'accès à l'établissement d'un client :
 * il n'y a pas d'affectation, donc pas de droits, donc 404. Vérifié — aucune extension Doctrine
 * n'exempte l'éditeur. **Le risque n'est donc pas l'accès non autorisé.**
 *
 * **Le risque est le contournement, et il est certain.** Le jour où un client appelle parce que sa
 * caisse ne s'ouvre pas, quelqu'un devra regarder ses données. Sans chemin légitime, la seule façon est
 * de **donner à l'agent une affectation sur l'établissement du client** : permanente, invisible,
 * indistinguable d'une affectation normale, et que personne ne pensera à retirer. C'est exactement ce
 * que RG-ED-07 interdit, obtenu par la porte de service — et pire que si la règle n'existait pas,
 * puisque tout le monde croira qu'elle protège.
 *
 * **Une règle sans chemin praticable ne tient pas : elle se contourne, et le contournement devient la
 * pratique.** Requalification due à `claude-D`, qui avait d'abord signalé « un garde appelé par rien »
 * et a compris en le rouvrant que ce n'était pas une serrure manquante mais **une porte manquante**.
 *
 * **Ce que ce port accorde : `*.*` — les droits d'un administrateur.**
 *
 * ⚠ CE PARAGRAPHE DISAIT « `*.lire`, et rien d'autre », avec son raisonnement : une assistance sert à
 * comprendre, jamais à agir, et un agent qui corrige lui-même une donnée du client remplace un
 * problème constaté par un problème invisible. C'était le contrat tant que personne n'implémentait le
 * port. **Maxime a tranché le 31/08 : tout, comme un administrateur**, et l'unique implémentation rend
 * le joker complet. La phrase a survécu à la décision qui l'a rendue fausse.
 *
 * Sa raison, et elle tient : une assistance en lecture seule ne dépanne pas. Elle permet de dire au
 * client quoi cliquer, ce qu'un appel téléphonique fait déjà. Le jour où sa caisse ne s'ouvre pas,
 * quelqu'un doit pouvoir corriger.
 *
 * **L'objection ci-dessus n'a pas disparu pour autant** : un agent de l'éditeur peut encaisser,
 * supprimer et modifier des données comptables chez un client. Ce qui l'encadre n'est pas une limite
 * de droits — c'est la TRACE, journalisée contre l'établissement DU CLIENT, avec le nom de l'agent et
 * le motif, et que le client voit dans son propre journal. Contrepartie énoncée avant le choix, et
 * seconde décision du même jour.
 *
 * Le jour où l'on voudra revenir à la lecture seule, c'est `SupportAccessRights` qu'on change — un
 * seul endroit — et c'est ici qu'on réécrit ce paragraphe.
 *
 * **L'implémentation trace l'usage.** Savoir qui *pouvait* regarder n'est pas savoir qui a regardé.
 *
 * ⚠ **Le socle ne connaît pas `Subscription`.** Ce port est déclaré ici parce que c'est `Securite` qui
 * en a besoin, et implémenté là-bas parce que c'est `Subscription` qui tient `SupportAccess`. Une
 * dépendance directe ferait dépendre le calcul des droits d'un module métier — et le jour où ce module
 * n'est pas installé, plus personne n'aurait de droits nulle part.
 */
interface SupportAccessRightsInterface
{
    /**
     * Les codes de permission qu'un accès d'assistance valide accorde à cet agent sur cet établissement.
     *
     * **Rend un tableau vide dans tous les cas douteux** — pas d'accès, accès révoqué, accès expiré,
     * agent qui n'appartient pas à l'éditeur, module d'abonnement absent. L'appelant n'a pas à
     * distinguer ces cas : ils produisent tous le même résultat, aucun droit.
     *
     * **L'appel vaut usage.** Une implémentation qui accorde doit tracer, parce que c'est le seul
     * moment où l'on sait qu'un agent a effectivement regardé.
     *
     * @return list<string> codes au format `module.action`, vide si aucun accès valide
     */
    public function grantedCodes(
        Utilisateur $agent,
        Etablissement $etablissement,
        \DateTimeImmutable $a,
    ): array;
}
