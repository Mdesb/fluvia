<?php

declare(strict_types=1);

namespace App\Reporting\Service;

use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Indicateur;
use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\ModeCalculIndicateur;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Enum\RegimeExploitantMesure;
use App\Reporting\Enum\StatutCompletude;
use App\Reporting\Projection\ProjectionAccesInterface;
use App\Reporting\Projection\ProjectionComptaInterface;
use App\Reporting\Projection\ProjectionRecouvrementInterface;
use App\Reporting\Projection\ProjectionReservationInterface;
use App\Reporting\Projection\ProjectionVenteInterface;
use App\Reporting\ValueObject\Periode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cœur de `reporting:agreger` (§2.1/§2.5/§2.6/§2.7 plan-reporting.md) : trois passes
 * site → région → groupe, upsert idempotent via `Mesure.cleAgregation` (RG-M7-02/03).
 *
 * ⚠ Simplification documentée vs §2.9 du plan : la passe groupe agrège directement les `Mesure`
 * de niveau établissement (filtrées sur `groupe_id`, dénormalisé sur chaque ligne établissement,
 * §1.1) plutôt que les `Mesure` de niveau région — résultat identique (même somme/max), lecture
 * toujours indexée, mais sans la stricte séquentialité « chaque passe ne lit que la précédente »
 * du plan (acceptable aux volumes MVP, documenté rapport final).
 */
final class AgregateurMesuresService
{
    private const CODE_FMI_MAX = 'FMI_MAX';
    private const CODE_FMI_MAX_SOMME_SITES = 'FMI_MAX_SOMME_SITES';
    private const CODE_FMI_MAX_SITE_CRITIQUE = 'FMI_MAX_SITE_CRITIQUE';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectionVenteInterface $projectionVente,
        private readonly ProjectionAccesInterface $projectionAcces,
        private readonly ProjectionComptaInterface $projectionCompta,
        private readonly ProjectionReservationInterface $projectionReservation,
        private readonly ProjectionRecouvrementInterface $projectionRecouvrement,
    ) {
    }

    public function agregerPeriode(Periode $periode): void
    {
        $this->agregerSites($periode);
        $this->em->flush();

        $this->agregerNiveauSuperieur(NiveauEntite::Region, $periode);
        $this->em->flush();

        $this->agregerNiveauSuperieur(NiveauEntite::Groupe, $periode);
        $this->em->flush();
    }

    // --- Passe 1 : site (§2.1, §2.5, §2.6) ---

    private function agregerSites(Periode $periode): void
    {
        /** @var list<Etablissement> $etablissements */
        $etablissements = $this->em->getRepository(Etablissement::class)->findAll();
        $indicateurs = $this->indicateursSite();

        foreach ($etablissements as $etablissement) {
            $regime = $this->mapRegime($this->projectionCompta->regimeExploitant($etablissement->getId()));

            foreach ($indicateurs as $indicateur) {
                $valeur = $this->calculerValeurSite($indicateur, $etablissement, $periode);
                if ($valeur === null) {
                    continue;
                }

                $seuil = $indicateur->getSeuilCompletudeMinutes() ?? 60;
                $horsLigne = $this->projectionAcces->etablissementHorsLigne($etablissement->getId(), $seuil);
                $statut = $horsLigne ? StatutCompletude::Partiel : StatutCompletude::Complet;
                $sitesManquants = $horsLigne ? [$etablissement->getId()->toRfc4122()] : null;

                $this->upsert(
                    $indicateur,
                    NiveauEntite::Etablissement,
                    $etablissement->getId(),
                    $etablissement,
                    null,
                    null,
                    $periode,
                    $valeur,
                    $regime,
                    false,
                    $statut,
                    $sitesManquants,
                );
            }
        }
    }

    /** @return list<Indicateur> Indicateurs actifs calculables au niveau site (exclut les variantes FMI agrégées). */
    private function indicateursSite(): array
    {
        /** @var list<Indicateur> $tous */
        $tous = $this->em->getRepository(Indicateur::class)->findBy(['actif' => true]);

        return array_values(array_filter(
            $tous,
            static fn (Indicateur $i): bool => !\in_array($i->getCode(), [self::CODE_FMI_MAX_SOMME_SITES, self::CODE_FMI_MAX_SITE_CRITIQUE], true),
        ));
    }

    private function calculerValeurSite(Indicateur $indicateur, Etablissement $etablissement, Periode $periode): ?string
    {
        return match ($indicateur->getCode()) {
            'CA' => $this->projectionVente->caEncaisse($etablissement->getId(), $periode),
            // number_format (pas `(string) $int`) : `Mesure.valeur` est decimal(14,2) (cf. calculerFmiMaxSite).
            'FREQUENTATION_CUMULEE' => number_format($this->projectionAcces->frequentationCumulee($etablissement->getId(), $periode), 2, '.', ''),
            self::CODE_FMI_MAX => $this->calculerFmiMaxSite($etablissement, $periode),
            'FOND_CAISSE' => $this->projectionCompta->fondDeCaisseTheorique($etablissement->getId()),
            'TAUX_REMPLISSAGE' => $this->projectionReservation->tauxRemplissage($etablissement->getId(), $periode),
            'NO_SHOW' => number_format($this->projectionReservation->noShow($etablissement->getId(), $periode), 2, '.', ''),
            'IMPAYES' => $this->projectionRecouvrement->impayes($etablissement->getId(), $periode)['montant'],
            default => null,
        };
    }

    /**
     * FMI max échantillonnée (§2.5) : max des jauges de l'établissement à l'instant de
     * l'agrégation, conservé par `GREATEST` avec la valeur déjà stockée pour cette période — un pic
     * bref entre deux exécutions peut ne pas être capturé (Risque §9.6 plan-reporting.md, sans
     * impact sécurité : le seuil ERP temps réel reste entièrement dans Accès).
     */
    private function calculerFmiMaxSite(Etablissement $etablissement, Periode $periode): string
    {
        $jauges = $this->projectionAcces->jaugesFmi($etablissement->getId());
        $max = 0;
        foreach ($jauges as $jauge) {
            $max = max($max, $jauge['valeurCourante']);
        }

        $indicateur = $this->em->getRepository(Indicateur::class)->findOneBy(['code' => self::CODE_FMI_MAX]);
        if ($indicateur !== null) {
            $cle = Mesure::calculerCleAgregation(
                self::CODE_FMI_MAX,
                NiveauEntite::Etablissement->value,
                $etablissement->getId()->toRfc4122(),
                null,
                null,
                null,
                null,
                $periode->debut->format('Y-m-d'),
                $periode->fin->format('Y-m-d'),
                $periode->granularite->value,
            );
            $existante = $this->em->getRepository(Mesure::class)->findOneBy(['cleAgregation' => $cle]);
            if ($existante !== null) {
                $max = max($max, (int) (float) $existante->getValeur());
            }
        }

        // `number_format` impératif (pas `(string) $max`) : `Mesure.valeur` est un `decimal(14,2)` —
        // une valeur non formatée ("10" plutôt que "10.00") reste telle quelle dans l'objet PHP après
        // flush (Doctrine ne reformate pas en mémoire), et une relecture ultérieure dans le même
        // EntityManager renvoie l'instance déjà managée (identity map), pas la valeur reformatée par
        // MySQL — un test/consommateur comparant à "10.00" échouerait sinon.
        return number_format($max, 2, '.', '');
    }

    // --- Passes 2/3 : région et groupe (§2.1, §2.5, §2.7) ---

    private function agregerNiveauSuperieur(NiveauEntite $niveau, Periode $periode): void
    {
        $entites = $niveau === NiveauEntite::Region
            ? $this->em->getRepository(Region::class)->findAll()
            : $this->em->getRepository(Groupe::class)->findAll();

        $indicateurs = $this->em->getRepository(Indicateur::class)->findBy(['actif' => true]);
        $fmiMax = $this->em->getRepository(Indicateur::class)->findOneBy(['code' => self::CODE_FMI_MAX]);
        $fmiSomme = $this->em->getRepository(Indicateur::class)->findOneBy(['code' => self::CODE_FMI_MAX_SOMME_SITES]);
        $fmiCritique = $this->em->getRepository(Indicateur::class)->findOneBy(['code' => self::CODE_FMI_MAX_SITE_CRITIQUE]);

        foreach ($entites as $entite) {
            \assert($entite instanceof Region || $entite instanceof Groupe);
            $region = $entite instanceof Region ? $entite : null;
            $groupe = $entite instanceof Groupe ? $entite : null;

            foreach ($indicateurs as $indicateur) {
                $code = $indicateur->getCode();
                if (\in_array($code, [self::CODE_FMI_MAX, self::CODE_FMI_MAX_SOMME_SITES, self::CODE_FMI_MAX_SITE_CRITIQUE], true)) {
                    continue;
                }

                $mesures = $this->mesuresSourcesEtablissement($indicateur, $niveau, $entite->getId(), $periode);
                if ($mesures === []) {
                    continue;
                }

                [$valeur, $statut, $sitesManquants, $regime, $comparabilite] = $this->combiner($indicateur, $mesures);

                $this->upsert($indicateur, $niveau, $entite->getId(), null, $region, $groupe, $periode, $valeur, $regime, $comparabilite, $statut, $sitesManquants);
            }

            if ($fmiMax !== null && $fmiSomme !== null && $fmiCritique !== null) {
                $this->agregerFmi($fmiMax, $fmiSomme, $fmiCritique, $niveau, $entite->getId(), $region, $groupe, $periode);
            }
        }
    }

    /**
     * Mesures de niveau établissement descendant de l'entité région/groupe visée (§1.1 : `region_id`/
     * `groupe_id` dénormalisés sur chaque ligne établissement).
     *
     * @return list<Mesure>
     */
    private function mesuresSourcesEtablissement(Indicateur $indicateur, NiveauEntite $niveau, Uuid $entiteId, Periode $periode): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mesure::class, 'm')
            ->where('m.indicateur = :indicateur')
            ->andWhere('m.niveau = :niveauEtab')
            ->andWhere('m.periodeDebut = :debut')
            ->andWhere('m.periodeFin = :fin')
            ->andWhere('m.granularite = :granularite')
            // Lié par identifiant + type 'uuid' explicite (PAS l'objet `Indicateur` directement) :
            // binder l'entité provoque un WHERE toujours faux avec ce couple Doctrine ORM 3.x /
            // identifiant custom `UuidType` (reproduit et confirmé, cf. rapport final).
            ->setParameter('indicateur', $indicateur->getId(), 'uuid')
            ->setParameter('niveauEtab', NiveauEntite::Etablissement)
            ->setParameter('debut', $periode->debut->format('Y-m-d'))
            ->setParameter('fin', $periode->fin->format('Y-m-d'))
            ->setParameter('granularite', $periode->granularite);

        if ($niveau === NiveauEntite::Region) {
            $qb->andWhere('m.region = :entite')->setParameter('entite', $entiteId, 'uuid');
        } else {
            $qb->andWhere('m.groupe = :entite')->setParameter('entite', $entiteId, 'uuid');
        }

        /** @var list<Mesure> $resultat */
        $resultat = $qb->getQuery()->getResult();

        return $resultat;
    }

    /**
     * @param list<Mesure> $mesures
     *
     * @return array{0: string, 1: StatutCompletude, 2: list<string>|null, 3: ?RegimeExploitantMesure, 4: bool}
     */
    private function combiner(Indicateur $indicateur, array $mesures): array
    {
        $valeurs = array_map(static fn (Mesure $m): float => (float) $m->getValeur(), $mesures);

        $valeur = match ($indicateur->getModeCalcul()) {
            ModeCalculIndicateur::Somme => array_sum($valeurs),
            ModeCalculIndicateur::Max => max($valeurs),
            ModeCalculIndicateur::Moyenne, ModeCalculIndicateur::Ratio => $valeurs === [] ? 0.0 : array_sum($valeurs) / \count($valeurs),
        };

        $partiel = false;
        $sitesManquants = [];
        foreach ($mesures as $m) {
            if ($m->getStatutCompletude() === StatutCompletude::Partiel) {
                $partiel = true;
                foreach ($m->getSitesManquants() ?? [] as $site) {
                    $sitesManquants[$site] = $site;
                }
            }
        }

        // Consolidation multi-régime (RG-REPORT-09, §2.7) : régimes distincts non nuls des sources.
        $regimes = [];
        foreach ($mesures as $m) {
            if ($m->getRegimeExploitant() !== null) {
                $regimes[$m->getRegimeExploitant()->value] = $m->getRegimeExploitant();
            }
        }
        $comparabilite = \count($regimes) > 1;
        $regimeConsolide = $comparabilite ? RegimeExploitantMesure::Mixte : (1 === \count($regimes) ? array_values($regimes)[0] : null);

        return [
            number_format($valeur, 2, '.', ''),
            $partiel ? StatutCompletude::Partiel : StatutCompletude::Complet,
            $sitesManquants === [] ? null : array_values($sitesManquants),
            $regimeConsolide,
            $comparabilite,
        ];
    }

    /**
     * FMI ≠ fréquentation cumulée (RG-M7-04, §2.5, CA-6) : jamais d'agrégation naïve au-delà du
     * site. Deux indicateurs distincts, libellés explicites imposés par construction
     * (`Indicateur.libelle`) — `FMI_MAX_SOMME_SITES` (somme) et `FMI_MAX_SITE_CRITIQUE` (max).
     */
    private function agregerFmi(
        Indicateur $fmiMax,
        Indicateur $fmiSomme,
        Indicateur $fmiCritique,
        NiveauEntite $niveau,
        Uuid $entiteId,
        ?Region $region,
        ?Groupe $groupe,
        Periode $periode,
    ): void {
        $mesures = $this->mesuresSourcesEtablissement($fmiMax, $niveau, $entiteId, $periode);
        if ($mesures === []) {
            return;
        }

        $valeurs = array_map(static fn (Mesure $m): float => (float) $m->getValeur(), $mesures);
        $somme = array_sum($valeurs);
        $max = max($valeurs);

        $partiel = false;
        $sitesManquants = [];
        foreach ($mesures as $m) {
            if ($m->getStatutCompletude() === StatutCompletude::Partiel) {
                $partiel = true;
                foreach ($m->getSitesManquants() ?? [] as $site) {
                    $sitesManquants[$site] = $site;
                }
            }
        }
        $statut = $partiel ? StatutCompletude::Partiel : StatutCompletude::Complet;
        $sitesManquantsListe = $sitesManquants === [] ? null : array_values($sitesManquants);

        $this->upsert($fmiSomme, $niveau, $entiteId, null, $region, $groupe, $periode, number_format($somme, 2, '.', ''), null, false, $statut, $sitesManquantsListe);
        $this->upsert($fmiCritique, $niveau, $entiteId, null, $region, $groupe, $periode, number_format($max, 2, '.', ''), null, false, $statut, $sitesManquantsListe);
    }

    // --- Upsert idempotent (§2.1) ---

    /** @param list<string>|null $sitesManquants */
    private function upsert(
        Indicateur $indicateur,
        NiveauEntite $niveau,
        Uuid $entiteId,
        ?Etablissement $etablissement,
        ?Region $region,
        ?Groupe $groupe,
        Periode $periode,
        string $valeur,
        ?RegimeExploitantMesure $regime,
        bool $comparabilite,
        StatutCompletude $statut,
        ?array $sitesManquants,
    ): Mesure {
        $cle = Mesure::calculerCleAgregation(
            $indicateur->getCode(),
            $niveau->value,
            $entiteId->toRfc4122(),
            null,
            null,
            null,
            null,
            $periode->debut->format('Y-m-d'),
            $periode->fin->format('Y-m-d'),
            $periode->granularite->value,
        );

        $mesure = $this->em->getRepository(Mesure::class)->findOneBy(['cleAgregation' => $cle]);
        if ($mesure === null) {
            $mesure = new Mesure();
            $mesure->setCleAgregation($cle);
        }

        $mesure->setIndicateur($indicateur);
        match ($niveau) {
            NiveauEntite::Etablissement => $mesure->definirRattachementEtablissement($etablissement ?? throw new \LogicException('Établissement requis.')),
            NiveauEntite::Region => $mesure->definirRattachementRegion($region ?? throw new \LogicException('Région requise.')),
            NiveauEntite::Groupe => $mesure->definirRattachementGroupe($groupe ?? throw new \LogicException('Groupe requis.')),
        };
        $mesure->setPeriodeDebut($periode->debut)->setPeriodeFin($periode->fin)->setGranularite($periode->granularite);
        $mesure->setValeur($valeur);
        $mesure->setRegimeExploitant($regime);
        $mesure->setComparabiliteRegime($comparabilite);
        $mesure->setStatutCompletude($statut);
        $mesure->setSitesManquants($sitesManquants);
        $mesure->setGenereLe(new \DateTimeImmutable());

        $this->em->persist($mesure);

        return $mesure;
    }

    private function mapRegime(?TypeExploitant $type): ?RegimeExploitantMesure
    {
        return match ($type) {
            TypeExploitant::RegieDirecte => RegimeExploitantMesure::Regie,
            TypeExploitant::Dsp => RegimeExploitantMesure::Dsp,
            TypeExploitant::GroupePrive => RegimeExploitantMesure::GroupePrive,
            null => null,
        };
    }
}
