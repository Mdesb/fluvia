<?php

declare(strict_types=1);

namespace App\Crm\Doctrine;

use App\Securite\Entity\Affectation;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * LA RÈGLE QUI DIT QUELS CLIENTS ON A LE DROIT DE VOIR — écrite une fois.
 *
 * Un utilisateur voit un client dès qu'il possède une affectation dans le **groupe** de ce client
 * (RG-SOCLE-05). Le chemin est long — affectation → établissement → région → groupe — et c'est
 * précisément pourquoi il ne doit exister qu'à un seul endroit.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────────────────────────
 *
 * La clause était écrite **deux fois** : dans `PerimetreCrmExtension`, et recopiée à la main dans
 * `RechercheClientProvider` — qui le documentait honnêtement, parce qu'un fournisseur sur mesure
 * court-circuite le pipeline d'API Platform et donc les extensions.
 *
 * Le module Campagnes en aurait écrit une troisième. Or une copie d'une règle de cloisonnement n'est
 * pas de la duplication de code : c'est une **seconde politique de sécurité que personne ne
 * maintient**. Le jour où la règle change, il en reste une version périmée — et c'est elle qui
 * décide qui voit quoi.
 *
 * > **Une fuite de cloisonnement ne produit pas d'erreur : elle produit des lignes en trop.**
 *
 * ── CE QUE L'APPELANT GARDE À SA CHARGE ─────────────────────────────────────────────────────────
 *
 * La **jointure** jusqu'à l'alias qui porte `.groupe`. Elle dépend de la requête — l'extension
 * traverse parfois une association (`consentement → client`), un fournisseur part directement du
 * client — et la mécaniser ici obligerait à deviner la forme de la requête appelante.
 *
 * ── POURQUOI UNE SOUS-REQUÊTE `EXISTS` ET PAS UNE JOINTURE ──────────────────────────────────────
 *
 * `FilterEagerLoadingExtension` réécrit les requêtes de collection en `IN()` et **perd
 * silencieusement** une jointure « libre » (sur une classe, pas une association) quand son
 * `resourceClassResolver` optionnel n'est pas câblé — ce qui est le cas ici. La sous-requête `EXISTS`,
 * elle, est recopiée telle quelle : c'est une simple chaîne dans la clause `WHERE`.
 *
 * Perdre la jointure ne casserait rien de visible. Ça rendrait les clients des autres groupes.
 */
final class CustomerScope
{
    /**
     * Restreint la requête aux clients du ou des groupes où l'utilisateur est affecté.
     *
     * @param string $aliasPorteurDuGroupe l'alias dont la propriété `groupe` est celle à confronter
     */
    public static function restreindreAuGroupe(
        QueryBuilder $queryBuilder,
        string $aliasPorteurDuGroupe,
        Uuid $utilisateurId,
    ): void {
        $sousRequete = 'SELECT aff_pc.id FROM ' . Affectation::class . ' aff_pc '
            . 'INNER JOIN ' . Etablissement::class . ' etb_pc WITH etb_pc = aff_pc.etablissement '
            . 'INNER JOIN ' . Region::class . ' reg_pc WITH reg_pc = etb_pc.region '
            . 'WHERE IDENTITY(aff_pc.utilisateur) = :perimetre_client_utilisateur '
            . 'AND IDENTITY(reg_pc.groupe) = IDENTITY(' . $aliasPorteurDuGroupe . '.groupe)';

        $queryBuilder
            ->andWhere('EXISTS (' . $sousRequete . ')')
            // Type `uuid` explicite : sur un identifiant à type personnalisé, une comparaison sans
            // type ne compte rien **et ne lève pas** (D58). Ici, zéro client ressemblerait à un
            // périmètre vide — la plus rassurante des réponses fausses.
            ->setParameter('perimetre_client_utilisateur', $utilisateurId, 'uuid')
            ->distinct();
    }
}
