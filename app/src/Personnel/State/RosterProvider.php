<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Entity\Etablissement;
use App\Personnel\ApiResource\RosterHebdomadaire;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Enum\StatutAffectationTravail;
use App\Personnel\Enum\StatutCreneauTravail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /personnel/roster (§4.5 spec, CA-6) : agrège `CreneauTravail`+`AffectationTravail`+
 * `Qualification.estValideA()`. Filtres en query string : `etablissement`, `espace`, `poste`,
 * `employe`, `debut[after]`/`debut[before]` (période).
 *
 * @implements ProviderInterface<list<RosterHebdomadaire>>
 */
final class RosterProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $this->requestStack->getCurrentRequest();

        $qb = $this->em->getRepository(CreneauTravail::class)->createQueryBuilder('c')
            ->andWhere('c.statut != :annule')
            ->setParameter('annule', StatutCreneauTravail::Annule->value)
            ->orderBy('c.debut', 'ASC');

        if ($request !== null) {
            $etablissementId = $request->query->get('etablissement');
            if (\is_string($etablissementId) && $etablissementId !== '') {
                $qb->andWhere('c.etablissement = :etablissement')->setParameter('etablissement', basename($etablissementId), 'uuid');
            }
            $espaceId = $request->query->get('espace');
            if (\is_string($espaceId) && $espaceId !== '') {
                $qb->andWhere('c.espace = :espace')->setParameter('espace', basename($espaceId), 'uuid');
            }
            $poste = $request->query->get('poste');
            if (\is_string($poste) && $poste !== '') {
                $qb->andWhere('c.libellePoste LIKE :poste')->setParameter('poste', '%' . $poste . '%');
            }
            $periode = $request->query->all('debut');
            $apres = $periode['after'] ?? null;
            if (\is_string($apres) && $apres !== '') {
                $qb->andWhere('c.debut >= :apres')->setParameter('apres', new \DateTimeImmutable($apres), 'datetime_immutable');
            }
            $avant = $periode['before'] ?? null;
            if (\is_string($avant) && $avant !== '') {
                $qb->andWhere('c.debut <= :avant')->setParameter('avant', new \DateTimeImmutable($avant), 'datetime_immutable');
            }
        }

        /** @var list<CreneauTravail> $creneaux */
        $creneaux = $qb->getQuery()->getResult();

        $employeFiltre = $request?->query->get('employe');

        $vues = [];
        foreach ($creneaux as $creneau) {
            /** @var list<AffectationTravail> $affectations */
            $affectations = $this->em->getRepository(AffectationTravail::class)->createQueryBuilder('a')
                ->andWhere('a.creneauTravail = :creneau')
                ->andWhere('a.statut != :annulee')
                ->setParameter('creneau', $creneau->getId(), 'uuid')
                ->setParameter('annulee', StatutAffectationTravail::Annulee->value)
                ->getQuery()->getResult();

            if (\is_string($employeFiltre) && $employeFiltre !== '') {
                $idFiltre = basename($employeFiltre);
                $employeAffecte = array_filter(
                    $affectations,
                    static fn (AffectationTravail $a) => (string) $a->getEmploye()?->getId() === $idFiltre,
                );
                if ($employeAffecte === []) {
                    continue;
                }
            }

            $vue = new RosterHebdomadaire();
            $vue->id = (string) $creneau->getId();
            $vue->etablissement = (string) $creneau->getEtablissement()?->getId();
            $vue->espace = $creneau->getEspace() !== null ? (string) $creneau->getEspace()->getId() : null;
            $vue->poste = $creneau->getLibellePoste();
            $vue->debut = (string) $creneau->getDebut()?->format(DATE_ATOM);
            $vue->fin = (string) $creneau->getFin()?->format(DATE_ATOM);
            $vue->qualificationRequise = $creneau->getQualificationRequise()?->value;
            $vue->effectifRequis = $creneau->getEffectifRequis();
            $vue->employesAffectes = array_map(
                static fn (AffectationTravail $a) => trim(($a->getEmploye()?->getPrenom() ?? '') . ' ' . ($a->getEmploye()?->getNom() ?? '')),
                $affectations,
            );

            $qualificationRequise = $creneau->getQualificationRequise();
            $qualificationManquante = false;
            if ($qualificationRequise !== null) {
                $couvert = false;
                foreach ($affectations as $affectation) {
                    $qualification = $affectation->getQualificationUtilisee();
                    if ($qualification !== null && $qualification->getType() === $qualificationRequise && $qualification->estValideA($creneau->getDebut() ?? new \DateTimeImmutable())) {
                        $couvert = true;
                        break;
                    }
                }
                $qualificationManquante = !$couvert;
            }
            $vue->qualificationManquanteOuExpiree = $qualificationManquante;

            $vue->statutCouverture = match (true) {
                $qualificationManquante => 'conflit',
                \count($affectations) < $creneau->getEffectifRequis() => 'sous_couvert',
                default => 'complet',
            };

            $vues[] = $vue;
        }

        return $vues;
    }
}
