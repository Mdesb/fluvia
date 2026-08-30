<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Enum\StatutEnvoi;
use App\Compta\Port\PdpInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Adaptateur PDP (Plateforme de Dématérialisation Partenaire) — AUCUN RACCORDEMENT RÉEL.
 *
 * ── LE MÊME DÉFAUT QUE SON VOISIN, ET IL PORTE PLUS LOIN ────────────────────────────────────────
 *
 * Il rendait `StatutEnvoi::Transmis` sans rien transmettre, et son propre commentaire disait
 * « simulation » — mais un commentaire ne protège personne : c'est la valeur rendue qui est lue.
 *
 * ⚠ Ici l'enjeu dépasse une facture. Une déclaration d'e-reporting non transmise mais marquée
 * transmise est une **obligation déclarative que personne ne sait plus manquante**. La spécification
 * de facturation le dit elle-même sans détour : « aucune implémentation n'est livrée ». C'était le
 * câblage qui affirmait le contraire.
 *
 * ── CE QUI RESTE À CADRER, ET CE N'EST PAS QU'UN BRANCHEMENT ────────────────────────────────────
 *
 * Le choix de la PDP, le format (Factur-X / UBL / CII, EN 16931) et le calendrier de la réforme sont
 * hors du périmètre livré. Aucun de ces termes n'existe aujourd'hui dans le code — vérifié le 31/08,
 * « Factur-X » n'apparaît qu'une fois dans une spécification, comme question ouverte.
 *
 * ── POURQUOI 503, ET CE QUE LA LEVÉE NE CASSE PAS ───────────────────────────────────────────────
 *
 * L'appelant n'a rien à corriger : c'est la plateforme qui manque. `TransmettreEReportingProcessor`
 * appelle `deposer()` avant `flush()`, donc la déclaration garde son statut d'avant et reste
 * transmissible le jour du raccordement.
 *
 * @see \App\Compta\Adapter\ChorusProStubAdapter le même refus, sur le canal B2G
 */
final class PdpStubAdapter implements PdpInterface
{
    public function deposer(DeclarationEReporting $declaration): StatutEnvoi
    {
        throw new ServiceUnavailableHttpException(null, sprintf(
            'Aucune plateforme de dématérialisation n\'est raccordée : la déclaration d\'e-reporting '
            .'du %s au %s N\'A PAS été transmise. Le choix de la PDP et le format de dépôt restent à '
            .'cadrer ; la déclaration conserve son statut et reste transmissible ensuite.',
            $declaration->getPeriodeDebut()->format('d/m/Y'),
            $declaration->getPeriodeFin()->format('d/m/Y')
        ));
    }
}
