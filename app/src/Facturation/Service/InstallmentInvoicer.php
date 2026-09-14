<?php

declare(strict_types=1);

namespace App\Facturation\Service;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Crm\Entity\Client;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\InstallmentInvoice;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\TypeDestinataire;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Dto\EcheanceSepaDue;
use App\Sepa\Entity\MandatSepa;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Émet la facture d'une échéance d'abonnement, **à sa date d'échéance et avant tout prélèvement**.
 *
 * ── LE MODÈLE, ET POURQUOI IL N'EST PAS CELUI QU'ON CROIT ───────────────────────────────────────
 *
 * La facturation est **découplée de l'encaissement** : la facture naît quand l'échéance arrive à
 * terme, et la remise SEPA n'est ensuite qu'une **tentative de paiement contre un document qui
 * existe déjà**. C'est ce que font Stripe Billing, Chargebee, Zuora ou Recurly, et ça règle deux
 * choses d'un coup : un adhérent en retard de trois mois a trois factures datées de leurs mois
 * respectifs — pas une liasse le jour de la remise — et chaque écriture tombe dans une période
 * comptable encore ouverte, ce qui évite le refus de `DirectLedgerEntryBuilder` sur période close.
 *
 * ── CE QUE CE SERVICE N'ÉCRIT PAS ───────────────────────────────────────────────────────────────
 *
 * Aucune comptabilité. Il compose une `Facture` et la confie à {@see EmettreFactureDirecteHandler},
 * qui numérote, écrit l'écriture au journal `FAC` et scelle au sens NF525 — dans une transaction
 * unique, avec un verrou contre les émissions concurrentes. Réimplémenter la moindre de ces étapes
 * produirait un second moteur d'écritures, ce que le module interdit explicitement.
 *
 * ── L'ORDRE DES DEUX ÉCRITURES, QUI EST TOUT LE SUJET ───────────────────────────────────────────
 *
 * La réservation ({@see InstallmentInvoice}) est posée **avant** l'émission, et c'est sa contrainte
 * d'unicité en base qui garantit — pas le `findOneBy` qui la précède. Si l'émission échoue ensuite,
 * la réservation est **retirée** : une échéance rejouable vaut mieux qu'une échéance verrouillée par
 * une panne technique, qui se lirait ensuite comme « déjà facturée ». Patron repris tel quel de
 * {@see \App\Subscription\Service\SubscriptionInvoicer}, éprouvé sur la facturation SaaS.
 */
final class InstallmentInvoicer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly FactureDirecteBuilder $builder,
        private readonly EmettreFactureDirecteHandler $emetteur,
    ) {
    }

    /**
     * Facture cette échéance, ou rend la réservation déjà posée si elle l'a déjà été.
     *
     * @throws UnprocessableEntityHttpException si le taux de TVA ne se résout pas, si le mandat ne
     *                                          désigne aucun client, ou si l'échéance n'a pas de montant
     */
    public function facturer(EcheanceSepaDue $echeance, Etablissement $etablissement, Utilisateur $auteur): InstallmentInvoice
    {
        // ⚠ ON RÉSOUT AVANT D'ÉCRIRE QUOI QUE CE SOIT, RÉSERVATION COMPRISE.
        //
        // C'était l'inverse : la réservation était posée, l'émission échouait sur un taux
        // introuvable, et il fallait la retirer par une requête DBAL. Résoudre d'abord supprime ce
        // va-et-vient pour toute la famille des refus de configuration — et, surtout, c'est ce qui
        // rend le refus rejouable à l'identique SANS écrire : voir `verifier()`.
        $resolu = $this->resoudre($echeance, $etablissement);

        $deja = $this->em->getRepository(InstallmentInvoice::class)->findOneBy([
            'originReference' => $echeance->referenceOrigine,
        ]);
        if ($deja instanceof InstallmentInvoice) {
            return $deja;
        }

        $reservation = (new InstallmentInvoice())
            ->setEtablissement($etablissement)
            ->setOriginReference($echeance->referenceOrigine)
            ->setIssuedAt($echeance->dateEcheance);

        try {
            $this->em->persist($reservation);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Arrivé deuxième. La facture du gagnant fait foi — et il n'en existe qu'une, ce qui est
            // exactement ce que la contrainte est là pour obtenir.
            $this->em->getConnection()->close();

            $gagnant = $this->em->getRepository(InstallmentInvoice::class)->findOneBy([
                'originReference' => $echeance->referenceOrigine,
            ]);
            \assert($gagnant instanceof InstallmentInvoice);

            return $gagnant;
        }

        try {
            $facture = $this->emettre($echeance, $etablissement, $auteur, $resolu);
        } catch (\Throwable $echec) {
            // Voir le commentaire de classe : une échéance rejouable vaut mieux qu'une échéance
            // verrouillée par un échec technique.
            $this->retirerReservation($reservation);

            throw $echec;
        }

        $reservation->setInvoiceId($facture->getId())
            ->setTotalCents((int) round(((float) $facture->getTotalTTC()) * 100));
        $this->em->flush();

        return $reservation;
    }

    /**
     * Retire la réservation après un échec d'émission, **sans jamais masquer la cause**.
     *
     * ⚠ CE N'EST PAS UN `remove()` ORM, ET C'EST TOUT LE SUJET. Une exception pendant l'émission
     * FERME l'EntityManager : y appeler `remove()`/`flush()` lève « The EntityManager is closed », et
     * c'est cette erreur-là que l'appelant voit — à la place de celle qui explique quelque chose. Je
     * m'y suis fait prendre en écrivant ce service : le vrai motif de refus était devenu invisible,
     * et le message affiché ne parlait que de la plomberie de ma propre récupération.
     *
     * Le nettoyage passe donc par la connexion DBAL, qui survit à la fermeture de l'ORM. S'il échoue
     * lui aussi, on ne dit rien de plus : la réservation reste, l'échéance sera signalée « déjà
     * facturée » au passage suivant — mais la cause d'origine, elle, remonte intacte.
     */
    private function retirerReservation(InstallmentInvoice $reservation): void
    {
        try {
            $this->em->getConnection()->delete(
                'billing_installment_invoice',
                ['id' => $reservation->getId()->toBinary()],
            );
        } catch (\Throwable) {
            // Volontairement muet : perdre la cause coûterait plus cher que la réservation orpheline.
        }
    }

    /**
     * REJOUE, SANS RIEN ÉCRIRE, TOUTES LES RÉSOLUTIONS QUE L'ÉMISSION EXIGE.
     *
     * ⚠ ELLE EXISTE PARCE QU'UN MODE À BLANC A MENTI. Le `--dry-run` de `sepa:echeances:facturer`
     * sortait juste après le plancher de date : il annonçait « 5 à facturer, 0 refus » là où le
     * passage réel refusait les cinq, faute de taux de TVA. Sur une commande qui produit des
     * documents scellés, un mode à blanc optimiste est pire que pas de mode à blanc — il fait
     * lancer le vrai passage en confiance.
     *
     * ⚠ CE N'EST PAS UNE SECONDE IMPLÉMENTATION DES CONTRÔLES, ET C'EST TOUT L'INTÉRÊT. Elle appelle
     * `resoudre()`, exactement la même méthode que l'émission — pas une copie qui aurait l'air
     * équivalente. Une vérification écrite à part diverge au premier contrôle ajouté d'un seul côté,
     * et elle diverge en silence.
     *
     * ── CE QU'ELLE NE PEUT PAS PROUVER, ET QU'IL FAUT DIRE ──────────────────────────────────────
     *
     * Elle s'arrête au seuil de l'émission. Ce qui vient après — numérotation, période comptable
     * ouverte, scellement NF525, résolution du compte de produit ligne par ligne — n'existe que dans
     * une transaction qui écrit, et `EmettreFactureDirecteHandler` ne se joue pas à blanc. Un
     * `--dry-run` vert ne promet donc pas une émission réussie : il promet que la CONFIGURATION est
     * résolvable. C'est la différence entre « rien ne bloque à ma connaissance » et « ça marchera »,
     * et la commande doit le dire à l'écran plutôt que de laisser croire l'un pour l'autre.
     *
     * @throws UnprocessableEntityHttpException le refus exact que l'émission aurait produit
     */
    public function verifier(EcheanceSepaDue $echeance, Etablissement $etablissement): void
    {
        $this->resoudre($echeance, $etablissement);
    }

    /**
     * Tout ce qu'il faut résoudre avant d'écrire : le profil, le taux, le client.
     *
     * ⚠ AJOUTER UN CONTRÔLE ICI, JAMAIS DANS `emettre()`. C'est la seule chose qui garde le mode à
     * blanc honnête : un contrôle posé plus bas ne serait pas rejoué par `verifier()`, et le
     * `--dry-run` recommencerait à annoncer des émissions que le passage réel refuse.
     *
     * @return array{profil: ProfilExploitant, taux: TauxTva, client: Client}
     */
    private function resoudre(EcheanceSepaDue $echeance, Etablissement $etablissement): array
    {
        if ($echeance->montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Échéance « %s » : montant nul ou négatif, rien à facturer.',
                $echeance->referenceOrigine,
            ));
        }

        $profil = $this->comptes->profilPour($etablissement);

        return [
            'profil' => $profil,
            'taux' => $this->tauxApplicable($echeance, $profil),
            'client' => $this->client($echeance),
        ];
    }

    /** @param array{profil: ProfilExploitant, taux: TauxTva, client: Client} $resolu */
    private function emettre(EcheanceSepaDue $echeance, Etablissement $etablissement, Utilisateur $auteur, array $resolu): Facture
    {
        ['profil' => $profil, 'taux' => $taux, 'client' => $client] = $resolu;

        $facture = new Facture();
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setEtablissement($etablissement);
        $facture->setProfilExploitant($profil);
        $facture->setCreePar($auteur);

        $raisonSociale = trim((string) $client->getRaisonSociale());
        $this->builder->appliquerDestinataire($facture, [
            'type' => '' !== $raisonSociale ? TypeDestinataire::PersonneMorale->value : TypeDestinataire::Particulier->value,
            'raisonSociale' => '' !== $raisonSociale ? $raisonSociale : null,
            'nom' => $client->getNom(),
            'prenom' => $client->getPrenom(),
            'siret' => $client->getSiret(),
            'adresse' => $client->getAdresse() ?? [],
            'clientRef' => $client->getId()->toRfc4122(),
        ]);

        $this->builder->appliquerLignes($facture, ['lignes' => [[
            'designation' => $echeance->libelle,
            'quantite' => 1,
            // Le montant de l'échéance est TTC côté SEPA — c'est ce qui sera prélevé. La ligne porte
            // donc un prix unitaire HT déduit du taux, sans quoi la facture réclamerait la TVA en plus
            // de ce que la banque débite, et les deux chiffres ne se rejoindraient jamais.
            'prixUnitaireHT' => $this->htDepuisTtc($echeance->montantCentimes, $taux),
            'tauxTva' => $taux->getId()->toRfc4122(),
        ]]]);

        $parametre = $this->comptes->parametre($profil);
        $facture->setConditionsReglement($parametre?->conditionsCompletes());

        // ⚠ L'ÉCHÉANCE DÉRIVE DE LA DATE D'ÉCHÉANCE, PAS DE `now()`. Une facture rejouée — reprise
        //    après incident, rattrapage d'un mois oublié — doit porter exactement les mêmes dates.
        $facture->setDateEcheance($echeance->dateEcheance->modify(
            sprintf('+%d days', $parametre?->getDelaiPaiementDefautJours() ?? 30),
        ));

        $this->em->persist($facture);
        $this->em->flush();

        return $this->emetteur->emettre($facture);
    }

    /**
     * Le taux applicable : celui du produit vendu, sinon le défaut de l'établissement, sinon un REFUS.
     *
     * ⚠ ON NE DEVINE JAMAIS UN TAUX. Un profil français porte couramment quatre taux actifs — 20 %,
     * 10 %, 5,5 % et hors champ : en choisir un d'office reviendrait à se tromper une fois sur quatre,
     * et l'erreur part dans une facture scellée qui ne se corrige plus, elle s'avoire.
     *
     * ⚠ ET UNE VALEUR DÉCIMALE NE DÉSIGNE PAS UN TAUX DE FAÇON UNIQUE. `Produit::$tauxTva` est une
     * chaîne (« 20.00 ») quand `LigneFacture` exige une entité `TauxTva` du profil. Deux taux peuvent
     * légitimement porter la même valeur — territoires ou catégories différents, et la catégorie est
     * portée par le taux, pas par la ligne. Une résolution ambiguë REFUSE aussi : choisir le premier
     * reviendrait à décider d'une catégorie fiscale par hasard.
     */
    private function tauxApplicable(EcheanceSepaDue $echeance, ProfilExploitant $profil): TauxTva
    {
        if ($echeance->tauxTvaValeur !== null && $echeance->tauxTvaValeur !== '') {
            $candidats = $this->em->getRepository(TauxTva::class)->findBy([
                'profilExploitant' => $profil->getId(),
                'taux' => $echeance->tauxTvaValeur,
                'actif' => true,
            ]);

            if (\count($candidats) === 1) {
                return $candidats[0];
            }
            if (\count($candidats) > 1) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Échéance « %s » : le taux %s correspond à %d taux actifs de cet exploitant. '
                    . 'Impossible de choisir sans décider d\'une catégorie fiscale au hasard.',
                    $echeance->referenceOrigine,
                    $echeance->tauxTvaValeur,
                    \count($candidats),
                ));
            }
        }

        $defaut = $this->comptes->parametre($profil)?->getTauxTvaDefaut();
        if ($defaut instanceof TauxTva) {
            return $defaut;
        }

        throw new UnprocessableEntityHttpException(sprintf(
            'Échéance « %s » : aucun taux de TVA. Le produit vendu n\'en porte pas, et aucun taux par '
            . 'défaut n\'est réglé dans Paramètres › Facturation.',
            $echeance->referenceOrigine,
        ));
    }

    private function client(EcheanceSepaDue $echeance): Client
    {
        $mandat = $this->em->getRepository(MandatSepa::class)->find($echeance->mandatId);
        $client = $mandat instanceof MandatSepa ? $mandat->getClient() : null;

        if (!$client instanceof Client) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Échéance « %s » : le mandat ne désigne aucun client, la facture n\'aurait pas de destinataire.',
                $echeance->referenceOrigine,
            ));
        }

        return $client;
    }

    /**
     * Le prix unitaire HT correspondant à un montant TTC, en chaîne décimale.
     *
     * ⚠ EN ENTIERS JUSQU'AU DERNIER MOMENT. `120.00 / 1.2` en virgule flottante binaire ne rend pas
     * `100.00` exactement, et l'écart se propage dans une facture scellée. On divise des centimes,
     * on arrondit une fois, et on formate à la fin.
     */
    private function htDepuisTtc(int $ttcCentimes, TauxTva $taux): string
    {
        $tauxCentiemes = (int) round(((float) $taux->getTaux()) * 100);
        if ($tauxCentiemes === 0) {
            return number_format($ttcCentimes / 100, 2, '.', '');
        }

        $htCentimes = (int) round($ttcCentimes * 10000 / (10000 + $tauxCentiemes));

        return number_format($htCentimes / 100, 2, '.', '');
    }
}
