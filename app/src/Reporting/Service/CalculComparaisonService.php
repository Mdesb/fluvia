<?php

declare(strict_types=1);

namespace App\Reporting\Service;

use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\Mesure;
use App\Reporting\Entity\ObjectifIndicateur;
use App\Reporting\Enum\NiveauEntite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Écarts vs objectif et vs n-1, code couleur bon/à surveiller/critique (§2.4 plan-reporting.md,
 * M7-02). Seuils par défaut documentés (Risque §9.5 plan-reporting.md, non chiffrés par le cahier) :
 * écart ≥ 0 % → « bon », entre -10 % et 0 % (exclu) → « à_surveiller », < -10 % → « critique ».
 */
final class CalculComparaisonService
{
    private const SEUIL_A_SURVEILLER = -10.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{valeurCible: string, ecartValeur: string, ecartPourcentage: ?string, couleur: string}|null
     */
    public function ecartVsObjectif(Indicateur $indicateur, NiveauEntite $niveau, Uuid $entiteId, Mesure $mesureCourante): ?array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('o')
            ->from(ObjectifIndicateur::class, 'o')
            ->where('o.indicateur = :indicateur')
            ->andWhere('o.niveau = :niveau')
            ->andWhere('o.periodeDebut <= :debut')
            ->andWhere('o.periodeFin >= :fin')
            // getId()+'uuid' explicite, pas l'objet Indicateur (cf. AgregateurMesuresService).
            ->setParameter('indicateur', $indicateur->getId(), 'uuid')
            ->setParameter('niveau', $niveau)
            ->setParameter('debut', $mesureCourante->getPeriodeDebut())
            ->setParameter('fin', $mesureCourante->getPeriodeFin())
            ->setMaxResults(1);

        match ($niveau) {
            NiveauEntite::Etablissement => $qb->andWhere('o.etablissement = :entite'),
            NiveauEntite::Region => $qb->andWhere('o.region = :entite'),
            NiveauEntite::Groupe => $qb->andWhere('o.groupe = :entite'),
        };
        $qb->setParameter('entite', $entiteId, 'uuid');

        $objectif = $qb->getQuery()->getOneOrNullResult();
        if (!$objectif instanceof ObjectifIndicateur) {
            return null;
        }

        return $this->calculerEcart($mesureCourante->getValeur(), $objectif->getValeurCible());
    }

    /**
     * @return array{valeurN1: string, ecartValeur: string, ecartPourcentage: ?string, couleur: string}|null
     */
    public function ecartVsN1(Mesure $mesureCourante): ?array
    {
        $cleN1 = Mesure::calculerCleAgregation(
            $mesureCourante->getIndicateur()?->getCode() ?? '',
            $mesureCourante->getNiveau()->value,
            $this->entiteId($mesureCourante)->toRfc4122(),
            $mesureCourante->getActivite(),
            $mesureCourante->getProduit()?->toRfc4122(),
            $mesureCourante->getCategorie()?->toRfc4122(),
            $mesureCourante->getCanal(),
            $mesureCourante->getPeriodeDebut()->modify('-1 year')->format('Y-m-d'),
            $mesureCourante->getPeriodeFin()->modify('-1 year')->format('Y-m-d'),
            $mesureCourante->getGranularite()->value,
        );

        $mesureN1 = $this->em->getRepository(Mesure::class)->findOneBy(['cleAgregation' => $cleN1]);
        if (!$mesureN1 instanceof Mesure) {
            return null;
        }

        $ecart = $this->calculerEcart($mesureCourante->getValeur(), $mesureN1->getValeur());

        return [
            'valeurN1' => $mesureN1->getValeur(),
            'ecartValeur' => $ecart['ecartValeur'],
            'ecartPourcentage' => $ecart['ecartPourcentage'],
            'couleur' => $ecart['couleur'],
        ];
    }

    /**
     * Fonction pure (testable isolément) : écart valeur/pourcentage/couleur entre une valeur
     * courante et une valeur de référence (objectif ou n-1).
     *
     * @return array{valeurCible: string, ecartValeur: string, ecartPourcentage: ?string, couleur: string}
     */
    public function calculerEcart(string $valeurCourante, string $reference): array
    {
        $courante = (float) $valeurCourante;
        $ref = (float) $reference;
        $ecartValeur = $courante - $ref;
        $ecartPourcentage = $ref !== 0.0 ? ($ecartValeur / $ref) * 100 : null;

        return [
            'valeurCible' => $reference,
            'ecartValeur' => number_format($ecartValeur, 2, '.', ''),
            'ecartPourcentage' => $ecartPourcentage !== null ? number_format($ecartPourcentage, 2, '.', '') : null,
            'couleur' => $this->couleur($ecartPourcentage),
        ];
    }

    private function couleur(?float $ecartPourcentage): string
    {
        if ($ecartPourcentage === null) {
            return 'inconnu';
        }

        return match (true) {
            $ecartPourcentage >= 0 => 'bon',
            $ecartPourcentage >= self::SEUIL_A_SURVEILLER => 'a_surveiller',
            default => 'critique',
        };
    }

    private function entiteId(Mesure $mesure): Uuid
    {
        return match ($mesure->getNiveau()) {
            NiveauEntite::Etablissement => $mesure->getEtablissement()?->getId() ?? throw new \LogicException('Établissement manquant.'),
            NiveauEntite::Region => $mesure->getRegion()?->getId() ?? throw new \LogicException('Région manquante.'),
            NiveauEntite::Groupe => $mesure->getGroupe()?->getId() ?? throw new \LogicException('Groupe manquant.'),
        };
    }
}
