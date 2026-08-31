<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Organisation\Entity\Etablissement;
use App\Stock\Entity\ParametrageStock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résout le paramétrage de stock d'un établissement — **le seul endroit du module qui interroge la
 * base pour cela**.
 *
 * Avant, quatre services faisaient chacun leur `findOneBy()` puis interprétaient l'absence à leur
 * façon. Concentrer la lecture ici ne sert pas à économiser une requête : cela sert à ce qu'il n'y ait
 * **qu'un seul endroit où l'absence a un sens**, celui déclaré par {@see StockSettings}.
 *
 * Un établissement `null` — un article orphelin — donne le même résultat qu'un paramétrage absent, et
 * c'est voulu : on ne sait rien, donc on n'accorde rien.
 */
final class StockSettingsProvider
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function forEstablishment(?Etablissement $etablissement): StockSettings
    {
        if (null === $etablissement) {
            return StockSettings::from(null);
        }

        $parametrage = $this->em->getRepository(ParametrageStock::class)
            ->findOneBy(['etablissement' => $etablissement->getId()]);

        return StockSettings::from($parametrage instanceof ParametrageStock ? $parametrage : null);
    }
}
