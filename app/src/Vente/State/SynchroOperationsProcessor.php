<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Vente;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Nf525\SignataireOperation;
use App\Vente\Service\AjoutLigneHandler;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaiementHandler;
use App\Vente\Service\ValiderVenteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Remontée d'un lot d'opérations hors-ligne (POST /synchro/operations, RG-M2-08 / CA-16). Rejeu
 * chronologique par séquence locale, anti-doublon idempotent (une clé déjà remontée = no-op),
 * vérification de la chaîne serveur avant ancrage, quarantaine sur incohérence — sans casser la
 * chaîne. Chaque vente remontée est composée et validée côté serveur (donc scellée) en conservant sa
 * clé d'idempotence et ses identifiants client (« une seule remontée par session »). Corps :
 *   { "session": iri|uuid, "operations": [
 *       { "cleIdempotence": uuid, "sequenceLocale": int, "id"?: uuid,
 *         "lignes": [{"produit":…, "typeTarif":…, "quantite":…}],
 *         "paiements": [{"moyen":…, "montant":…}] } ] }
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class SynchroOperationsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurNumero $generateur,
        private readonly AjoutLigneHandler $ajout,
        private readonly PaiementHandler $paiement,
        private readonly ValiderVenteService $valider,
        private readonly ScellementHandler $scellement,
        private readonly SignataireOperation $signataire,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $session = $this->resoudreSession($corps['session'] ?? null);
        if (!$session->estOuverte()) {
            throw new ConflictHttpException('Session non ouverte : ancrage impossible.');
        }

        $operations = \is_array($corps['operations'] ?? null) ? $corps['operations'] : [];

        // Rejeu chronologique par séquence locale.
        usort($operations, static fn (array $a, array $b): int => ((int) ($a['sequenceLocale'] ?? 0)) <=> ((int) ($b['sequenceLocale'] ?? 0)));

        // Trou de séquence dans le lot → rejet (alerte de contrôle), pas d'insertion partielle.
        $this->verifierContinuite($operations);

        // Vérification de la chaîne serveur avant ancrage (CA-15/16).
        $pdv = $session->getPointDeVente();
        if ($pdv !== null) {
            $rapport = $this->signataire->verifieChaine($this->scellement->chaine($pdv));
            if (!$rapport->intacte) {
                throw new ConflictHttpException('Chaîne serveur rompue : ancrage refusé (alerte de contrôle NF525).');
            }
        }

        $inseres = [];
        $doublons = [];
        $quarantaine = [];

        foreach ($operations as $op) {
            $cle = $this->uuid($op['cleIdempotence'] ?? null);
            if ($cle === null) {
                $quarantaine[] = ['raison' => 'clé d\'idempotence manquante'];
                continue;
            }
            if ($this->em->getRepository(Vente::class)->findOneBy(['cleIdempotence' => $cle]) !== null) {
                $doublons[] = (string) $cle; // rejouer = no-op.
                continue;
            }

            try {
                $vente = $this->composer($session, $cle, $op);
                $this->em->flush();
                $inseres[] = (string) $vente->getId();
            } catch (\Throwable $e) {
                $this->em->clear();
                $session = $this->resoudreSession((string) $session->getId());
                $quarantaine[] = ['cle' => (string) $cle, 'raison' => $e->getMessage()];
            }
        }

        return new JsonResponse([
            'session' => $session->getNumero(),
            'recus' => \count($operations),
            'inseres' => $inseres,
            'doublons' => $doublons,
            'quarantaine' => $quarantaine,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $op
     */
    private function composer(SessionCaisse $session, Uuid $cle, array $op): Vente
    {
        $vente = new Vente();
        if (($id = $this->uuid($op['id'] ?? null)) !== null) {
            $vente->setId($id);
        }
        $vente->setCleIdempotence($cle)
            ->setSession($session)
            ->setEtablissement($session->getEtablissement())
            ->setNumero($this->generateur->numeroVente($session))
            ->setOrigineHorsLigne(true);
        $this->em->persist($vente);

        foreach ($op['lignes'] ?? [] as $ligne) {
            if (\is_array($ligne)) {
                $this->ajout->ajouter($vente, $ligne, false);
            }
        }
        foreach ($op['paiements'] ?? [] as $paiement) {
            if (\is_array($paiement)) {
                $this->paiement->encaisser($vente, $paiement);
            }
        }

        $this->valider->valider($vente);

        return $vente;
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function verifierContinuite(array $operations): void
    {
        $precedente = null;
        foreach ($operations as $op) {
            if (!isset($op['sequenceLocale'])) {
                continue;
            }
            $seq = (int) $op['sequenceLocale'];
            if ($precedente !== null && $seq !== $precedente + 1) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Trou de séquence dans le lot hors-ligne (attendu %d, trouvé %d) : rejet, alerte de contrôle.',
                    $precedente + 1,
                    $seq,
                ));
            }
            $precedente = $seq;
        }
    }

    private function resoudreSession(mixed $reference): SessionCaisse
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Référence de session obligatoire.');
        }
        $session = $this->em->getRepository(SessionCaisse::class)->find($uuid);
        if ($session === null) {
            throw new UnprocessableEntityHttpException('Session introuvable.');
        }

        return $session;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
