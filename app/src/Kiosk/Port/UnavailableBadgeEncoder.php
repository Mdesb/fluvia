<?php

declare(strict_types=1);

namespace App\Kiosk\Port;

use App\Kiosk\Entity\Kiosk;
use App\Vente\Entity\BilletSupport;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * L'encodeur de badges tant que le pilote matériel (#337) n'est pas raccordé : il répond
 * « indisponible », toujours, et le dit. Une doublure qui RÉUSSIT ferait croire à l'exploitant que ses
 * bornes remettent des badges ; celle-ci rend l'écran honnête — la borne imprime le ticket à la place.
 */
#[AsAlias(BadgeEncoderInterface::class)]
final class UnavailableBadgeEncoder implements BadgeEncoderInterface
{
    public function encode(Kiosk $kiosk, BilletSupport $support): BadgeEncodingResult
    {
        return BadgeEncodingResult::unavailable(
            'Aucun encodeur de badges n\'est raccordé à cette borne : votre ticket est imprimé à la place, son QR code ouvre l\'accès.'
        );
    }
}
