<?php

declare(strict_types=1);

namespace App\Facturation\EventListener;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\InstallmentInvoice;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Service\ReglementFactureHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Sepa\Event\EcheancesCollecteesEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Une échéance SEPA prélevée SOLDE sa facture — et l'argent entre au grand livre.
 *
 * ── LE TROU QUE CE LOT FERME ────────────────────────────────────────────────────────────────────
 *
 * La facture d'échéance naît à l'échéance (avant le prélèvement), au journal FAC : la créance 411 est
 * débitée. Jusqu'ici, un prélèvement RÉUSSI ne produisait NI règlement NI écriture d'encaissement :
 * la créance restait débitrice au grand livre alors que l'argent était rentré. On solde donc la
 * facture sur la collecte, ce qui écrit l'encaissement (journal ENC : 512 trésorerie / 411 client) et
 * lettre la créance — exactement le geste manquant.
 *
 * ── D2 : ÉVÉNEMENT, PAS D'APPEL DIRECT ─────────────────────────────────────────────────────────
 *
 * `App\Sepa` émet `EcheancesCollecteesEvent` ; c'est ICI (Facturation) qu'on résout la pièce. Même
 * sens de dépendance et même patron que `SettleInvoiceOnIncidentResolvedListener`, qui solde sur la
 * résolution d'un impayé. On réutilise `ReglementFactureHandler` : aucun second moteur d'écritures.
 *
 * ── PAS ENCORE DE MOYEN « PRÉLÈVEMENT » AU RÉFÉRENTIEL ─────────────────────────────────────────
 *
 * Le référentiel comptable (`MoyenPaiement`) ne porte pas de code « prélèvement » ; un prélèvement
 * SEPA encaisse sur le MÊME compte de trésorerie que le virement (512). On enregistre donc le
 * règlement sous `virement` : l'écriture est comptablement JUSTE (512 / 411), seul le libellé du moyen
 * est une simplification. Un moyen dédié « prélèvement » (référentiel + mapping trésorerie par
 * exploitant) est le raffinement propre, à venir.
 */
#[AsEventListener]
final class SettleInstallmentInvoiceOnCollecteListener
{
    /** Un compte système dédié, créé paresseusement : la trace dit QUI a soldé (pas « système » tout court). */
    private const EMAIL_SYSTEME = 'systeme+sepa-encaissement@fluvia.local';

    /** Voir le docblock : un prélèvement SEPA encaisse sur le compte banque, comme un virement (512). */
    private const MOYEN = 'virement';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglementFactureHandler $reglements,
    ) {
    }

    public function __invoke(EcheancesCollecteesEvent $event): void
    {
        // Résolu paresseusement : on ne crée le compte système que s'il y a au moins une facture à
        // solder (une remise peut ne porter que des échéances non encore facturées).
        $auteur = null;

        foreach ($event->referencesOrigine as $reference) {
            $reservation = $this->em->getRepository(InstallmentInvoice::class)
                ->findOneBy(['originReference' => $reference]);
            $invoiceId = $reservation?->getInvoiceId();
            if ($invoiceId === null) {
                // Échéance sans facture émise (arriéré pas encore facturé) : rien à solder ici.
                continue;
            }

            $facture = $this->em->getRepository(Facture::class)->find($invoiceId);
            if (!$facture instanceof Facture) {
                continue;
            }

            // ⚠ IDEMPOTENCE. `enregistrer` lève un 409 sur une facture qui n'attend plus de paiement,
            // et une collecte peut être rejouée (reprise). On saute donc AVANT d'appeler, sur le même
            // critère que la garde interne du handler : un rejeu devient un no-op, pas une erreur.
            if (!\in_array(
                $facture->getStatut(),
                [StatutFacture::EnAttentePaiement, StatutFacture::PartiellementReglee],
                true,
            )) {
                continue;
            }

            try {
                $this->reglements->enregistrer(
                    $facture,
                    // Le solde restant dû = le TTC pour une facture d'échéance encore non réglée.
                    $facture->getSoldeDu(),
                    self::MOYEN,
                    $event->referenceTransmission,
                    $auteur ??= $this->auteurSysteme(),
                );
            } catch (\Throwable) {
                // ⚠ L'ÉCHEC D'UNE FACTURE NE BLOQUE PAS LES AUTRES. Une remise solde N factures ;
                // qu'une seule refuse (période comptable close, mapping trésorerie absent, écart de
                // solde…) ne doit pas laisser les N-1 autres impayées. On passe : le trou se voit
                // alors sur UNE facture au grand livre, pas en silence sur toute la remise.
            }
        }
    }

    private function auteurSysteme(): Utilisateur
    {
        $existant = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_SYSTEME]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = (new Utilisateur())
            ->setEmail(self::EMAIL_SYSTEME)
            ->setNom('Système (encaissement des échéances SEPA)')
            ->setStatut(StatutUtilisateur::Actif)
            ->setMotDePasse(bin2hex(random_bytes(32)))
            ->setRolesSecurite(['ROLE_SYSTEME']);
        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }
}
