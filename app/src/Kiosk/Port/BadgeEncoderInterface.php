<?php

declare(strict_types=1);

namespace App\Kiosk\Port;

use App\Kiosk\Entity\Kiosk;
use App\Vente\Entity\BilletSupport;

/**
 * L'ENCODEUR DE BADGES de la borne : le distributeur qui sort un badge (ou un bracelet) RFID vierge,
 * l'encode pour le support vendu et le remet à l'usager.
 *
 * Le pilote matériel vit dans le module d'accès (#337, encodage RFID ITCotation) : ce port est la
 * frontière, et la borne ne connaît rien d'autre. Tant qu'il n'est pas raccordé, {@see UnavailableBadgeEncoder}
 * répond « indisponible » — jamais « encodé » : un faux succès laisserait l'usager repartir sans titre.
 *
 * Contrat : l'appel arrive APRÈS la validation (la vente est payée et scellée, le support existe). Il
 * ne lève pas : un échec est une issue (`issued: false`), et la borne imprime alors le ticket, dont le
 * QR code ouvre le tourniquet. L'usager a payé ; il repart toujours avec un titre.
 */
interface BadgeEncoderInterface
{
    public function encode(Kiosk $kiosk, BilletSupport $support): BadgeEncodingResult;
}
