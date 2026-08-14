<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Vente\Entity\Vente;
use App\Vente\Service\GenerateurNumero;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Ouvre un panier (POST /ventes, CA-1). Refuse hors session ouverte (RG-M2-01). La clé d'idempotence
 * (générée côté client en mode dégradé) rend l'ouverture rejouable sans doublon (RG-M2-08). Corps :
 *   { "session": iri|uuid, "client"?: uuid, "cleIdempotence"?: uuid, "origineHorsLigne"?: bool, "id"?: uuid }
 *
 * @implements ProcessorInterface<mixed, Vente>
 */
final class CreerVenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly GenerateurNumero $generateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        $corps = $this->lecteur->corps();

        // Anti-doublon idempotent : si la clé existe déjà, renvoyer la vente correspondante (no-op).
        $cle = $this->uuid($corps['cleIdempotence'] ?? null);
        if ($cle !== null) {
            $existante = $this->em->getRepository(Vente::class)->findOneBy(['cleIdempotence' => $cle]);
            if ($existante !== null) {
                return $existante;
            }
        }

        $session = $this->resoudreSession($corps['session'] ?? null);
        if (!$session->estOuverte()) {
            throw new ConflictHttpException('Aucune session ouverte : vente impossible (RG-M2-01).');
        }

        $vente = new Vente();
        if (($id = $this->uuid($corps['id'] ?? null)) !== null) {
            $vente->setId($id);
        }
        if ($cle !== null) {
            $vente->setCleIdempotence($cle);
        }
        $vente->setSession($session)
            ->setEtablissement($session->getEtablissement())
            ->setNumero($this->generateur->numeroVente($session))
            ->setOrigineHorsLigne(($corps['origineHorsLigne'] ?? false) === true);
        if (($client = $this->uuid($corps['client'] ?? null)) !== null) {
            $vente->setClient($client);
        }

        $this->em->persist($vente);
        $this->em->flush();

        return $vente;
    }

    private function resoudreSession(mixed $reference): SessionCaisse
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Référence de session obligatoire pour ouvrir une vente.');
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
