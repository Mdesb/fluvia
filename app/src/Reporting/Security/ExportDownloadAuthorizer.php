<?php

declare(strict_types=1);

namespace App\Reporting\Security;

use App\Organisation\Entity\Etablissement;
use App\Reporting\Entity\Export;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\EstablishmentReachability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * QUI PEUT TÉLÉCHARGER UN EXPORT — la règle, en un seul endroit.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────────────────────────
 *
 * Deux routes servent le fichier d'un export :
 *
 *     GET /reporting/exports/{id}/telecharger        ExportTelechargerController
 *     GET /api/reporting/exports/{id}/telecharger    TelechargerExportProvider  (celle de l'écran)
 *
 * La règle du §3 de `plan-reporting.md` — `reporting.lire` **et** (demandeur **ou**
 * `reporting.configurer`) — ne vivait que dans le contrôleur. Le provider ne vérifiait qu'un
 * périmètre. Mesuré le 15/09 par un test qui pose la même demande sur les deux portes :
 *
 *     directeur régional, couvre A1, n'est pas le demandeur
 *       contrôleur -> 403      provider -> 200
 *
 * Un export « réservé à son demandeur » cessait donc de l'être dès qu'on passait par la seconde
 * porte, qui est précisément celle que le frontal appelle. Le durcissement de l'audit du 06/09
 * (constat 5) ne s'appliquait qu'à une porte sur deux.
 *
 * ⚠ RECOPIER LA RÈGLE DANS LE PROVIDER AURAIT REPRODUIT LA CAUSE. Deux copies d'une règle
 * d'autorisation divergent au premier changement, et rien ne le signale — c'est exactement ce qui
 * vient de se produire, dans un module de sept fichiers. Une seule implémentation, deux appelants.
 */
final class ExportDownloadAuthorizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly EstablishmentReachability $reachability,
    ) {
    }

    /**
     * Lève si l'appelant n'a pas le droit de télécharger cet export ; ne rend rien sinon.
     *
     * ⚠ LES DEUX CODES NE SONT PAS INTERCHANGEABLES. Un refus de droit est un 403 ; un export d'un
     * autre tenant rend 404, pour ne pas confirmer son existence à qui n'a rien à y voir. C'est la
     * distinction posée par l'audit du 06/09, et elle est conservée telle quelle.
     */
    public function assertPeutTelecharger(Export $export, Utilisateur $appelant): void
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis.');
        }

        $demandeur = $export->getDemandePar();
        $estProprietaire = $demandeur !== null && $demandeur->getId()->equals($appelant->getId());
        if ($estProprietaire) {
            return;
        }

        // Un export généré pour un destinataire de `RapportPlanifie` n'a pas de `demandePar` :
        // il reste accessible à `reporting.configurer` seul (traçabilité admin, §3).
        if (!$this->security->isGranted('PERM', 'reporting.configurer')) {
            throw new AccessDeniedHttpException('Export réservé à son demandeur ou à reporting.configurer.');
        }

        // ⚠ AUDIT DU 06/09, CONSTAT 5. `reporting.configurer` ouvrait les exports de TOUS les
        // tenants, l'export ne portant aucun établissement. Un export qu'on n'a pas demandé
        // soi-même doit avoir été demandé par quelqu'un qui atteint AU MOINS UN des sites qu'on
        // atteint soi-même : c'est ce qui le rattache à un tenant — en-tête ou pas, et depuis
        // n'importe lequel de ses sites pour un administrateur de groupe.
        if (!$this->partageUnEtablissement($appelant, $demandeur)) {
            throw new NotFoundHttpException('Export introuvable.');
        }
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
            if ($etablissement instanceof Etablissement
                && $this->reachability->canReachEstablishment($demandeur, $etablissement, $maintenant)) {
                return true;
            }
        }

        return false;
    }
}
