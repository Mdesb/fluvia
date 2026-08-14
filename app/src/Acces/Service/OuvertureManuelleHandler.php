<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Dto\OuvertureContexte;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\Passage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Acces\Port\PiloteAcces;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouverture manuelle tracée (US-L3-06, écran A-03, CA-7) : franchissement forcé par un agent, motif
 * requis, horodaté et attribué. Alimente la même jauge/journal qu'un franchissement automatique.
 */
final class OuvertureManuelleHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PiloteAcces $pilote,
    ) {
    }

    public function ouvrir(Equipement $equipement, Utilisateur $agent, string $motif, SensPassage $sens = SensPassage::Entree, ?\DateTimeImmutable $horodatage = null): Passage
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour une ouverture manuelle (CA-7).');
        }

        $controleur = $equipement->getControleur();
        $espace = $controleur?->getEspace();
        if ($controleur === null || $espace === null) {
            throw new UnprocessableEntityHttpException('Équipement mal rattaché (topologie incohérente).');
        }

        $passage = new Passage();
        $passage->setEspace($espace)
            ->setControleur($controleur)
            ->setEquipement($equipement)
            ->setSens($sens)
            ->setHorodatage($horodatage ?? new \DateTimeImmutable())
            ->setCleIdempotence(Uuid::v4())
            ->setResultat(ResultatPassage::Valide)
            ->setCodeMotif(CodeMotifRefus::OuvertureManuelle)
            ->setMotif($motif)
            ->setAgent($agent);

        $this->em->persist($passage);
        $this->em->flush();

        $this->pilote->ouvrir($equipement, new OuvertureContexte(manuelle: true, agent: $agent, motif: $motif));

        return $passage;
    }
}
