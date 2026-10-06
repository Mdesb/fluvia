<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Acces\ApiResource\SnapshotTerminal;
use App\Acces\Dto\EntreeSnapshotDto;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\StatutSupport;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Security\TerminalPorteeChecker;
use App\Acces\Security\TerminalUtilisateur;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Snapshot local incrémental/complet (GET /terminal/snapshot, US-TERM-03/04/05, plan-acces-terminal.md
 * §2.3). Snapshot complet = cas particulier du delta (`depuis` absent/0). Pagination stable via
 * `jusqua` (borne haute fixée à la 1ʳᵉ page). `Terminal.dernierSnapshotVersion`/`dernierAppel` mis à
 * jour en best-effort (traçabilité, pas source de vérité serveur).
 *
 * @implements ProviderInterface<SnapshotTerminal>
 */
final class SnapshotTerminalProvider implements ProviderInterface
{
    private const TAILLE_DEFAUT = 500;
    private const TAILLE_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly TerminalPorteeChecker $porteeChecker,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SnapshotTerminal
    {
        $terminalUtilisateur = $this->security->getUser();
        if (!$terminalUtilisateur instanceof TerminalUtilisateur) {
            throw new AccessDeniedHttpException('Terminal non authentifié.');
        }
        $terminal = $terminalUtilisateur->terminal;
        $etablissement = $terminal->getEtablissement();

        $request = $this->requestStack->getCurrentRequest();
        $depuis = max(0, (int) ($request?->query->get('depuis') ?? 0));
        $page = max(1, (int) ($request?->query->get('page') ?? 1));
        $tailleDemandee = (int) ($request?->query->get('taille') ?? self::TAILLE_DEFAUT);
        $taille = $tailleDemandee > 0 ? min($tailleDemandee, self::TAILLE_MAX) : self::TAILLE_DEFAUT;

        $jusquaParam = $request?->query->get('jusqua');
        $jusqua = $jusquaParam !== null
            ? (int) $jusquaParam
            : $this->versionCourante($etablissement?->getId());

        $qb = $this->em->getRepository(Support::class)->createQueryBuilder('s')
            ->andWhere('s.etablissement = :etab')->setParameter('etab', $etablissement?->getId(), 'uuid')
            ->andWhere('s.versionMaj <= :jusqua')->setParameter('jusqua', $jusqua)
            ->orderBy('s.versionMaj', 'ASC')->addOrderBy('s.id', 'ASC')
            ->setFirstResult(($page - 1) * $taille)
            ->setMaxResults($taille + 1);
        if ($depuis > 0) {
            // `depuis` absent/0 = snapshot complet (§2.3 du plan) : les supports jamais mutés portent
            // encore la valeur par défaut `versionMaj = 0` (backfill uniforme, cf. migration §5 pt.4) —
            // un filtre strict `> 0` les exclurait à tort du tout premier bootstrap. Pour un delta réel
            // (`depuis` = un curseur déjà servi, toujours >= 1 car la séquence démarre à 1), la borne
            // stricte est appliquée normalement (pas de sur-transmission, CA-5).
            $qb->andWhere('s.versionMaj > :depuis')->setParameter('depuis', $depuis);
        }

        /** @var list<Support> $supports */
        $supports = $qb->getQuery()->getResult();
        $pageSuivante = \count($supports) > $taille;
        if ($pageSuivante) {
            array_pop($supports);
        }

        $equipementsPortee = $this->porteeChecker->equipementsDansPortee($terminalUtilisateur);

        $vue = new SnapshotTerminal();
        $vue->versionCourante = $jusqua;
        $vue->page = $page;
        $vue->taillepage = $taille;
        $vue->pageSuivante = $pageSuivante;
        foreach ($supports as $support) {
            $vue->entrees[] = $this->projeter($support, $equipementsPortee)->toArray();
        }

        $terminal->setDernierSnapshotVersion($jusqua);
        $this->em->flush();

        return $vue;
    }

    private function versionCourante(?Uuid $etablissementId): int
    {
        $max = $this->em->getRepository(Support::class)->createQueryBuilder('s')
            ->select('MAX(s.versionMaj)')
            ->andWhere('s.etablissement = :etab')
            ->setParameter('etab', $etablissementId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return $max !== null ? (int) $max : 0;
    }

    /**
     * Le droit ouvre-t-il cette porte ? Oui s'il ouvre l'un des espaces que son contrôleur dessert
     * (`Controleur::espacesOuverts()`, le principal et les desservis) — exactement la boucle du
     * contrôle en ligne. Un équipement sans contrôleur n'ouvre rien : en ligne, il est refusé
     * comme topologie incohérente.
     */
    private function ouvreLaPorte(DroitAcces $droit, Equipement $equipement): bool
    {
        foreach ($equipement->getControleur()?->espacesOuverts() ?? [] as $desservi) {
            if ($droit->ouvre($desservi)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, Equipement> $equipementsPortee */
    private function projeter(Support $support, array $equipementsPortee): EntreeSnapshotDto
    {
        $appairage = $this->em->getRepository(Appairage::class)->findOneBy(['support' => $support, 'actif' => true]);
        $droit = $appairage?->getDroit();

        $revoque = $support->getStatut() === StatutSupport::Bloque
            || !$appairage instanceof Appairage
            || !$droit instanceof DroitAcces
            || $droit->getStatutProjection() !== StatutProjectionDroit::Valide;

        if ($revoque || !$droit instanceof DroitAcces) {
            return new EntreeSnapshotDto(identifiant: $support->getIdentifiant(), revoque: true, versionMaj: $support->getVersionMaj());
        }

        $portesEligibles = [];
        foreach ($equipementsPortee as $equipement) {
            // Zones autorisées (D87) : MÊME règle que le contrôle en ligne (`ValidationPassageHandler`,
            // étape des zones). Sans ce filtre, un droit limité à une zone ouvrait HORS LIGNE toutes
            // les portes de la borne, alors que la même porte le refusait en ligne. Le format ne
            // change pas : une porte non ouverte est simplement absente de `portesEligibles`.
            if (!$this->ouvreLaPorte($droit, $equipement)) {
                continue;
            }
            if ($droit->getSousReseau() !== null) {
                $sousReseauEspace = $equipement->getControleur()?->getEspace()?->getSousReseau();
                if ($sousReseauEspace === null || !$sousReseauEspace->getId()->equals($droit->getSousReseau()->getId())) {
                    continue;
                }
            }
            $portesEligibles[] = (string) $equipement->getId();
        }

        // Numéro de billet dérivé du `BilletSupport` référencé, même patron que
        // `AffichagePorteurResolver::resoudre()` (cohérence du contrat §5 entre le flux en ligne et le
        // snapshot hors-ligne).
        $numeroBillet = null;
        if ($droit->getBilletSupportRef() !== null) {
            $billetSupport = $this->em->getRepository(BilletSupport::class)->find($droit->getBilletSupportRef());
            $numeroBillet = $billetSupport?->getVente()?->getNumero();
        }

        return new EntreeSnapshotDto(
            identifiant: $support->getIdentifiant(),
            revoque: false,
            versionMaj: $support->getVersionMaj(),
            nomPorteur: null,
            numeroBillet: $numeroBillet,
            typeSupport: $support->getType()?->value,
            typeDroit: $droit->getSourceType()->value,
            compostagesRestants: $droit->getSourceType() === TypeDroitAcces::CarteQuota ? $droit->getCreditRestant() : null,
            validiteDebut: $droit->getFenetreDebut()?->format(DATE_ATOM),
            validiteFin: $droit->getFenetreFin()?->format(DATE_ATOM),
            portesEligibles: $portesEligibles,
            sousReseauId: $droit->getSousReseau()?->getId()->toRfc4122(),
        );
    }
}
