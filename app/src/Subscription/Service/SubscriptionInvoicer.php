<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Crm\Entity\Client;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\TypeDestinataire;
use App\Facturation\Service\EmettreFactureDirecteHandler;
use App\Facturation\Service\FactureDirecteBuilder;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SubscriptionInvoice;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvoicingRefusedException;
use App\Subscription\Exception\UnknownCustomerException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Émet la facture mensuelle d'un abonnement (ED-7).
 *
 * **Ce service n'écrit aucune comptabilité.** Il compose une `Facture` et la confie à
 * {@see EmettreFactureDirecteHandler}, qui numérote, écrit l'écriture comptable et scelle au sens
 * NF525 — le tout dans une transaction unique, avec un verrou contre les émissions concurrentes.
 * Réimplémenter la moindre de ces étapes ici produirait un second moteur d'écritures, ce que le
 * module de facturation interdit explicitement.
 *
 * **Un mois, une facture, et c'est la base qui l'impose.** L'unicité `(abonnement, mois)` de
 * {@see SubscriptionInvoice} est réservée **avant** l'émission : un ordonnanceur qui repasse, une
 * relance manuelle après un doute, deux exploitants qui cliquent — aucun ne doit produire un second
 * prélèvement. Une lecture préalable ne suffirait pas, deux appels concurrents la franchiraient tous
 * les deux.
 *
 * **Si l'émission échoue, la réservation est défaite.** Sans quoi un échec technique — taux de TVA
 * absent, période comptable close — condamnerait le mois : plus personne ne pourrait facturer ce
 * client, et le blocage se lirait comme « déjà facturé ». On préfère un mois rejouable à un mois
 * verrouillé par erreur.
 *
 * **On ne facture pas un abonnement qui n'est pas actif.** Un brouillon n'a pas été payé, un résilié
 * ne l'est plus. Un suspendu, en revanche, l'est toujours — la suspension coupe l'exposition des
 * modules, pas la dette (RG-ED-06).
 */
final class SubscriptionInvoicer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorTenantResolver $editorTenant,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly FactureDirecteBuilder $builder,
        private readonly EmettreFactureDirecteHandler $emetteur,
        private readonly CatalogueCapacites $capacites,
    ) {
    }

    /**
     * Facture le mois de `$quand` pour cet abonnement, ou rend la facture déjà émise.
     *
     * @throws InvoicingRefusedException si l'abonnement n'est pas facturable, ou si l'éditeur n'a pas
     *                                   de taux de TVA exploitable
     * @throws UnknownCustomerException  si la fiche client a disparu
     */
    public function facturerLeMois(
        Subscription $abonnement,
        \DateTimeImmutable $quand,
        Utilisateur $auteur,
        ?TauxTva $taux = null,
    ): SubscriptionInvoice {
        $this->assertFacturable($abonnement);

        $mois = SubscriptionInvoice::debutDeMois($quand);

        $deja = $this->em->getRepository(SubscriptionInvoice::class)->findOneBy([
            'subscription' => $abonnement,
            'periodStart' => $mois,
        ]);
        if ($deja instanceof SubscriptionInvoice) {
            return $deja;
        }

        $registre = (new SubscriptionInvoice())
            ->setSubscription($abonnement)
            ->setPeriodStart($mois);

        try {
            $this->em->persist($registre);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Arrivé deuxième. La facture du gagnant fait foi.
            $this->em->getConnection()->close();

            $gagnant = $this->em->getRepository(SubscriptionInvoice::class)->findOneBy([
                'subscription' => $abonnement,
                'periodStart' => $mois,
            ]);
            \assert($gagnant instanceof SubscriptionInvoice);

            return $gagnant;
        }

        try {
            $facture = $this->emettre($abonnement, $mois, $auteur, $taux);
        } catch (\Throwable $echec) {
            // Voir le commentaire de classe : un mois rejouable vaut mieux qu'un mois verrouillé par
            // un échec technique qui se lirait ensuite comme « déjà facturé ».
            $this->em->remove($registre);
            $this->em->flush();

            throw $echec;
        }

        $registre->setInvoiceId($facture->getId())
            ->setTotalCents((int) round(((float) $facture->getTotalTTC()) * 100));
        $this->em->flush();

        return $registre;
    }

    private function emettre(
        Subscription $abonnement,
        \DateTimeImmutable $mois,
        Utilisateur $auteur,
        ?TauxTva $taux,
    ): Facture {
        $editeur = $this->editorTenant->resolve();
        $profil = $this->comptes->profilPour($editeur);
        $taux ??= $this->tauxUnique($profil);

        $facture = new Facture();
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setEtablissement($editeur);
        $facture->setProfilExploitant($profil);
        $facture->setCreePar($auteur);

        $this->builder->appliquerDestinataire($facture, $this->destinataire($abonnement));
        $this->builder->appliquerLignes($facture, ['lignes' => $this->lignes($abonnement, $mois, $taux)]);
        $facture->setConditionsReglement($this->comptes->parametre($profil)?->conditionsCompletes());

        $this->em->persist($facture);
        $this->em->flush();

        return $this->emetteur->emettre($facture);
    }

    /**
     * Les lignes du mois : la formule, puis chaque option active.
     *
     * **Une ligne par option, et pas un total.** Le client doit reconnaître sur sa facture ce qu'il a
     * coché à la souscription ; un montant unique l'oblige à nous appeler pour comprendre, et c'est
     * l'appel qu'on ne veut pas.
     *
     * @return list<array<string, mixed>>
     */
    private function lignes(Subscription $abonnement, \DateTimeImmutable $mois, TauxTva $taux): array
    {
        $plan = $abonnement->getPlan();
        $lignes = [];

        if (null !== $plan) {
            $lignes[] = [
                'designation' => sprintf('%s — abonnement %s', $plan->getLabel(), $this->moisLisible($mois)),
                'quantite' => 1,
                'prixUnitaireHT' => $this->euros($plan->getMonthlyPriceCents()),
                'tauxTva' => $taux->getId()->toRfc4122(),
            ];
        }

        foreach ($abonnement->getItems() as $item) {
            if (!$item->isActiveAt($mois)) {
                continue;
            }

            $lignes[] = [
                'designation' => sprintf('Option %s', $this->libelle($item->getCapability())),
                'quantite' => 1,
                'prixUnitaireHT' => $this->euros($item->getUnitPriceCents()),
                'tauxTva' => $taux->getId()->toRfc4122(),
            ];
        }

        if ([] === $lignes) {
            throw new InvoicingRefusedException(
                'Cet abonnement ne porte ni formule ni option : il n\'y a rien à facturer.'
            );
        }

        return $lignes;
    }

    /** @return array<string, mixed> */
    private function destinataire(Subscription $abonnement): array
    {
        $client = $this->em->getRepository(Client::class)->find($abonnement->getCustomerReference());
        if (!$client instanceof Client) {
            throw new UnknownCustomerException(sprintf(
                'Abonnement « %s » : fiche client « %s » introuvable, la facture n\'aurait pas de destinataire.',
                $abonnement->getId()->toRfc4122(),
                $abonnement->getCustomerReference(),
            ));
        }

        $raisonSociale = trim((string) $client->getRaisonSociale());

        return [
            'type' => '' !== $raisonSociale ? TypeDestinataire::PersonneMorale->value : TypeDestinataire::Particulier->value,
            'raisonSociale' => '' !== $raisonSociale ? $raisonSociale : null,
            'nom' => $client->getNom(),
            'prenom' => $client->getPrenom(),
            // La référence permet de retrouver la fiche depuis la facture sans la dupliquer.
            'clientRef' => $client->getId()->toRfc4122(),
        ];
    }

    /**
     * Le taux de TVA de l'éditeur, quand il n'y en a qu'un.
     *
     * **On ne devine pas au-delà.** Deux taux actifs, c'est un choix commercial que ce service n'a pas
     * à trancher — facturer au mauvais taux se corrige par un avoir, et se voit sur une déclaration.
     */
    private function tauxUnique(ProfilExploitant $profil): TauxTva
    {
        /** @var list<TauxTva> $taux */
        $taux = $this->em->getRepository(TauxTva::class)->findBy(['profilExploitant' => $profil, 'actif' => true]);

        if (1 === \count($taux)) {
            return $taux[0];
        }

        throw new InvoicingRefusedException([] === $taux
            ? 'L\'éditeur n\'a aucun taux de TVA actif : configurez-en un avant de facturer.'
            : 'L\'éditeur a plusieurs taux de TVA actifs : précisez celui qui s\'applique aux abonnements.');
    }

    private function assertFacturable(Subscription $abonnement): void
    {
        // Suspendu reste facturable : la suspension coupe l'exposition des modules, pas la dette
        // (RG-ED-06). Brouillon et résilié, non.
        if (\in_array($abonnement->getStatus(), [SubscriptionStatus::Active, SubscriptionStatus::Suspended], true)) {
            return;
        }

        throw new InvoicingRefusedException(sprintf(
            'Un abonnement « %s » ne se facture pas : seul un abonnement actif ou suspendu porte une dette.',
            $abonnement->getStatus()->value,
        ));
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function libelle(string $capacite): string
    {
        // Le libellé du catalogue, jamais le code : « controle_acces » sur une facture n'aide personne.
        return $this->capacites->trouve($capacite)?->libelle ?? $capacite;
    }

    private function moisLisible(\DateTimeImmutable $mois): string
    {
        $formatteur = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            'MMMM yyyy',
        );

        return (string) $formatteur->format($mois);
    }
}
