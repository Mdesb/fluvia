<?php

declare(strict_types=1);

namespace App\Securite\Port;

use App\Securite\Entity\Utilisateur;
use Symfony\Component\Uid\Uuid;

/**
 * Les établissements qu'un accès d'assistance rend VISIBLES — le pendant du port qui accorde les
 * droits, et sans lequel ces droits ne servent à rien.
 *
 * ── POURQUOI DEUX PORTS ET NON UN ──────────────────────────────────────────────────────────────
 *
 * `SupportAccessRightsInterface` répond « que peut faire cet agent ICI ? » — une question posée sur
 * un établissement déjà désigné, celui de l'en-tête `X-Etablissement`. Elle suffit à autoriser les
 * gestes, et elle ne suffit à rien d'autre.
 *
 * Celui-ci répond « quels établissements cet agent peut-il seulement NOMMER ? » — la question que
 * pose `PerimetreEtablissementExtension` quand elle construit la liste. Sans elle, l'agent a tous
 * les droits sur un établissement qui n'apparaît nulle part : le sélecteur ne le propose pas, et
 * l'unique moyen d'y entrer est de connaître son identifiant et de forger l'en-tête à la main.
 *
 * ⚠ C'EST LE MÊME DÉFAUT QUE CELUI QU'ON VIENT DE CORRIGER, UNE COUCHE PLUS HAUT. Le mécanisme
 * complet, cohérent, testé — et inaccessible, faute de la pièce qui le rend atteignable. Un accès
 * d'assistance qu'on ne peut pas emprunter est un accès qui n'existe pas, et le contournement
 * redevient ce que RG-ED-07 interdit : une affectation permanente posée pour dépanner.
 *
 * ── CE QU'IL NE FAIT PAS ───────────────────────────────────────────────────────────────────────
 *
 * Il ne décide **rien**. Il énumère, et le cloisonnement reste seul juge de ce qu'il en fait. Le
 * jour où l'on voudra que l'assistance voie sans pouvoir agir, c'est l'autre port qui change, pas
 * celui-ci — la visibilité et les droits sont deux questions, et les avoir séparées est ce qui
 * permettra de les régler séparément.
 *
 * ⚠ **Le socle ne connaît pas `Subscription`.** Déclaré ici parce que c'est `Securite` qui en a
 * besoin, implémenté là-bas parce que c'est `Subscription` qui tient `SupportAccess`. Le repli est
 * `NoSupportAccessScope` : sans le module d'abonnement, personne n'accède à rien de plus, et le
 * cloisonnement ordinaire s'applique inchangé.
 */
interface SupportAccessScopeInterface
{
    /**
     * Les établissements que cet agent atteint par un accès d'assistance utilisable À CET INSTANT.
     *
     * **Rend un tableau vide dans tous les cas douteux** — pas d'accès, accès révoqué, accès expiré,
     * accès pas encore ouvert, module d'abonnement absent. L'appelant n'a pas à les distinguer.
     *
     * ⚠ **L'instant est un ARGUMENT, et ce n'est pas un détail de style.** Une implémentation qui
     * lirait l'horloge elle-même ne se testerait qu'à l'instant qu'il est — c'est précisément ce qui
     * avait laissé l'angle mort de l'autre port : les tests s'étaient massés du côté commode, celui
     * où l'on peut choisir le moment.
     *
     * **N'écrit rien et ne trace pas.** Cette question est posée à chaque liste d'établissements ;
     * la tracer noierait l'ouverture, qui est la trace qui compte parce qu'elle porte le motif.
     *
     * @return list<Uuid> identifiants d'établissements, vide si aucun accès utilisable
     */
    public function reachableEstablishmentIds(Utilisateur $agent, \DateTimeImmutable $at): array;
}
