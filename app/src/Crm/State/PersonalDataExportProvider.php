<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Audit\Service\JournalAudit;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Security\CustomerGroupGuard;
use App\Membership\Entity\Membership;
use App\Vente\Port\HistoriqueVenteInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /clients/{id}/personal-data-export (#344) : tout ce que l'application détient sur une
 * personne, en un document JSON téléchargeable.
 *
 * Contenu : identité et coordonnées, liens familiaux, consentements (historique complet, pas
 * seulement l'état courant), porte-monnaie et ses mouvements, abonnements (payés ou à son nom),
 * achats, demandes RGPD. Ce que l'export ne contient PAS, et que l'écran d'aide dit : les données
 * d'autres personnes (le payeur d'un abonnement n'est désigné que par son identifiant).
 *
 * Cloisonnement : la fiche doit appartenir à la collectivité de l'établissement actif — sinon 404,
 * jamais 403 (un 403 confirmerait qu'elle existe ailleurs). Chaque export est journalisé : sortir
 * les données de quelqu'un est un geste qu'on doit pouvoir retrouver.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PersonalDataExportProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerGroupGuard $guard,
        private readonly HistoriqueVenteInterface $historique,
        private readonly JournalAudit $journal,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $uuid = match (true) {
            $id instanceof Uuid => $id,
            \is_string($id) && Uuid::isValid($id) => Uuid::fromString($id),
            default => null,
        };
        $client = $uuid === null ? null : $this->em->getRepository(Client::class)->find($uuid);
        if (!$client instanceof Client) {
            throw new NotFoundHttpException('Client introuvable.');
        }
        $this->guard->assertInActiveGroup($client);

        $pmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);
        $mouvements = $pmv === null ? [] : $this->em->getRepository(MouvementPmv::class)->findBy(['pmv' => $pmv], ['dateMouvement' => 'ASC']);
        $beneficiaires = $this->em->getRepository(Beneficiaire::class)->findBy(['client' => $client]);
        $abonnements = $this->em->getRepository(Membership::class)->findBy(['payeur' => $client]);
        foreach ($beneficiaires as $b) {
            foreach ($this->em->getRepository(Membership::class)->findBy(['adherent' => $b]) as $m) {
                if (!\in_array($m, $abonnements, true)) {
                    $abonnements[] = $m;
                }
            }
        }

        $achats = [];
        foreach ($this->historique->pourClients([$client->getId()]) as $resume) {
            $achats[] = [
                'numero' => $resume->numero,
                'date' => $resume->date->format(DATE_ATOM),
                'total' => $resume->total,
                'roles' => array_values(array_unique(array_merge(...array_values($resume->roles)))),
            ];
        }

        $document = [
            'format' => 'fluvia.personal-data-export',
            'version' => 1,
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'person' => [
                'id' => (string) $client->getId(),
                'type' => $client->getType()->value,
                'civilite' => $client->getCivilite(),
                'nom' => $client->getNom(),
                'prenom' => $client->getPrenom(),
                'raisonSociale' => $client->getRaisonSociale(),
                'siret' => $client->getSiret(),
                'dateNaissance' => $client->getDateNaissance()?->format('Y-m-d'),
                'email' => $client->getEmail(),
                'telephone' => $client->getTelephone(),
                'adresse' => $client->getAdresse(),
                'regionGeographique' => $client->getRegionGeographique(),
                'statut' => $client->getStatut()->value,
                'dateCreation' => $client->getDateCreation()->format(DATE_ATOM),
                'dateDerniereVisite' => $client->getDateDerniereVisite()?->format(DATE_ATOM),
                'dateMaj' => $client->getDateMaj()?->format(DATE_ATOM),
            ],
            'family' => array_map(static fn (Beneficiaire $b): array => [
                'famille' => $b->getFamille()?->getLibelle(),
                'role' => $b->getRole()->value,
                'dateAjout' => $b->getDateAjout()->format(DATE_ATOM),
                'dateRetrait' => $b->getDateRetrait()?->format(DATE_ATOM),
            ], $beneficiaires),
            'consents' => array_map(static fn (Consentement $c): array => [
                'canal' => $c->getCanal()->value,
                'etat' => $c->getEtat()->value,
                'dateRecueil' => $c->getDateRecueil()->format(DATE_ATOM),
                'dateExpiration' => $c->getDateExpiration()?->format(DATE_ATOM),
                'source' => $c->getSource(),
                'textVersion' => $c->getTextVersion(),
            ], $this->em->getRepository(Consentement::class)->findBy(['client' => $client], ['dateRecueil' => 'ASC'])),
            'wallet' => $pmv === null ? null : [
                'solde' => $pmv->getSolde(),
                'devise' => $pmv->getDevise(),
                'statut' => $pmv->getStatut()->value,
                'dateEcheance' => $pmv->getDateEcheance()?->format('Y-m-d'),
                'mouvements' => array_map(static fn (MouvementPmv $m): array => [
                    'date' => $m->getDateMouvement()->format(DATE_ATOM),
                    'type' => $m->getType()->value,
                    'montant' => $m->getMontant(),
                    'soldeApres' => $m->getSoldeApres(),
                    'motif' => $m->getMotif(),
                ], $mouvements),
            ],
            'memberships' => array_map(static fn (Membership $m): array => [
                'statut' => $m->getStatut()->value,
                'periodicite' => $m->getPeriodicite()->value,
                'dateSouscription' => $m->getDateSouscription()->format('Y-m-d'),
                'dateDebutEngagement' => $m->getDateDebutEngagement()->format('Y-m-d'),
                'dateFinEngagement' => $m->getDateFinEngagement()->format('Y-m-d'),
                'montantCentimes' => $m->getMontantCentimes(),
                'payeParCettePersonne' => $m->getPayeur() === $client,
            ], $abonnements),
            'purchases' => $achats,
            'privacyRequests' => array_map(static fn (DemandeRGPD $d): array => [
                'type' => $d->getType()->value,
                'origin' => $d->getOrigin()->value,
                'statut' => $d->getStatut()->value,
                'dateDemande' => $d->getDateDemande()->format(DATE_ATOM),
                'dateTraitement' => $d->getDateTraitement()?->format(DATE_ATOM),
            ], $this->em->getRepository(DemandeRGPD::class)->findBy(['client' => $client], ['dateDemande' => 'ASC'])),
        ];

        $this->journal->enregistrer('crm.rgpd.export', 'Client', (string) $client->getId());
        $this->em->flush();

        $reponse = new JsonResponse($document, 200, [], false);
        $reponse->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="donnees-personnelles-%s.json"', $client->getId()));

        return $reponse;
    }
}
