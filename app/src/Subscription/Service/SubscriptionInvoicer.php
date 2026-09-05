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
use App\Subscription\Entity\SubscriptionItem;
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
        private readonly ProrationCalculator $prorata,
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

    /**
     * Ce que ce mois coutera, avant emission.
     *
     * **Le meme calcul que celui des lignes, et c'est le but.** Un ecran qui annonce un montant et une
     * facture qui en porte un autre est la maniere la plus sure de perdre la confiance d'un client au
     * premier prelevement — et de la perdre chez soi d'abord, quand personne ne sait lequel croire.
     * Une seule methode, deux usages.
     */
    public function montantDuMois(Subscription $abonnement, \DateTimeImmutable $quand): int
    {
        $mois = SubscriptionInvoice::debutDeMois($quand);
        $fin = $mois->modify('+1 month');
        $total = $abonnement->getPlan()?->getMonthlyPriceCents() ?? 0;

        foreach ($abonnement->getItems() as $item) {
            if (!$this->porteSurLeMois($item, $mois, $fin)) {
                continue;
            }

            // ⚠ LA MÊME GARDE QUE `lignes()`, ET ELLE MANQUAIT ICI (§8.1). Un module qui ne peut
            //   rien servir n'est pas facturé — mais cette méthode-ci calcule le montant AFFICHÉ,
            //   et elle lisait les mêmes items sans le filtre. Une facture à 0 € pour une ligne
            //   annoncée à 19 €, ou l'inverse : les deux chemins doivent répondre pareil.
            //
            // ⚠ ET LE COMMIT QUI A POSÉ L'AUTRE GARDE AFFIRMAIT « point de passage UNIQUE ». Une
            //   affirmation d'unicité se prouve en cherchant les CONCURRENTS du chemin qu'on
            //   corrige, pas en relisant celui-là. Celle-ci a été trouvée par un audit, pas par moi.
            if ($this->capacites->trouve($item->getCapability())?->peutServir === false) {
                continue;
            }

            $total += $item->getActiveFrom() > $mois
                ? $this->prorata->forPartialPeriod($item->getUnitPriceCents(), $item->getActiveFrom(), $mois, $fin)
                : $item->getUnitPriceCents();
        }

        return $total;
    }

    private function emettre(
        Subscription $abonnement,
        \DateTimeImmutable $mois,
        Utilisateur $auteur,
        ?TauxTva $taux,
    ): Facture {
        $editeur = $this->editorTenant->resolve();
        $profil = $this->comptes->profilPour($editeur);
        $taux ??= $this->tauxApplicable($profil);

        $facture = new Facture();
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setEtablissement($editeur);
        $facture->setProfilExploitant($profil);
        $facture->setCreePar($auteur);

        $this->builder->appliquerDestinataire($facture, $this->destinataire($abonnement));
        $this->builder->appliquerLignes($facture, ['lignes' => $this->lignes($abonnement, $mois, $taux)]);
        $parametre = $this->comptes->parametre($profil);
        $facture->setConditionsReglement($parametre?->conditionsCompletes());

        // L'echeance derive du MOIS FACTURE, pas de l'heure d'execution : une meme facture rejouee
        // — reprise apres incident, rattrapage d'un mois oublie — doit porter la meme date. Le delai
        // vient du parametrage de l'exploitant, qui existait et que personne ne lisait.
        $facture->setDateEcheance($mois->modify(sprintf('+%d days', $parametre?->getDelaiPaiementDefautJours() ?? 30)));

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

        $finDuMois = $mois->modify('+1 month');

        foreach ($abonnement->getItems() as $item) {
            if (!$this->porteSurLeMois($item, $mois, $finDuMois)) {
                continue;
            }

            // ⚠ ON NE FACTURE PAS UN MODULE QUI NE PEUT RIEN SERVIR (§8.1). `OfferCatalog` empêche
            //   désormais d'en acheter un ; cette garde couvre l'abonnement souscrit AVANT.
            //
            // ⚠ Aujourd'hui elle n'a rien à corriger : `subscription_item` ne porte aucune de ces
            //   trois capacités (mesuré le 04/09). Elle est posée pour que la décision tienne des
            //   deux côtés, pas parce qu'un cas vivant l'exige — et c'est dit plutôt que sous-entendu.
            if ($this->capacites->trouve($item->getCapability())?->peutServir === false) {
                continue;
            }

            $depuis = $item->getActiveFrom();
            $partiel = $depuis > $mois;

            // Une option achetee le 24 se facture au prorata, pas pour rien. C'est CA-4, et le calcul
            // existe deja : `ProrationCalculator` compte des jours entiers, la journee d'achat due.
            $montant = $partiel
                ? $this->prorata->forPartialPeriod($item->getUnitPriceCents(), $depuis, $mois, $finDuMois)
                : $item->getUnitPriceCents();

            if (0 === $montant) {
                continue;
            }

            $lignes[] = [
                'designation' => $partiel
                    ? sprintf('Option %s — a partir du %s', $this->libelle($item->getCapability()), $depuis->format('d/m/Y'))
                    : sprintf('Option %s', $this->libelle($item->getCapability())),
                'quantite' => 1,
                'prixUnitaireHT' => $this->euros($montant),
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
            // ⚠ SIRET ET ADRESSE : MENTIONS LEGALES OBLIGATOIRES, ET ELLES ETAIENT OMISES.
            //
            // La fiche client les porte — `EmissionFactureJustificativeHandler` les recopie deja sur
            // son propre chemin. Ici, elles etaient simplement absentes de la liste, et personne ne
            // s'en apercevait parce que RG-FACT-08 n'avait aucun appelant : rien ne demandait jamais
            // si la facture etait complete.
            //
            // Sans elles, les factures d'abonnement de Fluvia a ses PROPRES clients ne comportaient
            // ni SIRET ni adresse du destinataire.
            'siret' => $client->getSiret(),
            'adresse' => $client->getAdresse() ?? [],
            // La référence permet de retrouver la fiche depuis la facture sans la dupliquer.
            'clientRef' => $client->getId()->toRfc4122(),
        ];
    }

    /**
     * Le taux de TVA applicable aux abonnements.
     *
     * **Il se lit dans le parametrage, il ne se devine pas.** Un profil francais porte couramment
     * quatre taux actifs — 20 %, 10 %, 5,5 % et hors champ. En choisir un d office reviendrait a se
     * tromper une fois sur quatre, et l erreur se corrige par un avoir apres avoir ete declaree.
     *
     * **`null` veut dire « non decide », jamais « exonere ».** Les deux se ressemblent et n ont rien
     * a voir : l exoneration se dit par un taux a **zero**, qui facture normalement et porte sa
     * mention legale. Un parametre vide, lui, est une decision que personne n a prise — on refuse, et
     * on dit ou la prendre.
     */
    private function tauxApplicable(ProfilExploitant $profil): TauxTva
    {
        $taux = $this->comptes->parametre($profil)?->getTauxTvaAbonnement();

        if ($taux instanceof TauxTva) {
            return $taux;
        }

        throw new InvoicingRefusedException(
            'Aucun taux de TVA n est designe pour les abonnements. Choisissez-le dans le parametrage '
            .'de facturation de l editeur : il n y a pas de valeur par defaut, parce qu en poser une '
            .'reviendrait a facturer au hasard parmi les taux actifs.'
        );
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

    /**
     * L'option est-elle vendue a un moment quelconque de ce mois ?
     *
     * **Pas « active au premier du mois ».** C'etait la premiere version, et elle ne facturait pas du
     * tout une option achetee en cours de mois : le client l'utilisait sans jamais la payer, et rien
     * ne le signalait. Une option compte des qu'elle chevauche le mois, ne serait-ce que d'un jour.
     */
    private function porteSurLeMois(SubscriptionItem $item, \DateTimeImmutable $debut, \DateTimeImmutable $fin): bool
    {
        if ($item->getActiveFrom() >= $fin) {
            return false;
        }

        $jusqua = $item->getActiveTo();

        return null === $jusqua || $jusqua > $debut;
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
