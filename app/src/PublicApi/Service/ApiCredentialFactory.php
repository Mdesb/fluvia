<?php

declare(strict_types=1);

namespace App\PublicApi\Service;

use App\PublicApi\Entity\ApiCredential;
use App\PublicApi\Entity\PartnerApplication;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Frappe une cle : rend le secret en clair UNE fois, n'enregistre que son empreinte.
 *
 * ⚠ **LE SECRET N'EST JAMAIS RELISIBLE**, y compris par nous. C'est le seul comportement qui rende
 * vraie la phrase qu'on ecrira dans la documentation : « conservez cette cle, elle ne sera plus
 * affichee ». Une cle qu'on peut retrouver cote serveur est une cle qu'un acces au serveur suffit a
 * voler, et la promesse faite au partenaire devient fausse.
 *
 * Le prefixe `flv_` sert a la reconnaitre dans un journal ou un depot de code : les outils de
 * detection de secrets se reglent sur un prefixe, pas sur une entropie.
 */
final class ApiCredentialFactory
{
    private const PREFIXE = 'flv_';

    /** 32 octets : le secret n'est jamais choisi par un humain, l'entropie est native. */
    private const OCTETS = 32;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{credential: ApiCredential, secret: string} le secret n'existe que dans ce retour
     */
    public function issue(PartnerApplication $application, ?\DateTimeImmutable $expiresAt = null): array
    {
        $secret = self::PREFIXE.bin2hex(random_bytes(self::OCTETS));

        $credential = (new ApiCredential())
            ->setApplication($application)
            ->setSecretHash(hash('sha256', $secret))
            ->setPrefix(substr($secret, 0, 12))
            ->setExpiresAt($expiresAt);

        $this->em->persist($credential);
        $this->em->flush();

        return ['credential' => $credential, 'secret' => $secret];
    }
}
