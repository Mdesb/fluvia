<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Service\ConsentementResolver;
use App\Vente\Port\HistoriqueVenteInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Fiche client 360° (US-L5-02, CA-4) : agrège en un seul appel Coordonnées, Historique, PMV, Famille
 * et Consentements RGPD (§2.4 plan-crm.md). L'historique résout la chaîne complète des identifiants
 * fusionnés (`Client.fusionneDans`) pour ne perdre aucun achat après fusion.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class FicheClient360Provider implements ProviderInterface
{
    use ResolutionClientSoiTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HistoriqueVenteInterface $historique,
        private readonly Security $security,
        private readonly ConsentementResolver $consentementResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $client = $this->resoudreClient($uriVariables['id'] ?? null);
        $this->verifierAccesSoi($client, 'crm.lire', 'crm.lire_soi');

        // Chaîne de fusion complète (A fusionné dans B fusionné dans C…) pour l'historique (§2.4).
        $chaine = [$client];
        $courant = $client;
        while ($courant->getFusionneDans() !== null) {
            $courant = $courant->getFusionneDans();
            $chaine[] = $courant;
        }
        $idsChaine = array_map(static fn (Client $c): Uuid => $c->getId(), $chaine);

        $pmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);

        $beneficiaires = $this->em->getRepository(Beneficiaire::class)->findBy(['client' => $client]);
        $familles = array_values(array_filter(array_map(
            static fn (Beneficiaire $b): ?array => $b->getFamille() === null ? null : [
                'famille' => (string) $b->getFamille()->getId(),
                'libelle' => $b->getFamille()->getLibelle(),
                'role' => $b->getRole()->value,
                'actif' => $b->estActif(),
            ],
            $beneficiaires,
        )));

        $consentements = $this->em->getRepository(Consentement::class)->findBy(['client' => $client], ['dateRecueil' => 'DESC']);
        $etatsParCanal = [];
        foreach ($consentements as $c) {
            $canal = $c->getCanal()->value;
            $etatsParCanal[$canal] ??= [
                'canal' => $canal,
                'etat' => $c->getEtat()->value,
                'dateRecueil' => $c->getDateRecueil()->format(DATE_ATOM),
                // RG-M4-07 (CA-16) : état courant réellement exploitable (accordé ET non expiré).
                'exploitable' => $this->consentementResolver->estExploitable($client, $c->getCanal()),
            ];
        }

        $achats = [];
        foreach ($this->historique->pourClients($idsChaine) as $resume) {
            foreach ($resume->roles as $clientIdStr => $roles) {
                $achats[] = [
                    'vente' => (string) $resume->venteId,
                    'numero' => $resume->numero,
                    'date' => $resume->date->format(DATE_ATOM),
                    'total' => $resume->total,
                    'client' => $clientIdStr,
                    'roles' => $roles,
                ];
            }
        }

        return new JsonResponse([
            'client' => [
                'id' => (string) $client->getId(),
                'type' => $client->getType()->value,
                'nom' => $client->getNom(),
                'prenom' => $client->getPrenom(),
                'raisonSociale' => $client->getRaisonSociale(),
                'email' => $client->getEmail(),
                'telephone' => $client->getTelephone(),
                'adresse' => $client->getAdresse(),
                'statut' => $client->getStatut()->value,
                'estMineur' => $client->estMineur(),
                'dateDerniereVisite' => $client->getDateDerniereVisite()?->format(DATE_ATOM),
                'caCumule' => $client->getCaCumule(),
            ],
            'famille' => $familles,
            'pmv' => $pmv === null ? null : [
                'solde' => $pmv->getSolde(),
                'devise' => $pmv->getDevise(),
                'statut' => $pmv->getStatut()->value,
                'dateEcheance' => $pmv->getDateEcheance()?->format('Y-m-d'),
            ],
            'consentements' => array_values($etatsParCanal),
            'historique' => $achats,
        ]);
    }

    private function resoudreClient(mixed $id): Client
    {
        $uuid = match (true) {
            $id instanceof Uuid => $id,
            \is_string($id) && Uuid::isValid($id) => Uuid::fromString($id),
            default => null,
        };
        $client = $uuid === null ? null : $this->em->getRepository(Client::class)->find($uuid);
        if (!$client instanceof Client) {
            throw new NotFoundHttpException('Client introuvable.');
        }

        return $client;
    }
}
