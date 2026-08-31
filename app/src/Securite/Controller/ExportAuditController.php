<?php

declare(strict_types=1);

namespace App\Securite\Controller;

use App\Audit\Entity\EntreeAudit;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /audit/export` (US-L7-09, CA-15, §2.10 plan-backoffice.md) : export CSV du journal
 * d'audit filtré — mêmes filtres que `GetCollection(EntreeAudit)` (`auteur` partiel, `action`,
 * `cibleType`, `etablissement` exacts, `dateHeure[after]`/`dateHeure[before]`). ⚠ HYPOTHÈSE
 * reconduite de la spec : export CSV synchrone, borné en MVP (pas de traitement asynchrone).
 */
#[AsController]
final class ExportAuditController
{
    private const LIMITE_LIGNES = 10000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    #[Route('/audit/export', name: 'securite_audit_export', methods: ['GET'])]
    public function __invoke(Request $request): StreamedResponse
    {
        if (!$this->security->isGranted('PERM', 'securite.gerer') && !$this->security->isGranted('PERM', 'securite.exporter')) {
            throw new AccessDeniedHttpException("Export de l'audit réservé à securite.gerer/securite.exporter.");
        }

        // ⚠ CE CONTRÔLEUR EST ÉCRIT À LA MAIN : AUCUNE EXTENSION DOCTRINE NE LE BORNE.
        //
        // `ResidualScopeExtension` cloisonne bien `EntreeAudit` — mais une extension ne s'applique
        // qu'aux opérations d'API Platform. La collection JSON était bornée au périmètre du lecteur ;
        // cet export CSV de la MÊME donnée ne l'était par rien, et `securite.gerer` est porté par
        // « Administrateur groupe », un rôle CLIENT. Un administrateur de groupe pouvait exporter le
        // journal d'audit de tous les établissements de la base en omettant le filtre.
        //
        // L'axe retenu est l'établissement ACTIF, plus strict que le périmètre de la collection.
        // Recopier ici la condition de périmètre la dupliquerait, et une règle écrite à deux endroits
        // devient une faille le jour où l'une des deux change. L'export est de toute façon un geste
        // délibéré sur le site qu'on regarde — bascule du 28/08.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Un export vide se lit « il ne s'est rien passé ». Ce n'est pas ce qu'on veut dire.
            throw new AccessDeniedHttpException("Aucun établissement actif : l'audit ne peut pas être exporté.");
        }

        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(EntreeAudit::class, 'e')
            ->andWhere('e.etablissement = :perimetre_export_actif')
            ->setParameter('perimetre_export_actif', $actif, 'uuid')
            ->orderBy('e.dateHeure', 'DESC')
            ->setMaxResults(self::LIMITE_LIGNES);

        if ($request->query->get('auteur') !== null) {
            $qb->andWhere('e.auteur LIKE :auteur')->setParameter('auteur', '%' . $request->query->get('auteur') . '%');
        }
        if ($request->query->get('action') !== null) {
            $qb->andWhere('e.action = :action')->setParameter('action', $request->query->get('action'));
        }
        if ($request->query->get('cibleType') !== null) {
            $qb->andWhere('e.cibleType = :cibleType')->setParameter('cibleType', $request->query->get('cibleType'));
        }
        // Ce filtre ne peut plus qu'AFFINER : la borne de l'établissement actif est déjà posée
        // au-dessus, et un `andWhere` ne l'élargit pas. Demander un autre établissement rend donc
        // un export vide plutôt que celui du voisin.
        if ($request->query->get('etablissement') !== null) {
            $qb->andWhere('e.etablissement = :etablissement')->setParameter('etablissement', $request->query->get('etablissement'), 'uuid');
        }
        $dateHeure = $request->query->all('dateHeure');
        if (isset($dateHeure['after'])) {
            $qb->andWhere('e.dateHeure >= :dateApres')->setParameter('dateApres', new \DateTimeImmutable((string) $dateHeure['after']));
        }
        if (isset($dateHeure['before'])) {
            $qb->andWhere('e.dateHeure <= :dateAvant')->setParameter('dateAvant', new \DateTimeImmutable((string) $dateHeure['before']));
        }

        /** @var list<EntreeAudit> $entrees */
        $entrees = $qb->getQuery()->getResult();

        $response = new StreamedResponse(function () use ($entrees): void {
            $sortie = fopen('php://output', 'w');
            \assert($sortie !== false);
            fputcsv($sortie, ['id', 'dateHeure', 'auteur', 'action', 'cibleType', 'cibleId', 'etablissement'], ';', '"', '\\');
            foreach ($entrees as $entree) {
                fputcsv($sortie, [
                    (string) $entree->getId(),
                    $entree->getDateHeure()->format(DATE_ATOM),
                    $entree->getAuteur() ?? '',
                    $entree->getAction(),
                    $entree->getCibleType(),
                    $entree->getCibleId() ?? '',
                    $entree->getEtablissement() !== null ? (string) $entree->getEtablissement() : '',
                ], ';', '"', '\\');
            }
            fclose($sortie);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="audit-export.csv"');

        return $response;
    }
}
