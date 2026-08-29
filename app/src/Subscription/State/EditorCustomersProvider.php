<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Sepa\Entity\MandatSepa;
use App\Subscription\ApiResource\EditorCustomer;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\Security\EditorOnly;
use App\Subscription\Service\OfferCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * La fiche client de l'éditeur, assemblée en une lecture (ED-6).
 *
 * **Les clients rendus ici sont ceux du CRM de l'éditeur**, c'est-à-dire les fiches créées dans son
 * établissement. Le filtre n'est pas cosmétique : sans lui, cet écran listerait aussi les clients
 * finaux de chaque exploitant — les nageurs, les visiteurs, les joueurs — qui n'ont rien à faire
 * dans le commerce de l'éditeur et dont la lecture serait une fuite pure et simple.
 *
 * **Ce que la fiche ne va pas chercher.** Aucune donnée d'exploitation : ni ventes, ni réservations,
 * ni fréquentation. L'éditeur vend une plateforme, il ne lit pas ce qui s'y passe — et quand il doit
 * regarder, c'est par un accès d'assistance nominatif, borné et audité (ED-4).
 */
final class EditorCustomersProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly EditorTenantResolver $editorTenant,
        private readonly OfferCatalog $catalog,
        private readonly Security $securite,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $this->editorOnly->assertEditor('editor.read_customer');

        if ($operation instanceof CollectionOperationInterface) {
            return array_map([$this, 'fiche'], $this->clientsDeLediteur());
        }

        return $this->fiche($this->client($uriVariables['id'] ?? null));
    }

    /** @return list<Client> */
    private function clientsDeLediteur(): array
    {
        /** @var list<Client> $clients */
        $clients = $this->em->getRepository(Client::class)->findBy(
            ['etablissementCreation' => $this->editorTenant->resolve()],
            ['nom' => 'ASC'],
        );

        return $clients;
    }

    /**
     * @cloisonnement-verifie: l entite resolue est confrontee au perimetre juste apres sa lecture —
     * `etablissementCreation` doit etre l etablissement editeur, sinon 404. C est exactement le motif
     * attendu : on compare l etablissement DE L ENTITE, pas l en-tete de la requete. Un client d un
     * exploitant demande par son identifiant rend donc introuvable, sans distinction observable
     * d avec un identifiant qui n existe pas.
     */
    private function client(mixed $id): Client
    {
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        $client = $this->em->getRepository(Client::class)->find(Uuid::fromString($id));

        if (!$client instanceof Client || !$this->editorTenant->isEditor($client->getEtablissementCreation())) {
            throw new NotFoundHttpException();
        }

        return $client;
    }

    /** Qui porte `editor.read_billing` voit les montants et le mandat ; les autres, non. */
    private function voitLArgent(): bool
    {
        return $this->securite->isGranted('PERM', 'editor.read_billing');
    }

    private function fiche(Client $client): EditorCustomer
    {
        $vue = new EditorCustomer();
        $vue->id = $client->getId()->toRfc4122();
        $vue->name = $this->nom($client);
        $vue->email = $client->getEmail();
        $vue->phone = $client->getTelephone();

        $etablissements = [];

        foreach ($this->abonnementsDe($client) as $abonnement) {
            $demande = $this->em->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $abonnement]);
            $etablissement = $demande?->getEstablishment();

            if ($etablissement instanceof Etablissement) {
                $etablissements[$etablissement->getId()->toRfc4122()] = $etablissement;
            }

            $vue->subscriptions[] = [
                'id' => $abonnement->getId()->toRfc4122(),
                'planLabel' => $abonnement->getPlan()?->getLabel() ?? '',
                'status' => $abonnement->getStatus()->value,
                // ⚠ LA FICHE SE TAIT SUR L'ARGENT SANS `editor.read_billing`.
                //
                // Une permission sur la ressource ne suffisait pas : la fiche PORTE les montants,
                // donc autoriser l'assistance à la lire lui aurait donné le chiffre d'affaires de
                // chaque client — exactement ce que « l'argent est un rôle à part » exclut.
                //
                // Le libellé du plan et le statut restent : un agent doit savoir sur quelle offre
                // est son interlocuteur. Le prix, non — c'est une information fonctionnelle d'un
                // côté, commerciale de l'autre.
                'monthlyPriceCents' => $this->voitLArgent() ? $this->prix($abonnement) : null,
                'startedAt' => $abonnement->getStartedAt()?->format(\DateTimeInterface::ATOM),
                'provisioningStatus' => $demande?->getStatus()->value,
                'provisioningFailure' => $demande?->getFailureReason(),
                'establishmentName' => $etablissement?->getNom(),
            ];
        }

        // Même règle pour le mandat : il porte les quatre derniers chiffres de l'IBAN. Utile pour
        // reconnaître un compte au téléphone quand on traite un impayé ; sans objet pour qui n'a
        // pas à traiter d'impayés.
        $vue->mandate = $this->voitLArgent() ? $this->mandat($client) : null;
        $vue->supportAccesses = $this->acces(array_values($etablissements));

        return $vue;
    }

    /** @return list<Subscription> */
    private function abonnementsDe(Client $client): array
    {
        /** @var list<Subscription> $abonnements */
        $abonnements = $this->em->getRepository(Subscription::class)->findBy(
            ['customerReference' => $client->getId()->toRfc4122()],
            ['createdAt' => 'DESC'],
        );

        return $abonnements;
    }

    private function prix(Subscription $abonnement): int
    {
        $plan = $abonnement->getPlan();
        if (null === $plan) {
            return 0;
        }

        $capacites = $abonnement->activeCapabilities(new \DateTimeImmutable());

        return [] === $capacites
            ? $plan->getMonthlyPriceCents()
            : $this->catalog->monthlyPriceCents($plan, $capacites);
    }

    /** @return array{rum: string, last4: string, status: string, signedAt: ?string}|null */
    private function mandat(Client $client): ?array
    {
        $mandat = $this->em->getRepository(MandatSepa::class)->findOneBy(['client' => $client]);
        if (!$mandat instanceof MandatSepa) {
            return null;
        }

        return [
            'rum' => $mandat->getRum(),
            // Quatre chiffres : de quoi reconnaître un compte au téléphone, et rien de plus.
            'last4' => $mandat->getIban4Derniers(),
            'status' => $mandat->getStatut()->value,
            'signedAt' => $mandat->getDateSignature()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Etablissement> $etablissements
     *
     * @return list<array{grantee: string, reason: string, grantedAt: string, expiresAt: string, revokedAt: ?string, usable: bool}>
     */
    private function acces(array $etablissements): array
    {
        if ([] === $etablissements) {
            return [];
        }

        /** @var list<SupportAccess> $acces */
        $acces = $this->em->getRepository(SupportAccess::class)->findBy(
            ['establishment' => $etablissements],
            ['grantedAt' => 'DESC'],
        );

        $maintenant = new \DateTimeImmutable();

        return array_map(static fn (SupportAccess $a): array => [
            'grantee' => $a->getGrantee()?->getEmail() ?? '',
            'reason' => $a->getReason(),
            'grantedAt' => $a->getGrantedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $a->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'revokedAt' => $a->getRevokedAt()?->format(\DateTimeInterface::ATOM),
            'usable' => $a->isUsableAt($maintenant),
        ], $acces);
    }

    private function nom(Client $client): string
    {
        $raisonSociale = trim((string) $client->getRaisonSociale());
        if ('' !== $raisonSociale) {
            return $raisonSociale;
        }

        $nom = trim(($client->getPrenom() ?? '').' '.($client->getNom() ?? ''));

        return '' !== $nom ? $nom : 'Sans nom';
    }
}
