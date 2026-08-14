<?php

declare(strict_types=1);

namespace App\Securite\Service;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Résout l'établissement actif à partir de l'en-tête HTTP « X-Etablissement » (RG-SOCLE-05).
 * Sert de périmètre aux lectures/écritures et au calcul des droits effectifs.
 */
final class ContexteEtablissement
{
    public const HEADER = 'X-Etablissement';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Identifiant de l'établissement actif transmis dans l'en-tête, ou null. */
    public function idActif(): ?Uuid
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $valeur = $request->headers->get(self::HEADER);
        if ($valeur === null || $valeur === '') {
            return null;
        }

        return Uuid::isValid($valeur) ? Uuid::fromString($valeur) : null;
    }

    public function etablissementActif(): ?Etablissement
    {
        $id = $this->idActif();
        if ($id === null) {
            return null;
        }

        return $this->em->getRepository(Etablissement::class)->find($id);
    }
}
