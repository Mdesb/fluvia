<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Passage;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Comptage non nominatif (US-L3-04, RG-ACC-03, CA-5) : bébé/accompagnant/exonéré sans support. La
 * fréquentation (cumul) et la jauge FMI s'incrémentent (présence physique réelle) sans décompte de
 * crédit ; le motif est requis et le passage apparaît distinctement (`resultat = compte`).
 */
final class ComptageNonNominatifHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
    ) {
    }

    public function compter(Equipement $equipement, SensPassage $sens, string $motif, ?\DateTimeImmutable $horodatage = null): Passage
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour un comptage non nominatif (CA-5).');
        }

        $controleur = $equipement->getControleur();
        $espace = $controleur?->getEspace();
        if ($controleur === null || $espace === null) {
            throw new UnprocessableEntityHttpException('Équipement mal rattaché (topologie incohérente).');
        }

        $horodatage ??= new \DateTimeImmutable();

        // Cf. ValidationPassageHandler : on mire l'objet en mémoire après le raw SQL (contourne le
        // suivi ORM) pour éviter qu'un appelant relisant $jauge via la map d'identité soit périmé.
        $jauge = $this->em->getRepository(JaugeFmi::class)->findOneBy(['espace' => $espace]);
        if ($jauge instanceof JaugeFmi) {
            $hex = bin2hex($jauge->getId()->toBinary());
            if ($sens === SensPassage::Entree) {
                $this->connection->executeStatement(
                    'UPDATE acces_jauge_fmi SET valeur_courante = valeur_courante + 1, cumul_jour = cumul_jour + 1 WHERE id = UNHEX(:hex)',
                    ['hex' => $hex],
                );
                $jauge->setValeurCourante($jauge->getValeurCourante() + 1)->setCumulJour($jauge->getCumulJour() + 1);
            } else {
                $this->connection->executeStatement(
                    'UPDATE acces_jauge_fmi SET valeur_courante = GREATEST(valeur_courante - 1, 0) WHERE id = UNHEX(:hex)',
                    ['hex' => $hex],
                );
                $jauge->setValeurCourante(max(0, $jauge->getValeurCourante() - 1));
            }
        }

        $passage = new Passage();
        $passage->setEspace($espace)
            ->setControleur($controleur)
            ->setEquipement($equipement)
            ->setSens($sens)
            ->setHorodatage($horodatage)
            ->setCleIdempotence(Uuid::v4())
            ->setResultat(ResultatPassage::Compte)
            ->setCodeMotif(CodeMotifRefus::NonNominatif)
            ->setMotif($motif);

        $this->em->persist($passage);
        $this->em->flush();

        return $passage;
    }
}
