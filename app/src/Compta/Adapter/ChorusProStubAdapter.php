<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\FactureB2G;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\Port\ChorusProInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Adaptateur Chorus Pro par défaut — AUCUN RACCORDEMENT RÉEL.
 *
 * ── CE QU'IL FAISAIT, ET POURQUOI C'ÉTAIT LE PIRE DES TROIS COMPORTEMENTS POSSIBLES ─────────────
 *
 * Il rendait `StatutEnvoi::Transmis` sans rien transmettre. L'exploitant voyait ses factures B2G
 * marquées **transmises**, et l'aurait découvert par une relance de sa collectivité — c'est-à-dire
 * au moment et par la voie les plus coûteuses.
 *
 * Un canal absent qui annonce le succès est pire qu'un canal absent : il retire à l'exploitant la
 * seule chose qui lui restait, savoir que ça n'a pas été fait.
 *
 * ⚠ Le même dépôt contenait déjà les deux traitements opposés du même cas : `ItboxAdapter` lève une
 * exception explicite pour ce motif exact, en le disant dans son en-tête. Deux réponses contraires
 * à la même question, à deux modules d'écart. Arbitré par Maxime le 31/08 : on refuse.
 *
 * ── POURQUOI 503 ET PAS UNE ERREUR DE SAISIE ────────────────────────────────────────────────────
 *
 * L'appelant n'a rien fait de mal et n'a rien à corriger : c'est la plateforme qui n'est pas
 * raccordée. Un 4xx l'enverrait relire sa facture. Le dépôt pourra être rejoué tel quel le jour du
 * raccordement, sans nouveau numéro de facture (RG-FACT-07, cas limite §7 de la spec).
 *
 * ── CE QUE LA LEVÉE NE CASSE PAS ────────────────────────────────────────────────────────────────
 *
 * `DepotChorusProHandler` appelle `deposer()` AVANT `persist()`/`flush()` : rien n'est écrit, donc
 * aucun demi-état ne subsiste. La facture reste déposable plus tard.
 *
 * @see \App\Acces\Adapter\ItboxAdapter le même refus, pour la même raison, sur le matériel d'accès
 */
final class ChorusProStubAdapter implements ChorusProInterface
{
    public function deposer(FactureB2G $facture): StatutEnvoi
    {
        throw new ServiceUnavailableHttpException(null, sprintf(
            'Canal Chorus Pro non raccordé : la facture « %s » N\'A PAS été transmise et aucun dépôt '
            .'n\'a eu lieu. Le format de dépôt (Factur-X / UBL / CII) et le raccordement à la '
            .'plateforme restent à cadrer. Le dépôt sera rejouable tel quel, sans nouveau numéro.',
            (string) $facture->getId()
        ));
    }
}
