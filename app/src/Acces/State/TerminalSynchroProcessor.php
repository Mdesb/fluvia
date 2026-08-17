<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Enum\CodeMotifRefus;
use App\Acces\Security\TerminalPorteeChecker;
use App\Acces\Security\TerminalUtilisateur;
use App\Acces\Service\SynchroPassageHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Remontée d'un lot hors-ligne au niveau `Terminal` (POST /terminal/passages/lot, US-TERM-06/07/08,
 * CA-7/8/9/10, plan-acces-terminal.md §2.4). Généralise `SynchroPassageHandler::synchroniser()` au
 * niveau `Terminal` (plusieurs `Controleur`/`Equipement` d'un même `itboxRef`). `POST /acces/synchro`
 * reste strictement inchangé (opération, security, processor `SynchroProcessor`), rétrocompatibilité
 * totale.
 *
 * Le `terminal` est déduit du jeton authentifié, pas du corps (évite qu'une borne usurpe l'identité
 * d'une autre). Filtre de portée par lot AVANT rejeu : toute entrée hors portée est exclue du rejeu
 * (`statut = rejete`, `codeMotif = hors_portee`), jamais silencieusement ignorée.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class TerminalSynchroProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly TerminalPorteeChecker $porteeChecker,
        private readonly SynchroPassageHandler $handler,
        /** Seuil d'écart horloge suspect (CA-10, §4.4 spec — 5 min, seuil proposé non tranché). */
        private readonly int $seuilEcartHorlogeSecondes = 300,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $terminalUtilisateur = $this->security->getUser();
        if (!$terminalUtilisateur instanceof TerminalUtilisateur) {
            throw new AccessDeniedHttpException('Terminal non authentifié.');
        }

        $corps = $this->lecteur->corps();
        $lot = \is_array($corps['lot'] ?? null) ? $corps['lot'] : [];

        $equipementsPortee = $this->porteeChecker->equipementsDansPortee($terminalUtilisateur);

        $lotFiltre = [];
        $rejetesHorsPortee = [];
        $ecartsSuspects = [];
        $maintenant = new \DateTimeImmutable();

        foreach ($lot as $entree) {
            if (!\is_array($entree)) {
                continue;
            }
            $cle = (string) ($entree['cleIdempotence'] ?? '');
            $equipementId = (string) ($entree['equipementId'] ?? '');

            if (!isset($equipementsPortee[$equipementId])) {
                $rejetesHorsPortee[] = $cle;
                continue;
            }

            if (isset($entree['horodatageBorne'])) {
                try {
                    $horodatageBorne = new \DateTimeImmutable((string) $entree['horodatageBorne']);
                    if (abs($maintenant->getTimestamp() - $horodatageBorne->getTimestamp()) > $this->seuilEcartHorlogeSecondes) {
                        $ecartsSuspects[$cle] = true;
                    }
                    $entree['horodatage'] = $entree['horodatage'] ?? $entree['horodatageBorne'];
                } catch (\Exception) {
                    // horodatageBorne mal formé : ignoré pour le calcul de skew, le rejeu se chargera
                    // de la validation du champ « horodatage ».
                }
            }

            $lotFiltre[] = $entree;
        }

        $resultat = $this->handler->synchroniserPourTerminal($lotFiltre);

        $resultats = [];
        foreach ($resultat['details'] as $detail) {
            $resultats[] = [
                'cleIdempotence' => $detail['cleIdempotence'],
                'statut' => $detail['statut'],
                'codeMotif' => $detail['codeMotif'],
                'enConflit' => $detail['enConflit'],
                'ecartHorlogeSuspect' => $ecartsSuspects[$detail['cleIdempotence']] ?? false,
            ];
        }
        foreach ($rejetesHorsPortee as $cle) {
            $resultats[] = [
                'cleIdempotence' => $cle,
                'statut' => 'rejete',
                'codeMotif' => CodeMotifRefus::HorsPortee->value,
                'enConflit' => false,
                'ecartHorlogeSuspect' => false,
            ];
        }

        return new JsonResponse([
            'recus' => \count($lot),
            'resultats' => $resultats,
        ], JsonResponse::HTTP_OK);
    }
}
