<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\TauxTva;
use App\Facturation\Entity\Facture;
use App\Securite\Entity\Utilisateur;
use App\Subscription\ApiResource\EditorBilling;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SubscriptionInvoice;
use App\Subscription\Exception\InvoicingRefusedException;
use App\Subscription\Exception\UnknownCustomerException;
use App\Subscription\Security\EditorOnly;
use App\Subscription\Service\SubscriptionInvoicer;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Émet la facture du mois pour un abonnement, depuis l'écran de facturation (ED-7).
 *
 * **Le taux de TVA est exigé, jamais deviné.** Le profil d'un exploitant français porte couramment
 * quatre taux actifs — 20 %, 10 %, 5,5 % et hors champ. En choisir un à sa place reviendrait à
 * facturer au mauvais taux, ce qui se corrige par un avoir et se voit sur une déclaration. L'écran
 * demande, le serveur refuse si on ne lui dit pas.
 *
 * **Rejouer cet appel est sans effet**, et c'est la raison d'être du registre des factures
 * d'abonnement : un second clic rend la facture déjà émise au lieu d'en produire une seconde.
 */
final class EditorBillingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly SubscriptionInvoicer $facturier,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EditorBilling
    {
        $this->editorOnly->assertEditor();

        $corps = $this->lecteur->corps();
        $abonnement = $this->abonnement($corps['subscriptionId'] ?? null);
        $mois = $this->mois($corps['month'] ?? null);
        $taux = $this->taux($corps['tauxTvaId'] ?? null);

        $auteur = $this->security->getUser();
        if (!$auteur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Une facture porte le nom de qui l\'a émise.');
        }

        try {
            $registre = $this->facturier->facturerLeMois($abonnement, $mois, $auteur, $taux);
        } catch (InvoicingRefusedException|UnknownCustomerException $refus) {
            // Messages écrits pour l'exploitant : ils disent quoi faire. Voir les deux exceptions.
            throw new UnprocessableEntityHttpException($refus->getMessage(), $refus);
        }

        return $this->rendu($abonnement, $registre);
    }

    private function rendu(Subscription $abonnement, SubscriptionInvoice $registre): EditorBilling
    {
        $ligne = new EditorBilling();
        $ligne->subscriptionId = $abonnement->getId()->toRfc4122();
        $ligne->month = $registre->getPeriodStart()->format('Y-m');
        $ligne->planLabel = $abonnement->getPlan()?->getLabel() ?? '';
        $ligne->invoiced = true;
        $ligne->invoicedCents = $registre->getTotalCents();
        $ligne->issuedAt = $registre->getIssuedAt()->format(\DateTimeInterface::ATOM);
        $ligne->invoiceNumber = $this->em->getRepository(Facture::class)->find($registre->getInvoiceId())?->getNumero();

        return $ligne;
    }

    /**
     * @cloisonnement-verifie: l abonnement appartient au tenant editeur par construction — le
     * catalogue d abonnements est global a l editeur et n a pas d etablissement a confronter. Le
     * perimetre est verifie en amont par `EditorOnly::assertEditor()`, qui rend 404 avant d atteindre
     * cette resolution.
     */
    private function abonnement(mixed $id): Subscription
    {
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('« subscriptionId » est requis.');
        }

        $abonnement = $this->em->getRepository(Subscription::class)->find(Uuid::fromString($id));
        if (!$abonnement instanceof Subscription) {
            throw new NotFoundHttpException();
        }

        return $abonnement;
    }

    private function mois(mixed $brut): \DateTimeImmutable
    {
        if (!\is_string($brut) || '' === $brut) {
            return SubscriptionInvoice::debutDeMois(new \DateTimeImmutable());
        }

        if (1 !== preg_match('/^\d{4}-\d{2}$/', $brut)) {
            throw new UnprocessableEntityHttpException('« month » s\'écrit AAAA-MM.');
        }

        $mois = \DateTimeImmutable::createFromFormat('!Y-m-d', $brut.'-01');
        if (false === $mois) {
            throw new UnprocessableEntityHttpException('« month » s\'écrit AAAA-MM.');
        }

        return $mois;
    }

    /**
     * @cloisonnement-verifie: le taux appartient au profil de l editeur, et c est
     * `FactureDirecteBuilder` qui le verifie ligne par ligne au moment de composer la facture — il
     * refuse un taux d un autre exploitant (RG-SOCLE-05). On ne redouble pas ce controle ici : deux
     * verifications de la meme regle divergent, et c est celle du module de facturation qui fait foi.
     */
    private function taux(mixed $id): ?TauxTva
    {
        if (!\is_string($id) || '' === $id) {
            // Absent : le facturier tranchera — il accepte le silence quand il n'y a qu'un taux, et
            // refuse avec un message utile quand il y en a plusieurs.
            return null;
        }

        if (!Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('« tauxTvaId » n\'est pas un identifiant valide.');
        }

        $taux = $this->em->getRepository(TauxTva::class)->find(Uuid::fromString($id));
        if (!$taux instanceof TauxTva) {
            throw new UnprocessableEntityHttpException('Ce taux de TVA n\'existe pas.');
        }

        return $taux;
    }
}
