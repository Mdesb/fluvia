<?php

declare(strict_types=1);

namespace App\Caisse\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Caisse\Service\AgentPinChecker;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Réouverture d'une caisse sécurisée (CA-2). Une caisse 🔒 sécurisée (post-Z) exige le code régisseur
 * pour repasser 🟢 ouverte, autorisant une nouvelle session. Corps attendu : { "codeRegisseur": "…" }.
 *
 * ⚠ JUSQU'AU 05/11/2026 (#346), LE CODE ÉTAIT SEULEMENT EXIGÉ NON VIDE : « 0000 » rouvrait n'importe
 * quelle caisse sécurisée. Le « code régisseur » est désormais le CODE AGENT PERSONNEL du régisseur
 * de la session (`SessionCaisse::$regisseur`), comparé à son haché par `AgentPinChecker` — avec
 * blocage après cinq erreurs. La réouverture est tracée au journal, refus compris.
 *
 * @implements ProcessorInterface<SessionCaisse, SessionCaisse>
 */
final class RouvrirCaisseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly AgentPinChecker $pins,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SessionCaisse
    {
        \assert($data instanceof SessionCaisse);
        $caisse = $data->getCaisse();
        if ($caisse === null) {
            throw new UnprocessableEntityHttpException('Session sans caisse.');
        }

        $securisee = $caisse->getEtat() === EtatCaisse::Securisee;
        if ($securisee) {
            $regisseur = $data->getRegisseur();
            if (!$regisseur instanceof Utilisateur) {
                throw new UnprocessableEntityHttpException('Session sans régisseur : la caisse ne peut pas être rouverte.');
            }
            // Invoqué AVANT toute autre écriture : un refus persiste son compteur d'échecs (voir
            // `AgentPinChecker`), et ce flush-là ne doit rien emporter d'autre.
            $this->pins->assertValid(
                $regisseur,
                $this->lecteur->corps()['codeRegisseur'] ?? null,
                'cash_register.reopen',
                $data->getEtablissement()?->getId(),
                'Caisse sécurisée : code régisseur requis pour la rouvrir (CA-2).',
            );
        }

        $caisse->setEtat(EtatCaisse::Ouverte);

        $entree = $this->journal->enregistrer('cash_register.reopened', SessionCaisse::class, (string) $data->getId(), $data->getEtablissement()?->getId());
        $entree->setValeurApres([
            'caisse' => (string) $caisse->getId(),
            'etaitSecurisee' => $securisee,
            'regisseur' => $data->getRegisseur() !== null ? (string) $data->getRegisseur()->getId() : null,
        ]);

        $this->em->flush();

        return $data;
    }
}
