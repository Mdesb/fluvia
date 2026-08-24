<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Service\ContexteEtablissement;
use App\Subscription\ApiResource\EditorSubscription;
use App\Subscription\Entity\ProvisioningRequest;
use App\Subscription\Entity\Subscription;
use App\Subscription\Service\OfferCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Les abonnements de l'éditeur, et le contrôle qui décide qui peut les voir (ED-6, RG-ED-01).
 *
 * **Le contrôle porte sur l'identité du tenant, pas sur une permission.** Un rôle « voir les
 * abonnements » se délègue, s'hérite, se recopie dans un rôle modèle et finit par atterrir chez
 * quelqu'un qu'on n'avait pas prévu. L'appartenance au tenant éditeur, elle, ne se recopie pas :
 * l'établissement actif de la session est l'éditeur, ou il ne l'est pas.
 *
 * **404 et non 403.** Un 403 confirme à un client curieux que cet écran existe et qu'il concerne
 * l'éditeur ; un 404 ne lui apprend rien. C'est la même discipline que le reste du cloisonnement du
 * dépôt — échec fermé, et silencieux sur ce qu'il protège (D3).
 *
 * **Aucune requête ne dépend d'un identifiant fourni par le client.** Le périmètre vient de la
 * session (D3) : c'est ce qui évite la famille d'IDOR trouvée seize fois ici.
 */
final class EditorSubscriptionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly EditorTenantResolver $editorTenant,
        private readonly OfferCatalog $catalog,
    ) {
    }

    /** @return list<EditorSubscription> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        if (!$this->editorTenant->isEditor($this->contexte->etablissementActif())) {
            throw new NotFoundHttpException();
        }

        $lignes = [];

        /** @var list<Subscription> $abonnements */
        $abonnements = $this->em->getRepository(Subscription::class)->findBy([], ['createdAt' => 'DESC']);

        foreach ($abonnements as $abonnement) {
            $lignes[] = $this->decrire($abonnement);
        }

        return $lignes;
    }

    private function decrire(Subscription $abonnement): EditorSubscription
    {
        $ligne = new EditorSubscription();
        $ligne->id = $abonnement->getId()->toRfc4122();
        $ligne->status = $abonnement->getStatus()->value;
        $ligne->startedAt = $abonnement->getStartedAt()?->format(\DateTimeInterface::ATOM);

        $plan = $abonnement->getPlan();
        $ligne->planCode = $plan?->getCode() ?? '';
        $ligne->planLabel = $plan?->getLabel() ?? '';

        // Le prix affiché est recalculé depuis le catalogue, jamais stocké sur la ligne : un montant
        // figé au moment de la vente diverge du jour où un tarif change, et c'est l'écran de
        // pilotage qui mentirait.
        if (null !== $plan) {
            $capacites = $abonnement->activeCapabilities(new \DateTimeImmutable());
            $ligne->monthlyPriceCents = [] === $capacites
                ? $plan->getMonthlyPriceCents()
                : $this->catalog->monthlyPriceCents($plan, $capacites);
        }

        $client = $this->em->getRepository(Client::class)->find($abonnement->getCustomerReference());
        if ($client instanceof Client) {
            $ligne->customerName = $this->nom($client);
            $ligne->customerEmail = $client->getEmail();
        } else {
            // Fiche introuvable : on le dit plutôt que d'afficher une ligne vide que personne ne sait
            // interpréter. C'est aussi le symptôme d'un abonnement qui ne pourra pas se provisionner.
            $ligne->customerName = 'Fiche client introuvable';
        }

        $demande = $this->em->getRepository(ProvisioningRequest::class)->findOneBy(['subscription' => $abonnement]);
        if ($demande instanceof ProvisioningRequest) {
            $ligne->provisioningStatus = $demande->getStatus()->value;
            $ligne->provisioningFailure = $demande->getFailureReason();
            $ligne->establishmentName = $demande->getEstablishment()?->getNom();
        }

        return $ligne;
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
