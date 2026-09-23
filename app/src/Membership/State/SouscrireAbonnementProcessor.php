<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Security\CustomerReachability;
use App\Crm\Service\BeneficiaryResolver;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Service\SouscriptionAbonnementHandler;
use App\Membership\Service\SubscriptionContractSigner;
use App\Sepa\Service\IbanFormatValidator;
use App\Sepa\Service\SepaMandateSigner;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sport/abonnements/souscrire (US-SPORT-01, CA-1). Corps :
 *   { "adherent": iri|uuid, "payeur": iri|uuid, "formule": iri|uuid,
 *     "dateSouscription"?: "AAAA-MM-JJ", "dureeEngagementMois": int,
 *     "montantPremiereEcheanceCentimes"?: int,  // prorata d’entree ; 0 accepte (mois offert)
 *     "iban": string, "titulaireMandat": string }
 * L'IBAN en clair transite uniquement ici (jamais mappé Doctrine, tokenisé avant persistance, §4 du plan).
 *
 * @implements ProcessorInterface<mixed, Membership>
 */
final class SouscrireAbonnementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly SouscriptionAbonnementHandler $handler,
        private readonly ContexteEtablissement $contexte,
        private readonly BeneficiaryResolver $beneficiaires,
        private readonly CustomerReachability $customers,
        private readonly IbanFormatValidator $ibanValidator,
        private readonly SepaMandateSigner $mandateSigner,
        private readonly SubscriptionContractSigner $contractSigner,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Membership
    {
        $corps = $this->lecteur->corps();

        $payeur = $this->resoudre(Client::class, $corps['payeur'] ?? null);
        $formule = $this->resoudre(Formule::class, $corps['formule'] ?? null);
        if (!$payeur instanceof Client || !$formule instanceof Formule) {
            throw new UnprocessableEntityHttpException('« payeur » et « formule » sont requis et doivent référencer des ressources existantes.');
        }

        // ── CLOISONNEMENT DU PAYEUR (constat 5, même règle que CreerMandatSepaProcessor) ─────────
        // `payeur` est résolu par `find()` : `CustomerScope` (filtre de requête) ne protège pas un
        // processeur qui tient l'entité en main. Sans ce contrôle, un utilisateur souscrivait un
        // abonnement — mandat, échéancier, accès, billet QR — au nom du client d'un AUTRE groupe, et
        // la réponse 201 fuyait sa PII. 404 et non 403 (D3). L'adhérent désigné, lui, reste borné au
        // groupe du payeur juste en dessous : payeur atteignable + même groupe ⇒ adhérent atteignable.
        $this->customers->assertReachable($payeur);

        // ── L'ADHÉRENT EST UN CLIENT, RÉSOLU EN BÉNÉFICIAIRE — MÊME MODÈLE QUE LA CAISSE ──────
        // On ne fait plus choisir un `Beneficiaire` dans une liste (« adhérent » ne parlait à
        // personne) : on désigne un CLIENT — recherché ou créé comme au comptoir — et le serveur
        // en résout le bénéficiaire via `forPurchase` — exactement comme une vente au comptoir.
        // `adherent` absent : le payeur est l'adhérent. Cloisonnement échec fermé (404) : le
        // désigné doit partager le groupe du payeur, car forPurchase ne filtre aucun périmètre.
        $adherentDesigne = null;
        if (isset($corps['adherent'])) {
            $adherentDesigne = $this->resoudre(Client::class, $corps['adherent']);
            if (!$adherentDesigne instanceof Client) {
                // Désigné explicitement mais introuvable : on REFUSE plutôt que de retomber en
                // silence sur le payeur — une faute de saisie souscrirait pour la mauvaise personne.
                throw new UnprocessableEntityHttpException('« adherent » ne désigne aucun client existant. Retirez-le pour souscrire au nom du payeur.');
            }
        }
        if ($adherentDesigne instanceof Client
            && (string) $adherentDesigne->getGroupe()?->getId() !== (string) $payeur->getGroupe()?->getId()) {
            throw new NotFoundHttpException('Bénéficiaire introuvable.');
        }
        $adherent = $this->beneficiaires->forPurchase($payeur, $adherentDesigne);

        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        // ⚠ `periodicite` N'EST PLUS LU, ET SON ENVOI EST REFUSÉ PLUTÔT QU'IGNORÉ.
        //
        // Arbitrage de Maxime du 06/09 : le contrat gèle les termes de l'offre. La cadence vient
        // désormais de `Formule::$periodicite` — un champ déjà éditable dans la fiche produit, et
        // qui n'était lu par personne : une formule ANNUELLE souscrite ici devenait MENSUELLE.
        //
        // L'accepter en silence serait pire que de le refuser : un appelant continuerait de
        // l'envoyer, croirait fixer la cadence, et celle de la formule s'appliquerait à sa place —
        // sans erreur, sans message, et sur un prélèvement. Même raison que `montantCentimes`.
        if (isset($corps['periodicite'])) {
            throw new UnprocessableEntityHttpException(
                '« periodicite » n\'est plus accepté : la cadence vient de la formule du produit. '
                . 'Renseignez-la dans la fiche produit.',
            );
        }

        $iban = \is_string($corps['iban'] ?? null) ? $corps['iban'] : '';
        $titulaire = \is_string($corps['titulaireMandat'] ?? null) ? $corps['titulaireMandat'] : '';
        if (trim($iban) === '' || trim($titulaire) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « titulaireMandat » sont requis pour signer le mandat SEPA.');
        }
        // Format de l'IBAN (structure pays + clé mod-97) : un IBAN mal saisi était tokenisé, chiffré et
        // stocké, pour n'échouer qu'au pain.008 / rejet bancaire, loin de la saisie. On refuse ici.
        $this->ibanValidator->valider($iban);

        $dateSouscription = isset($corps['dateSouscription']) && \is_string($corps['dateSouscription'])
            ? new \DateTimeImmutable($corps['dateSouscription'])
            : new \DateTimeImmutable('today');
        $dureeEngagementMois = isset($corps['dureeEngagementMois']) ? (int) $corps['dureeEngagementMois'] : 12;
        // ⚠ `montantCentimes` N'EST PLUS LU, ET SON ENVOI EST REFUSÉ PLUTÔT QU'IGNORÉ.
        //
        // Arbitrage de Maxime du 01/09 : « il ne doit pas y avoir de prix libre. » Le prix vient
        // désormais de la grille tarifaire du produit, comme sur la boutique.
        //
        // L'accepter en silence serait pire que de le refuser : un appelant continuerait de
        // l'envoyer, croirait fixer le prix, et le tarif s'appliquerait à sa place — sans erreur,
        // sans message, et sur un prélèvement.
        if (isset($corps['montantCentimes'])) {
            throw new UnprocessableEntityHttpException(
                '« montantCentimes » n\'est plus accepté : le prix est résolu depuis la grille '
                . 'tarifaire du produit qui porte cette formule. Retirez ce champ.',
            );
        }

        // ── LE PRORATA D'ENTRÉE, FACULTATIF ───────────────────────────────────────────────────
        //
        // Une souscription en cours de période fait payer un demi-mois d'abord, puis le plein tarif.
        // C'est un MONTANT fourni, jamais un calcul fait ici : au prorata de quoi, arrondi comment,
        // à partir de quelle date sont des décisions commerciales, et une règle inventée
        // s'appliquerait en silence à toutes les souscriptions.
        //
        // ⚠ ZÉRO EST ACCEPTÉ, ET C'EST DÉLIBÉRÉ : le premier mois offert est une pratique courante.
        //    Seul un montant NÉGATIF est refusé — il n'y a pas de prélèvement négatif, et le laisser
        //    passer produirait une remise que la banque rejetterait, longtemps après la vente.
        $montantPremiereCentimes = null;
        if (isset($corps['montantPremiereEcheanceCentimes'])) {
            $montantPremiereCentimes = (int) $corps['montantPremiereEcheanceCentimes'];
            if ($montantPremiereCentimes < 0) {
                throw new UnprocessableEntityHttpException('« montantPremiereEcheanceCentimes » ne peut pas être négatif.');
            }
        }

        $existant = $this->em->getRepository(Membership::class)->findOneBy([
            'payeur' => $payeur,
            'adherent' => $adherent,
            'formule' => $formule,
            'etablissement' => $etablissement,
            'statut' => MembershipStatus::Actif,
            'dateSouscription' => $dateSouscription,
        ]);
        // ── IDEMPOTENCE : UN DOUBLE-POST NE CRÉE PAS DEUX ABONNEMENTS ─────────────────────────────
        // Sans clé d'idempotence, un double-clic ou un rejeu réseau créait deux abonnements, deux
        // mandats (RUM distincts), deux échéanciers → double prélèvement. On renvoie l'abonnement ACTIF
        // déjà créé le même jour pour le même (payeur, adhérent, formule, établissement) : on ne
        // souscrit pas deux fois la même offre pour la même personne le même jour ; un abonnement
        // résilié n'étant pas actif, une vraie re-souscription reste possible.
        // ⚠ LE CLOISONNEMENT PORTE SUR L'ENTITÉ RÉSOLUE : `$existant->getEtablissement()` la confronte à
        // l'établissement actif (le critère l'a déjà bornée ; on le confirme sur l'objet, pas seulement
        // dans la requête — même exigence que l'IDOR d'appairage du 22/08).
        if ($existant instanceof Membership && $existant->getEtablissement() === $etablissement) {
            return $existant;
        }

        $abonnement = $this->handler->souscrire(
            $adherent,
            $payeur,
            $formule,
            $etablissement,
            $dateSouscription,
            $dureeEngagementMois,
            $iban,
            $titulaire,
            $montantPremiereCentimes,
        );

        // ── SIGNATURE DU MANDAT ET DU CONTRAT (avancée, scellée — module App\Signature) ──────────
        // La souscription ne « marque » plus le mandat Actif sans preuve : elle SIGNE le mandat et
        // le contrat, et scelle les deux. L'image manuscrite arrive du tunnel (`signatureMandat` /
        // `signatureContrat`, base64) ; absente, la signature reste un consentement horodaté valide
        // (opérateur, IP, empreinte du document) — jamais moins qu'aujourd'hui, où rien n'était signé.
        $operateur = $this->security->getUser();
        $operateur = $operateur instanceof Utilisateur ? $operateur : null;
        $requete = $this->requestStack->getCurrentRequest();
        $ip = $requete?->getClientIp();
        $userAgent = $requete?->headers->get('User-Agent');
        $signatureMandat = \is_string($corps['signatureMandat'] ?? null) ? $corps['signatureMandat'] : null;
        $signatureContrat = \is_string($corps['signatureContrat'] ?? null) ? $corps['signatureContrat'] : null;

        $mandat = $abonnement->getMandatSepa();
        if ($mandat !== null) {
            $this->mandateSigner->sign($mandat, $payeur, $operateur, $signatureMandat, $ip, $userAgent);
            // ⚠ ON FLUSH ENTRE LES DEUX SIGNATURES. La séquence de la chaîne se lit en base
            // (`lastLink`) : sans ce flush, le contrat interrogerait une chaîne où la signature du
            // mandat n'est pas encore écrite, prendrait la MÊME séquence, et le second INSERT
            // violerait `uniq_electronic_signature_seq`. Mesuré : SQLSTATE 23000 sur (etab, 1).
            $this->em->flush();
        }
        $this->contractSigner->sign($abonnement, $payeur, $operateur, $signatureContrat, $ip, $userAgent);

        $this->em->flush();

        return $abonnement;
    }

    /** @param class-string $classe */
    private function resoudre(string $classe, mixed $reference): ?object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            return null;
        }

        return $this->em->getRepository($classe)->find($uuid);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
