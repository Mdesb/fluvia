<?php

declare(strict_types=1);

namespace App\Reporting\Controller;

use App\Securite\Entity\Affectation;
use App\Securite\Service\EstablishmentReachability;
use App\Reporting\Entity\Export;
use App\Reporting\Enum\StatutExport;
use App\Reporting\Service\StockageExportInterface;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /reporting/exports/{id}/telecharger` (§2.8/§3 plan-reporting.md) : streaming du fichier
 * depuis `StockageExportInterface`. `reporting.lire` + (`demandePar == user` OU
 * `reporting.configurer`) — un export généré pour un destinataire de `RapportPlanifie` (pas
 * `demandePar`) reste accessible à `reporting.configurer` uniquement (traçabilité admin).
 */
#[AsController]
final class ExportTelechargerController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly StockageExportInterface $stockage,
        private readonly EstablishmentReachability $reachability,
    ) {
    }

    #[Route('/reporting/exports/{id}/telecharger', name: 'reporting_export_telecharger', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis.');
        }

        $export = $this->em->getRepository(Export::class)->find($id);
        if (!$export instanceof Export) {
            throw new NotFoundHttpException('Export introuvable.');
        }

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);
        $estProprietaire = $export->getDemandePar() !== null && $export->getDemandePar()->getId()->equals($utilisateur->getId());
        if (!$estProprietaire && !$this->security->isGranted('PERM', 'reporting.configurer')) {
            throw new AccessDeniedHttpException('Export réservé à son demandeur ou à reporting.configurer.');
        }

        // ⚠ AUDIT DU 06/09, CONSTAT 5. `reporting.configurer` ouvrait les exports de TOUS les tenants,
        //   l'export ne portant aucun établissement. Un export qu'on n'a pas demandé soi-même doit avoir
        //   été demandé par quelqu'un qui atteint AU MOINS UN des sites qu'on atteint soi-même : c'est ce
        //   qui le rattache à un tenant — en-tête ou pas, et depuis n'importe lequel de ses sites pour un
        //   administrateur de groupe. 404, pas 403 : ne pas confirmer l'existence de l'export.
        if (!$estProprietaire && !$this->partageUnEtablissement($utilisateur, $export->getDemandePar())) {
            throw new NotFoundHttpException('Export introuvable.');
        }

        if ($export->getStatut() !== StatutExport::Genere && $export->getStatut() !== StatutExport::Envoye) {
            throw new ConflictHttpException(sprintf('Export non disponible (statut : %s).', $export->getStatut()->value));
        }
        if ($export->getCheminStockage() === null) {
            throw new NotFoundHttpException('Fichier d\'export introuvable.');
        }

        $contenu = $this->stockage->recuperer($export->getCheminStockage());

        $reponse = new Response($contenu);
        $reponse->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="export-%s.%s"', $export->getId(), $export->getFormat()->value));

        return $reponse;
    }

    private function partageUnEtablissement(Utilisateur $appelant, ?Utilisateur $demandeur): bool
    {
        if (!$demandeur instanceof Utilisateur) {
            return false;
        }

        $maintenant = new \DateTimeImmutable();
        /** @var list<Affectation> $affectations */
        $affectations = $this->em->getRepository(Affectation::class)->findBy(['utilisateur' => $appelant]);
        foreach ($affectations as $affectation) {
            $etablissement = $affectation->getEtablissement();
            if ($etablissement !== null && $this->reachability->canReachEstablishment($demandeur, $etablissement, $maintenant)) {
                return true;
            }
        }

        return false;
    }
}
