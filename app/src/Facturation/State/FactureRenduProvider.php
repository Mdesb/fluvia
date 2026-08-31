<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /factures/{id}/rendu (RG-FACT-02, CA-8) : structure de rendu normalisée, prête pour un gabarit
 * (PDF/HTML) — numéro, émetteur, destinataire, lignes, TVA ventilée, totaux, conditions de règlement,
 * mention « acquittée ». La génération d'un binaire PDF est repoussée (§7 point 10 du plan) : ce
 * provider livre la **structure de données**, pas le moteur PDF.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class FactureRenduProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesFacturation $comptes,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
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
        $facture = $uuid === null ? null : $this->em->getRepository(Facture::class)->find($uuid);
        if ($facture === null) {
            throw new NotFoundHttpException('Facture introuvable.');
        }

        // Cloisonnement (D3/D8) — la facture est résolue par un `find()` sur l'{id} d'URI, hors des
        // extensions Doctrine de lecture. `facturation.lire` doit se recalculer contre l'établissement
        // de la FACTURE, jamais contre l'en-tête X-Etablissement : sans quoi un agent `facturation.lire`
        // sur A lisait la facture de B (montants + PII destinataire : SIRET, adresse). La branche
        // `lire_soi` reste confrontée par `estLieA()` (l'utilisateur est bien lié à cette facture).
        // Échec fermé en 404 (anti-oracle : ne pas confirmer l'existence d'une facture hors périmètre).
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur || $utilisateur === null);

        $etablissement = $facture->getEtablissement();
        $codes = $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];
        $peutTout = $this->calculateur->autorise($codes, 'facturation', 'lire');
        $peutSoi = $this->security->isGranted('PERM', 'facturation.lire_soi') && $facture->estLieA($utilisateur);
        if (!$peutTout && !$peutSoi) {
            throw new NotFoundHttpException('Facture introuvable.');
        }

        $profil = $facture->getProfilExploitant();
        $emetteur = $profil !== null ? $this->comptes->parametre($profil)?->getMentionsLegalesEmetteur() : null;

        $destinataire = $facture->getDestinataire();

        // ⚠ LE TAUX VIENT DE L'INSTANTANE SCELLE, PAS DU REFERENTIEL VIVANT.
        //
        // Ce tableau melangeait deux temps : `tauxTva` etait relu en direct pendant que les trois
        // montants restaient figes. Apres un changement de taux, un client recevait une facture
        // montrant 5,5 % en face de 20 € de TVA sur 100 € HT.
        //
        // Il n'en conclut pas que le referentiel a bouge : il conclut que la facture est fausse — et
        // un document qui ne s'additionne pas EST faux. C'est le seul defaut de cette famille qu'un
        // client voit.
        //
        // Les montants sont deja figes en base et proteges par le garde d'inalterabilite. Le taux
        // etait la SEULE valeur derivee d'un referentiel ici, donc la seule a pouvoir contredire les
        // autres.
        $tauxScelle = $this->tauxParLigne($facture);

        $lignes = [];
        foreach ($facture->getLignes() as $ligne) {
            $lignes[] = [
                'designation' => $ligne->getDesignation(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaireHT' => $ligne->getPrixUnitaireHT(),
                'tauxTva' => $tauxScelle[(string) $ligne->getId()] ?? $ligne->getTauxTvaValeur(),
                'montantHT' => $ligne->getMontantHT(),
                'montantTva' => $ligne->getMontantTva(),
                'montantTTC' => $ligne->getMontantTTC(),
            ];
        }

        return new JsonResponse([
            'id' => (string) $facture->getId(),
            'numero' => $facture->getNumero(),
            'nature' => $facture->getNature()->value,
            'dateEmission' => $facture->getDateEmission()?->format(\DATE_ATOM),
            'dateEcheance' => $facture->getDateEcheance()?->format('Y-m-d'),
            'emetteur' => $emetteur ?? [],
            'destinataire' => $destinataire === null ? null : [
                'type' => $destinataire->getType()->value,
                'denomination' => $destinataire->denomination(),
                'siret' => $destinataire->getSiret(),
                'tvaIntracommunautaire' => $destinataire->getTvaIntracommunautaire(),
                'adresse' => $destinataire->getAdresse(),
            ],
            'lignes' => $lignes,
            'ventilationTva' => $facture->getVentilationTva() ?? [],
            'totalHT' => $facture->getTotalHT(),
            'totalTVA' => $facture->getTotalTVA(),
            'totalTTC' => $facture->getTotalTTC(),
            'conditionsReglement' => $facture->getConditionsReglement(),
            // ⚠ DEDUITE, PAS LUE. Maxime, 31/08 : « toute facture soldee doit porter la mention
            // acquittee, mais on ne peut pas modifier une facture. » Les deux moities ne s'excluent
            // que si on suppose que la mention doit etre ECRITE.
            //
            // Le champ stocke n'etait pose que par deux handlers (avoir, facture justificative). Une
            // facture ordinaire soldee par lettrage restait a `false` — FA-2026-00001 est `payee` et
            // son document ne la portait pas.
            //
            // On prend le plus favorable des deux : le stocke reste vrai dans ses cas legitimes — une
            // justificative est acquittee par construction — et la deduction couvre tous les autres.
            // Le stocke devient un cas particulier de la deduction, jamais son contradicteur.
            'mentionAcquittee' => $facture->isMentionAcquittee() || $this->estSoldeeParLesReglements($facture),
            'acquitteeLe' => $facture->getAcquitteeLe()?->format(\DATE_ATOM),
            'acquitteeMoyen' => $facture->getAcquitteeMoyen(),
            'acquitteeReference' => $facture->getAcquitteeReference(),
        ]);
    }

    /**
     * Le taux tel qu'il a ete SCELLE, par identifiant de ligne.
     *
     * On lit l'instantane conserve au scellement plutot que d'ajouter une colonne : un seul endroit
     * dit ce qui a ete scelle, et le rendu s'y adosse au lieu d'en faire une seconde copie qui
     * divergerait au premier correctif.
     *
     * ⚠ Vide quand le document a ete scelle AVANT qu'on ne conserve l'instantane, ou quand il n'est
     * pas encore scelle. L'appelant retombe alors sur la valeur vivante : on ne peut pas inventer ce
     * qu'on n'a pas garde, et une degradation dite vaut mieux qu'une valeur fabriquee.
     *
     * @return array<string, string>
     */
    private function tauxParLigne(Facture $facture): array
    {
        $instantane = $facture->getPayloadCanonique();

        if (!\is_array($instantane) || !\is_array($instantane['lignes'] ?? null)) {
            return [];
        }

        $taux = [];
        foreach ($instantane['lignes'] as $ligne) {
            if (\is_array($ligne) && isset($ligne['id'], $ligne['taux'])) {
                $taux[(string) $ligne['id']] = (string) $ligne['taux'];
            }
        }

        return $taux;
    }


    /**
     * La facture est-elle SOLDEE par ses reglements ?
     *
     * Comparaison en CENTIMES et non en flottants : `0.1 + 0.2 !== 0.3` en virgule flottante, et une
     * mention legale ne peut pas dependre d'un arrondi. Les montants sont des chaines decimales en
     * base, precisement pour cette raison.
     *
     * ⚠ On accepte le SURPAIEMENT comme soldant. Un client qui a paye plus que du a bel et bien
     * acquitte sa facture ; le trop-percu est un autre sujet, qui se traite par remboursement et non
     * en refusant de reconnaitre le paiement.
     *
     * Une facture dont le total est nul n'est pas « acquittee » faute de reglement : elle n'a rien a
     * acquitter. On exige donc au moins un reglement.
     */
    private function estSoldeeParLesReglements(Facture $facture): bool
    {
        $reglements = $facture->getReglements();

        if ($reglements->isEmpty()) {
            return false;
        }

        $verseCentimes = 0;
        foreach ($reglements as $reglement) {
            $verseCentimes += (int) round(((float) $reglement->getMontant()) * 100);
        }

        $duCentimes = (int) round(((float) $facture->getTotalTTC()) * 100);

        return $duCentimes > 0 && $verseCentimes >= $duCentimes;
    }

}
