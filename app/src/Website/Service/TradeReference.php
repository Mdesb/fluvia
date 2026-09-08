<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Website\Entity\Trade;
use App\Website\Enum\PublicationStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La lecture publique du referentiel des metiers.
 *
 * ⚠ **ELLE NE REND QUE LES METIERS PUBLIES.** Un metier en brouillon existe en base, se modifie
 * dans l'administration, et n'apparait ni sur le site, ni au plan du site, ni dans les donnees
 * structurees. Sans ce filtre, ecrire une page metier reviendrait a la publier.
 *
 * ⚠ **L'ORDRE EST CELUI DU RANG, PUIS DU CODE.** Le rang seul ne suffit pas : deux metiers au meme
 * rang sortiraient dans l'ordre que la base voudrait, c'est-a-dire dans un ordre qui peut changer
 * d'un serveur a l'autre. Une page rendue deux fois doit etre identique, sans quoi le cache et les
 * moteurs voient deux pages differentes.
 */
final readonly class TradeReference
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<Trade> */
    public function published(): array
    {
        /** @var list<Trade> $lignes */
        $lignes = $this->em->getRepository(Trade::class)->findBy(
            ['status' => PublicationStatus::Published],
            ['position' => 'ASC', 'code' => 'ASC'],
        );

        return $lignes;
    }
}
