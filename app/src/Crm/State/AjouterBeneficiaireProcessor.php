<?php

declare(strict_types=1);

namespace App\Crm\State;

use App\Crm\Security\CustomerReachability;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Enum\RoleBeneficiaire;
use App\Crm\Enum\StatutFamille;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /familles/{id}/beneficiaires (US-L5-03, CA-6) : ajout tracé et réversible. Si le client est
 * déjà membre actif d'une **autre** famille active, l'ajout reste possible mais une **alerte** est
 * renvoyée dans la réponse (le cahier ne prescrit pas de blocage, seulement une alerte affichée).
 * Corps : { "client": iri, "role"?: "...", "autorisations"?: [...] }.
 *
 * @implements ProcessorInterface<Famille, JsonResponse>
 */
final class AjouterBeneficiaireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly CustomerReachability $customers,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Famille);

        $corps = $this->lecteur->corps();
        $clientRef = $corps['client'] ?? null;
        $clientId = \is_string($clientRef) ? preg_replace('#^.*/#', '', $clientRef) : null;
        $client = \is_string($clientId) && Uuid::isValid($clientId) ? $this->em->getRepository(Client::class)->find(Uuid::fromString($clientId)) : null;
        if (!$client instanceof Client) {
            throw new UnprocessableEntityHttpException('Client introuvable ou invalide.');
        }
        // ⚠ AUDIT DU 06/09, CONSTAT 5 : le client venait du corps par `find()`. On rattachait à sa
        //   famille un client d'un autre groupe.
        $this->customers->assertReachable($client);

        $alerte = false;
        foreach ($this->em->getRepository(Beneficiaire::class)->findBy(['client' => $client]) as $existant) {
            $famille = $existant->getFamille();
            if ($existant->estActif() && $famille instanceof Famille && $famille->getStatut() === StatutFamille::Active && (string) $famille->getId() !== (string) $data->getId()) {
                $alerte = true;
            }
        }

        $role = RoleBeneficiaire::tryFrom(\is_string($corps['role'] ?? null) ? $corps['role'] : '') ?? RoleBeneficiaire::Beneficiaire;

        $beneficiaire = new Beneficiaire();
        $beneficiaire->setFamille($data);
        $beneficiaire->setClient($client);
        $beneficiaire->setRole($role);
        $beneficiaire->setAutorisations(\is_array($corps['autorisations'] ?? null) ? $corps['autorisations'] : null);
        $data->addBeneficiaire($beneficiaire);

        $this->em->persist($beneficiaire);
        $this->em->flush();

        return new JsonResponse([
            'beneficiaire' => (string) $beneficiaire->getId(),
            'famille' => (string) $data->getId(),
            'client' => (string) $client->getId(),
            'role' => $role->value,
            'alerte' => $alerte,
            'alerteMessage' => $alerte ? 'Ce client est déjà membre actif d\'une autre famille active (US-L5-03).' : null,
        ], JsonResponse::HTTP_CREATED);
    }
}
