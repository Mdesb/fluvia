<?php

declare(strict_types=1);

namespace App\Offre\Service;

use App\Offre\Entity\Produit;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère un code produit unique (préfixe « PRD- » + suffixe hexadécimal aléatoire), avec boucle
 * anti-collision contre la base. Utilisé à la création (ProduitProcessor) et à la duplication
 * (DupliquerProcessor) : le code n'est jamais saisi par l'utilisateur, sauf s'il est fourni
 * explicitement en entrée (rétrocompat), auquel cas il est respecté tel quel.
 */
final class GenerateurCodeProduit
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function generer(): string
    {
        do {
            $code = 'PRD-' . strtoupper(bin2hex(random_bytes(4)));
        } while ($this->em->getRepository(Produit::class)->findOneBy(['code' => $code]) !== null);

        return $code;
    }
}
