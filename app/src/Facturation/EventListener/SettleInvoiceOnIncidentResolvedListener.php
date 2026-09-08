<?php

declare(strict_types=1);

namespace App\Facturation\EventListener;

use App\Compta\Entity\MoyenPaiement;
use App\Compta\Service\PaymentLedgerPoster;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\ReglementFactureHandler;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Quand un impayé est réglé, la pièce qu'il portait est soldée — et l'argent entre au grand livre.
 *
 * ── POURQUOI C'EST UN ABONNÉ, ET PAS UN APPEL DEPUIS `Recouvrement` ─────────────────────────────
 *
 * D2 : communication par événements, jamais d'appel direct de module à module. `App\Recouvrement` ne
 * connaît ni `App\Facturation` ni `App\Compta` — il enregistre le canal, le moyen, la référence et une
 * **référence nue** vers la facture, puis annonce. C'est ici qu'on résout la pièce. Même sens de
 * dépendance que `App\Sport\EventListener\SynchroniserImpayeFitnessListener`, qui écoute les mêmes
 * événements pour son propre statut métier.
 *
 * ── LES DEUX CHEMINS, ET POURQUOI LE SECOND EXISTE ──────────────────────────────────────────────
 *
 * 1. **L'incident porte une facture** (né d'une échéance facturée) : on enregistre un
 *    `ReglementFacture` dessus. C'est lui qui écrit ensuite l'encaissement au journal `ENC` et lettre
 *    en groupe — on ne double aucun de ces gestes ici.
 *
 * 2. **L'incident n'en porte aucune** — tous ceux ouverts AVANT la facturation des échéances, dont
 *    l'unique incident de la préproduction. Il n'y a alors rien à solder, mais l'argent est quand même
 *    rentré : on écrit l'encaissement directement, avec le redevable en contrepartie. Ne rien écrire
 *    du tout laisserait ces règlements-là invisibles en comptabilité — le défaut exact que ce lot
 *    corrige.
 *
 * ⚠ CE SECOND CHEMIN N'INVENTE PAS DE FACTURE. Fabriquer après coup une pièce pour une dette née
 * ailleurs produirait un document opposable que personne n'a décidé d'émettre. L'écran doit dire
 * qu'aucune facture n'est soldée, pas faire semblant.
 */
#[AsEventListener]
final class SettleInvoiceOnIncidentResolvedListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglementFactureHandler $reglements,
        private readonly PaymentLedgerPoster $encaissements,
        private readonly ResolveurComptesFacturation $comptes,
    ) {
    }

    public function __invoke(IncidentImpayeResoluEvent $event): void
    {
        $incident = $event->incident;

        $moyen = $incident->getMoyenResolution();
        $auteur = $incident->getResoluPar();
        if ($moyen === null || !$auteur instanceof Utilisateur) {
            // Résolution issue d'un chemin qui ne renseigne pas ces champs — une représentation
            // bancaire réussie, par exemple. Il n'y a alors ni moyen déclaré ni acteur : on ne
            // fabrique ni l'un ni l'autre.
            return;
        }

        $montant = number_format($event->montantCentimes / 100, 2, '.', '');
        $reference = $incident->getReferenceResolution();

        $factureRef = $incident->getFactureOrigineRef();
        if ($factureRef instanceof Uuid) {
            $facture = $this->em->getRepository(Facture::class)->find($factureRef);
            if ($facture instanceof Facture) {
                $this->reglements->enregistrer($facture, $montant, $moyen, $reference, $auteur);

                return;
            }
        }

        $this->encaisserSansPiece($event, $moyen, $auteur);
    }

    /** Chemin n°2 : l'argent est rentré, mais aucune pièce ne le porte. */
    private function encaisserSansPiece(IncidentImpayeResoluEvent $event, string $codeMoyen, Utilisateur $auteur): void
    {
        $incident = $event->incident;
        $etablissement = $incident->getEtablissement();
        if ($etablissement === null) {
            return;
        }

        $moyen = $this->em->getRepository(MoyenPaiement::class)->findOneBy(['code' => $codeMoyen]);
        if (!$moyen instanceof MoyenPaiement) {
            return;
        }

        $profil = $this->comptes->profilPour($etablissement);

        // ⚠ LA CONTREPARTIE EST LE REDEVABLE, TEL QUE `Recouvrement` LE DÉSIGNE — un type et une
        //    référence opaques. On ne traduit pas : `Facturation` n'a pas à savoir ce qu'est un
        //    `crm.client` ou un `sport.abonnement_fitness`, et inventer une correspondance ici la
        //    ferait diverger de celle du module qui la possède.
        $reference = $incident->getReferenceRedevable();
        $contrepartieId = Uuid::isValid($reference) ? Uuid::fromString($reference) : null;

        $this->encaissements->post(
            $profil,
            $moyen,
            $event->montantCentimes,
            $event->date,
            $this->comptes->compteClient($profil),
            'Encaissement impayé ' . $incident->getId()->toRfc4122(),
            counterpartyType: $incident->getTypeRedevable(),
            counterpartyId: $contrepartieId,
        );
        $this->em->flush();
    }
}
