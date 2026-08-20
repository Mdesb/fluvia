<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\RattachementEmploye;
use App\Personnel\Security\EmployeSoiVoter;
use App\Securite\Entity\Utilisateur;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH `/expense_reports` (création/édition en brouillon, §0.2 point 1 du plan, D8 explicite) :
 * revérifie **explicitement**, dans l'ordre, échec fermé à chaque étape (jamais de repli silencieux) :
 *
 * 1. `employee` référencé -> doit être l'employé du salarié courant (`EMPLOYE_SOI`, réutilise
 *    strictement `App\Personnel\Security\EmployeSoiVoter`) -> 403 sinon.
 * 2. `establishment` référencé -> doit correspondre à un `RattachementEmploye` de **cet** `employee`,
 *    actif à la date du jour (`RattachementEmploye::estActifA()`) -> 422 sinon.
 * 3. `businessProfile` référencé -> doit couvrir `establishment` (`ProfilExploitant::couvre()`) -> 422
 *    sinon.
 * 4. Défense en profondeur (D8) : `PerimetreEtablissementVerificateur::verifier()` (réutilisé hors de
 *    son module d'origine, même précédent que FIN-2) sur `establishment` -> 403 (pas 404, comportement
 *    réel du service, §7 point 10 du plan).
 *
 * Aucun événement émis ici (la création n'est pas cataloguée — seuls `submitted`/`approved`/
 * `reimbursed` le sont, §0.7 du plan).
 *
 * @implements ProcessorInterface<ExpenseReport, ExpenseReport>
 */
final class CreateExpenseReportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseReport
    {
        \assert($data instanceof ExpenseReport);
        $creation = !isset($uriVariables['id']);

        if (!$creation && $data->getStatus() !== ExpenseReportStatus::Draft) {
            throw new ConflictHttpException('Note de frais déjà soumise : plus aucune modification possible (RG-EXP-01.1).');
        }

        $employe = $data->getEmployee();
        if ($employe === null || !$this->security->isGranted(EmployeSoiVoter::ATTRIBUTE, $employe)) {
            throw new AccessDeniedHttpException("Vous ne pouvez soumettre une note de frais que pour vous-même (§4.3 spec).");
        }

        $etablissement = $data->getEstablishment();
        if ($etablissement === null || !$this->rattachementActif($employe, $etablissement)) {
            throw new UnprocessableEntityHttpException("L'établissement choisi ne correspond à aucun rattachement actif de ce salarié (§0.2 point 1 du plan).");
        }

        $profil = $data->getBusinessProfile();
        if ($profil === null || !$profil->couvre($etablissement)) {
            throw new UnprocessableEntityHttpException('Profil exploitant introuvable ou hors périmètre de cet établissement.');
        }

        // D8 — échec fermé (403, pas 404, comportement réel vérifié dans le code, §7 point 10 du plan) :
        // défense en profondeur même pour un salarié légitime (§7 point 13, redondance assumée).
        $this->perimetre->verifier($etablissement);

        $utilisateur = $this->security->getUser();
        $acteur = $utilisateur instanceof Utilisateur ? $utilisateur : null;

        if ($creation) {
            $data->setStatus(ExpenseReportStatus::Draft);
            if ($acteur !== null) {
                $data->setCreatedBy($acteur);
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    private function rattachementActif(Employe $employe, Etablissement $etablissement): bool
    {
        $aujourdhui = new \DateTimeImmutable();

        /** @var list<RattachementEmploye> $rattachements */
        $rattachements = $this->em->getRepository(RattachementEmploye::class)->findBy([
            'employe' => $employe->getId(),
            'etablissement' => $etablissement->getId(),
        ]);

        foreach ($rattachements as $rattachement) {
            if ($rattachement->estActifA($aujourdhui)) {
                return true;
            }
        }

        return false;
    }
}
