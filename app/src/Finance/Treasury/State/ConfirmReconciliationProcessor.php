<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\LigneEcriture;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Finance\Treasury\Service\BankReconciliationHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST `/finance/treasury/statement-lines/{id}/reconcile` (§0.6 du plan, D8 explicite §0.2 point 3).
 * Corps `{ ledgerLineIds?: [uuid] }` — défaut : `suggestedLedgerLine` si `status = suggested`.
 *
 * `ledgerLineIds` (identifiants **clients** bruts, `LigneEcriture` n'expose aucun `#[ApiResource]`) :
 * chaque id référencé **doit** appartenir au **même** `CompteComptable` que
 * `bankStatementLine.statementImport.bankAccount.ledgerAccount` — 404 sinon, défense en profondeur
 * indépendante du cloisonnement établissement (D8, §0.2 point 3 du plan : « un identifiant de ligne
 * d'écriture appartenant à un autre compte, même du même établissement, est refusé »).
 *
 * @implements ProcessorInterface<BankStatementLine, BankStatementLine>
 */
/**
 * @cloisonnement-verifie : la ligne d'écriture résolue depuis `ledgerLineIds` doit appartenir au même
 *   **compte comptable** que le compte bancaire du relevé — contrôle plus strict que l'appartenance à
 *   l'établissement, et échec fermé en 404. Le garde-fou ne reconnaît que les contrôles par
 *   établissement, d'où cette déclaration. Écrit par claude-B (FIN-4), vérifié par claude-A le 22/08.
 */
final class ConfirmReconciliationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankReconciliationHandler $handler,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BankStatementLine
    {
        \assert($data instanceof BankStatementLine);

        $acteur = $this->security->getUser();
        if (!$acteur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Auteur introuvable.');
        }

        $compteAttendu = $data->getStatementImport()?->getBankAccount()?->getLedgerAccount();

        $corps = $this->lecteur->corps();
        $idsBruts = $corps['ledgerLineIds'] ?? null;

        if (\is_array($idsBruts) && $idsBruts !== []) {
            $ledgerLines = [];
            foreach ($idsBruts as $idBrut) {
                $idNettoye = \is_string($idBrut) ? basename($idBrut) : null;
                if ($idNettoye === null || !Uuid::isValid($idNettoye)) {
                    throw new UnprocessableEntityHttpException('ledgerLineIds doit contenir des identifiants valides.');
                }
                $ligneEcriture = $this->em->find(LigneEcriture::class, Uuid::fromString($idNettoye));
                // D8 (§0.2 point 3) : la ligne doit appartenir au même compte comptable que le compte
                // bancaire — pas seulement au même établissement (défense en profondeur sur identifiant client).
                if ($ligneEcriture === null || $compteAttendu === null || !($ligneEcriture->getCompte()?->getId()->equals($compteAttendu->getId()) ?? false)) {
                    throw new NotFoundHttpException('Ligne d\'écriture introuvable dans ce périmètre.');
                }
                $ledgerLines[] = $ligneEcriture;
            }
        } elseif ($data->getStatus() === BankStatementLineStatus::Suggested && $data->getSuggestedLedgerLine() !== null) {
            $ledgerLines = [$data->getSuggestedLedgerLine()];
        } else {
            $ledgerLines = [];
        }

        return $this->handler->confirmer($data, $ledgerLines, $acteur);
    }
}
