<?php

declare(strict_types=1);

namespace App\Reporting\Projection\Doctrine;

use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatSession;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Organisation\Entity\Etablissement;
use App\Reporting\Projection\ProjectionComptaInterface;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur Doctrine par défaut de `ProjectionComptaInterface` (§2.1 plan-reporting.md). Lit
 * `SessionCaisse` + paiements espèces (M2), `ProfilExploitant::couvre()` (M6) — jamais d'écriture.
 */
final class ProjectionComptaDoctrine implements ProjectionComptaInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function fondDeCaisseTheorique(Uuid $etablissementId): string
    {
        /** @var list<SessionCaisse> $sessionsOuvertes */
        $sessionsOuvertes = $this->em->getRepository(SessionCaisse::class)->findBy([
            'etablissement' => $etablissementId,
            'etat' => EtatSession::Ouverte,
        ]);

        $total = 0.0;
        foreach ($sessionsOuvertes as $session) {
            $total += (float) $session->getFondDeCaisse();

            // Arithmétique entre deux agrégats (SUM - SUM) non supportée nativement dans une seule
            // SelectExpression DQL (`Expected T_FROM, got '-'`) : deux agrégats séparés, soustraction en PHP.
            $resultat = $this->em->createQueryBuilder()
                ->select('COALESCE(SUM(p.montant), 0) AS montant', 'COALESCE(SUM(p.rendu), 0) AS rendu')
                ->from(Paiement::class, 'p')
                ->innerJoin(Vente::class, 'v', 'WITH', 'p.vente = v')
                ->where('v.session = :session')
                ->andWhere('p.moyenCode = :especes')
                ->andWhere('v.statut != :enCours')
                ->setParameter('session', $session->getId(), 'uuid')
                ->setParameter('especes', 'especes')
                ->setParameter('enCours', StatutVente::EnCours)
                ->getQuery()
                ->getSingleResult();

            $total += (float) $resultat['montant'] - (float) $resultat['rendu'];
        }

        return number_format($total, 2, '.', '');
    }

    public function regimeExploitant(Uuid $etablissementId): ?TypeExploitant
    {
        $etablissement = $this->em->getRepository(Etablissement::class)->find($etablissementId);
        if (!$etablissement instanceof Etablissement) {
            return null;
        }

        /** @var list<ProfilExploitant> $profils */
        $profils = $this->em->getRepository(ProfilExploitant::class)->findAll();
        foreach ($profils as $profil) {
            if ($profil->couvre($etablissement)) {
                return $profil->getType();
            }
        }

        return null;
    }

    public function syntheseRegimeIsolee(Uuid $etablissementId): array
    {
        $regime = $this->regimeExploitant($etablissementId);

        // RAD/redevances DSP non implémenté dans ce lot (§7.6 plan-compta.md) : vue isolée
        // documentée « non disponible », jamais fusionnée dans l'agrégat commun (RG-REPORT-09).
        return [
            'regime' => $regime?->value,
            'radDisponible' => false,
            'note' => 'RAD/redevances DSP non implémenté (Compta L4, point d\'extension) — vue isolée non disponible.',
        ];
    }
}
