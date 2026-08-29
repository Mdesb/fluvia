<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Facturation\Entity\Facture;
use App\Subscription\ApiResource\EditorBilling;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SubscriptionInvoice;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Security\EditorOnly;
use App\Subscription\Service\SubscriptionInvoicer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * L'état de facturation du mois, abonnement par abonnement (ED-7).
 *
 * **Les lignes non facturées viennent en premier**, et ce n'est pas un tri cosmétique. Un abonnement
 * actif qu'on a oublié de facturer ne produit aucun signal : pas d'erreur, pas d'alerte, juste de
 * l'argent qui n'est jamais prélevé. Le seul endroit où il peut se voir, c'est ici, et seulement si
 * on le met devant.
 *
 * **On liste les abonnements, pas les factures.** Partir des factures ne montrerait que ce qui existe
 * — c'est-à-dire exactement ce qu'on ne cherche pas.
 */
final class EditorBillingProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly SubscriptionInvoicer $facturier,
        private readonly RequestStack $requests,
    ) {
    }

    /** @return list<EditorBilling> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $this->editorOnly->assertEditor('editor.read_billing');

        $mois = $this->moisDemande();
        $lignes = [];

        foreach ($this->abonnementsFacturables() as $abonnement) {
            $lignes[] = $this->ligne($abonnement, $mois);
        }

        // Le manque d'abord. Le reste garde l'ordre alphabétique du client.
        usort($lignes, static function (EditorBilling $a, EditorBilling $b): int {
            return [$a->invoiced, $a->customerName] <=> [$b->invoiced, $b->customerName];
        });

        return $lignes;
    }

    /**
     * Le mois demandé, ou le mois courant.
     *
     * Un paramètre illisible retombe sur le mois courant plutôt que d'échouer : cet écran sert à
     * repérer un oubli, et le faire tomber sur une URL mal formée serait le rendre indisponible au
     * moment où l'on en a besoin.
     */
    private function moisDemande(): \DateTimeImmutable
    {
        $brut = $this->requests->getCurrentRequest()?->query->get('month');

        if (\is_string($brut) && 1 === preg_match('/^\d{4}-\d{2}$/', $brut)) {
            $mois = \DateTimeImmutable::createFromFormat('!Y-m-d', $brut.'-01');
            if (false !== $mois) {
                return $mois;
            }
        }

        return SubscriptionInvoice::debutDeMois(new \DateTimeImmutable());
    }

    /**
     * Les abonnements qui portent une dette ce mois-ci.
     *
     * Actif ou suspendu : la suspension coupe l'exposition des modules, pas la dette (RG-ED-06). Un
     * brouillon n'a jamais été payé, un résilié ne l'est plus.
     *
     * @return list<Subscription>
     */
    private function abonnementsFacturables(): array
    {
        /** @var list<Subscription> $abonnements */
        $abonnements = $this->em->getRepository(Subscription::class)->findBy(
            ['status' => [SubscriptionStatus::Active, SubscriptionStatus::Suspended]],
        );

        return $abonnements;
    }

    private function ligne(Subscription $abonnement, \DateTimeImmutable $mois): EditorBilling
    {
        $ligne = new EditorBilling();
        $ligne->subscriptionId = $abonnement->getId()->toRfc4122();
        $ligne->month = $mois->format('Y-m');
        $ligne->planLabel = $abonnement->getPlan()?->getLabel() ?? '';
        $ligne->customerName = $this->nomClient($abonnement);
        // Le montant vient du facturier lui-meme : l'ecran annonce exactement ce que la facture
        // portera. Deux calculs du meme montant divergent toujours, et on ne sait plus lequel croire.
        $ligne->expectedCents = $this->facturier->montantDuMois($abonnement, $mois);

        $registre = $this->em->getRepository(SubscriptionInvoice::class)->findOneBy([
            'subscription' => $abonnement,
            'periodStart' => SubscriptionInvoice::debutDeMois($mois),
        ]);

        if ($registre instanceof SubscriptionInvoice) {
            $ligne->invoiced = true;
            $ligne->invoicedCents = $registre->getTotalCents();
            $ligne->issuedAt = $registre->getIssuedAt()->format(\DateTimeInterface::ATOM);
            $ligne->invoiceNumber = $this->em->getRepository(Facture::class)->find($registre->getInvoiceId())?->getNumero();
        }

        return $ligne;
    }


    private function nomClient(Subscription $abonnement): string
    {
        $client = $this->em->getRepository(Client::class)->find($abonnement->getCustomerReference());
        if (!$client instanceof Client) {
            // Un abonnement sans fiche ne se facturera pas : il doit apparaître, pas disparaître.
            return 'Fiche client introuvable';
        }

        $raisonSociale = trim((string) $client->getRaisonSociale());
        if ('' !== $raisonSociale) {
            return $raisonSociale;
        }

        $nom = trim(($client->getPrenom() ?? '').' '.($client->getNom() ?? ''));

        return '' !== $nom ? $nom : 'Sans nom';
    }
}
