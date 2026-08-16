<?php

declare(strict_types=1);

namespace App\Reporting\Projection\Doctrine;

use App\Acces\Entity\Controleur;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\Passage;
use App\Acces\Enum\EtatControleur;
use App\Acces\Enum\ResultatPassage;
use App\Acces\Enum\SensPassage;
use App\Reporting\Projection\ProjectionAccesInterface;
use App\Reporting\ValueObject\Periode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur Doctrine par défaut de `ProjectionAccesInterface` (§2.1 plan-reporting.md). Lit
 * `Passage`, `JaugeFmi`/`EspaceAcces`, `Controleur.etat` — jamais d'écriture, jamais de recalcul de
 * jauge (RG-ACC-06).
 */
final class ProjectionAccesDoctrine implements ProjectionAccesInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function frequentationCumulee(Uuid $etablissementId, Periode $periode): int
    {
        $resultat = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Passage::class, 'p')
            ->where('p.etablissement = :etablissement')
            ->andWhere('p.sens = :sens')
            ->andWhere('p.resultat = :resultat')
            ->andWhere('p.horodatage >= :debut')
            ->andWhere('p.horodatage <= :fin')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->setParameter('sens', SensPassage::Entree)
            ->setParameter('resultat', ResultatPassage::Valide)
            ->setParameter('debut', $periode->debut)
            ->setParameter('fin', $periode->fin)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $resultat;
    }

    public function jaugesFmi(Uuid $etablissementId): array
    {
        $jauges = $this->em->createQueryBuilder()
            ->select('j', 'e')
            ->from(JaugeFmi::class, 'j')
            ->innerJoin('j.espace', 'e')
            ->where('e.etablissement = :etablissement')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->getQuery()
            ->getResult();

        $resultat = [];
        foreach ($jauges as $jauge) {
            \assert($jauge instanceof JaugeFmi);
            $espace = $jauge->getEspace();
            \assert($espace instanceof EspaceAcces);
            $resultat[] = [
                'espace' => (string) $espace->getId(),
                'libelle' => $espace->getLibelle(),
                'valeurCourante' => $jauge->getValeurCourante(),
                'seuil' => $jauge->getSeuil(),
                'mode' => $jauge->getMode()->value,
            ];
        }

        return $resultat;
    }

    public function etablissementHorsLigne(Uuid $etablissementId, int $seuilMinutes): bool
    {
        /** @var list<Controleur> $controleurs */
        $controleurs = $this->em->getRepository(Controleur::class)->findBy(['etablissement' => $etablissementId]);
        if ($controleurs === []) {
            // Pas de contrôleur Accès sur ce site (verticale sans contrôle d'accès physique) :
            // ce seul signal ne peut pas conclure à un défaut de remontée (Risque §9.7 plan-reporting.md).
            return false;
        }

        $seuil = new \DateTimeImmutable(sprintf('-%d minutes', $seuilMinutes));
        foreach ($controleurs as $controleur) {
            if ($controleur->getEtat() !== EtatControleur::EnLigne) {
                return true;
            }
            $heartbeat = $controleur->getDernierHeartbeat();
            if ($heartbeat === null || $heartbeat < $seuil) {
                return true;
            }
        }

        return false;
    }
}
