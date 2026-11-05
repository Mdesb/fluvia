<?php

declare(strict_types=1);

namespace App\Acces\Hardware\Simulated;

use App\Acces\Hardware\CredentialFormat;
use App\Acces\Hardware\CredentialNormalizer;
use App\Acces\Hardware\GateDecision;
use App\Acces\Hardware\LocalListDecider;
use Symfony\Component\Uid\Uuid;

/**
 * Une borne d'accès logicielle qui se comporte comme le matériel attendu d'un intégrateur (#337).
 *
 * Elle parle au contrat terminal de Fluvia, et à lui seul :
 *  - en ligne, elle demande la décision au serveur pour chaque présentation ;
 *  - hors connexion, elle décide sur sa liste locale (`LocalListDecider`), ouvre, et met le passage
 *    en file ;
 *  - au retour du réseau, elle remonte la file en lot, puis rafraîchit sa liste par delta.
 *
 * Elle sert à éprouver toute la chaîne sans matériel, et de modèle exécutable pour IT Cotation : ce
 * que fait cette classe est ce que doit faire une borne branchée sur Fluvia.
 *
 * ⚠ HORS CONNEXION, SEULS LES PASSAGES ACCORDÉS PARTENT EN FILE. Un refus local n'a ouvert aucune
 * porte : le remonter ferait rejouer au serveur une présentation qu'il pourrait accepter, et créerait
 * un passage qui n'a jamais eu lieu.
 */
final class SimulatedTerminal
{
    /** @var array<string, array<string, mixed>> liste locale, par identifiant canonique */
    private array $localList = [];
    private int $version = 0;
    private bool $online = true;
    /** @var list<array<string, mixed>> */
    private array $queue = [];
    /** @var list<array{equipment: string, decision: GateDecision, offline: bool}> */
    private array $gateLog = [];

    public function __construct(
        private readonly TerminalTransport $transport,
        private readonly CredentialFormat $readerFormat = CredentialFormat::Raw,
        private readonly CredentialNormalizer $normalizer = new CredentialNormalizer(),
        private readonly LocalListDecider $decider = new LocalListDecider(),
    ) {
    }

    /** Télécharge la liste complète (premier appel) ou le delta depuis la dernière version reçue. */
    public function synchronize(): int
    {
        $snapshot = $this->transport->snapshot($this->version);
        foreach ($snapshot['entrees'] as $entry) {
            $this->localList[(string) $entry['identifiant']] = $entry;
        }
        $this->version = max($this->version, (int) $snapshot['versionCourante']);

        return \count($snapshot['entrees']);
    }

    public function disconnect(): void
    {
        $this->online = false;
    }

    /**
     * Retour du réseau : remonte la file, la vide, puis rafraîchit la liste.
     *
     * @return array<string, mixed> le compte-rendu du serveur (`recus`, `resultats`)
     */
    public function reconnect(): array
    {
        $this->online = true;
        $report = $this->queue === [] ? ['recus' => 0, 'resultats' => []] : $this->transport->pushBatch($this->queue);
        $this->queue = [];
        $this->synchronize();

        return $report;
    }

    /** Un porteur présente son support au lecteur d'un équipement. */
    public function present(string $equipmentId, string $rawIdentifier, string $direction = 'entree', ?\DateTimeImmutable $at = null): GateDecision
    {
        $at ??= new \DateTimeImmutable();
        $identifier = $this->normalizer->normalize($rawIdentifier, $this->readerFormat);
        $event = [
            'equipementId' => $equipmentId,
            'identifiantSupport' => $identifier,
            'sens' => $direction,
            'cleIdempotence' => (string) Uuid::v4(),
            'horodatageBorne' => $at->format(DATE_ATOM),
        ];

        if ($this->online) {
            $response = $this->transport->validate($event);
            $decision = ($response['resultat'] ?? null) === 'valide'
                ? GateDecision::grant(max(1, (int) ($response['headcount'] ?? 1)))
                : GateDecision::deny((string) ($response['codeMotif'] ?? 'droit_invalide'), 'Refusé par le serveur.');
        } else {
            $entry = $this->localList[$identifier] ?? null;
            $decision = $this->decider->decide($entry, $equipmentId, $at);
            if ($decision->granted) {
                $this->queue[] = $event;
                if ($entry !== null && isset($entry['compostagesRestants'])) {
                    $this->localList[$identifier]['compostagesRestants'] = (int) $entry['compostagesRestants'] - $decision->headcount;
                }
            }
        }

        $this->gateLog[] = ['equipment' => $equipmentId, 'decision' => $decision, 'offline' => !$this->online];

        return $decision;
    }

    public function isOnline(): bool
    {
        return $this->online;
    }

    public function pendingCount(): int
    {
        return \count($this->queue);
    }

    /** @return array<string, mixed>|null */
    public function localEntry(string $identifier): ?array
    {
        return $this->localList[$identifier] ?? null;
    }

    /** @return list<array{equipment: string, decision: GateDecision, offline: bool}> */
    public function gateLog(): array
    {
        return $this->gateLog;
    }
}
