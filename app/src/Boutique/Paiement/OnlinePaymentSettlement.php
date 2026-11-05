<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Enum\StatutTunnel;
use App\Boutique\Service\ConfirmerCommandeHandler;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Vente\Enum\StatutTPE;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * RÉGLER UNE COMMANDE EN LIGNE, PAR QUELQUE PORTE QU'ON ARRIVE (#339).
 *
 * Deux portes mènent ici : le RETOUR de l'acheteur (`RetourPaiementProcessor`, jeton de panier exigé)
 * et la NOTIFICATION du prestataire (`OnlinePaymentNotificationController`, publique par nécessité).
 * Elles doivent faire exactement la même chose, sinon la seconde devient la porte faible. D'où ce
 * service unique, tiré de l'ancien corps de `RetourPaiementProcessor` :
 *
 *  1. la référence doit être celle qu'on a initiée, et le montant celui qu'on a mémorisé ;
 *  2. l'issue vient de l'adaptateur, qui la VÉRIFIE chez le prestataire (jamais du corps reçu) ;
 *  3. une issue « acceptée » ne confirme qu'UNE fois : le jeton `paymentSettledAt` se prend par un
 *     UPDATE conditionnel — le retour et la notification arrivent ensemble, un seul encaisse ;
 *  4. une issue inconnue (« en attente ») ne propose jamais de repayer.
 */
final class OnlinePaymentSettlement
{
    /** Le guide PayFiP demande des appels unitaires (§3.5.2.6) : au plus un toutes les 5 secondes par commande. */
    private const CHECK_INTERVAL_SECONDS = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfirmerCommandeHandler $confirmerCommande,
        private readonly SelecteurPaiementEnLigne $selecteur,
    ) {
    }

    /** @param array<string, mixed> $preuve ce que le retour transporte (reçu du bouchon…) — jamais une décision */
    public function settle(SuiviCommandeEnLigne $suivi, string $reference, array $preuve, bool $throttle = false): OnlinePaymentOutcome
    {
        $panier = $suivi->getPanierOrigine();
        $vente = $suivi->getVente();
        $referenceInitiee = $suivi->getPaymentReference();
        $montantInitie = $suivi->getPaymentAmountCents();
        if (!$panier instanceof PanierEnLigne || $vente === null || $referenceInitiee === null || $montantInitie === null) {
            throw new ConflictHttpException('Aucune initiation de paiement mémorisée pour ce panier : repassez par « payer ».');
        }
        if ($reference === '' || !hash_equals($referenceInitiee, $reference)) {
            throw new UnprocessableEntityHttpException('Retour de paiement refusé : la référence ne correspond pas à la transaction initiée.');
        }

        // Déjà réglée par l'autre porte : on le dit, sans redemander au prestataire ni réencaisser.
        if ($suivi->getPaymentSettledAt() !== null) {
            return OnlinePaymentOutcome::confirmed($vente, $suivi->getStatutTunnel() !== StatutTunnel::Confirme);
        }
        if ($vente->getStatut() !== StatutVente::EnCours) {
            throw new ConflictHttpException('Aucun paiement en cours pour ce panier (RG-M3-11).');
        }

        $maintenant = new \DateTimeImmutable();
        $dernier = $suivi->getPaymentCheckedAt();
        if ($throttle && $dernier !== null && $maintenant->getTimestamp() - $dernier->getTimestamp() < self::CHECK_INTERVAL_SECONDS) {
            return OnlinePaymentOutcome::pending();
        }
        $suivi->recordPaymentCheck($maintenant);
        $this->em->flush();

        $profil = $this->resoudreProfil($panier);
        $resultat = $this->selecteur->pour($profil)->verifierRetour($reference, $montantInitie, $preuve);
        if ($resultat === null) {
            // Rien n'atteste ce retour : ni reçu du bouchon, ni réponse concordante du prestataire.
            throw new UnprocessableEntityHttpException('Retour de paiement non vérifiable : aucune preuve du prestataire ne l\'accompagne.');
        }
        if ($resultat->montantCentimes !== $montantInitie) {
            throw new UnprocessableEntityHttpException('Retour de paiement refusé : le montant attesté n\'est pas celui de la commande.');
        }

        if ($resultat->statut === StatutTPE::Timeout) {
            return OnlinePaymentOutcome::pending();
        }
        if ($resultat->statut !== StatutTPE::Accepte) {
            // CA-10 : aucun paiement enregistré, la vente reste en_cours, le panier reste valide.
            return OnlinePaymentOutcome::failed();
        }

        if (!$this->prendreLeJeton($suivi, $maintenant)) {
            // L'autre porte a gagné la course : elle encaisse, pas nous.
            $this->em->refresh($suivi);

            return OnlinePaymentOutcome::confirmed($vente, false);
        }

        $moyen = $profil->getType() === TypeExploitant::RegieDirecte ? 'payfip' : 'cb_psp';
        try {
            $conflit = $this->confirmerCommande->confirmerApresPaiementReussi($panier, $vente, $moyen, $resultat->referenceTransaction);
        } catch (\Throwable $e) {
            // Rien n'a été confirmé : le jeton se rend, sinon la commande resterait bloquée pour toujours.
            $this->rendreLeJeton($suivi);

            throw $e;
        }

        return OnlinePaymentOutcome::confirmed($vente, $conflit);
    }

    private function prendreLeJeton(SuiviCommandeEnLigne $suivi, \DateTimeImmutable $maintenant): bool
    {
        $pris = $this->em->createQuery(
            'UPDATE ' . SuiviCommandeEnLigne::class . ' s SET s.paymentSettledAt = :maintenant WHERE s.id = :id AND s.paymentSettledAt IS NULL'
        )
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->setParameter('id', $suivi->getId(), UuidType::NAME)
            ->execute();

        return $pris === 1;
    }

    private function rendreLeJeton(SuiviCommandeEnLigne $suivi): void
    {
        $this->em->createQuery('UPDATE ' . SuiviCommandeEnLigne::class . ' s SET s.paymentSettledAt = NULL WHERE s.id = :id')
            ->setParameter('id', $suivi->getId(), UuidType::NAME)
            ->execute();
    }

    public function resoudreProfil(PanierEnLigne $panier): ProfilExploitant
    {
        $etablissement = $panier->getEtablissement();
        $profils = $this->em->getRepository(ProfilExploitant::class)->findAll();
        foreach ($profils as $profil) {
            \assert($profil instanceof ProfilExploitant);
            if ($etablissement !== null && $profil->couvre($etablissement)) {
                return $profil;
            }
        }

        throw new UnprocessableEntityHttpException('Aucun profil exploitant (M6) configuré pour cet établissement.');
    }
}
