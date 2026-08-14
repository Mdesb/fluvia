<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Port\ReferentielReglementInterface;
use App\Vente\Tpe\TerminalPaiementInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Encaissement scindé (RG-M2-03). Ajoute un règlement : le montant par défaut vaut le reste dû, le
 * reste à payer est recalculé après chaque ajout. Le rendu de monnaie n'est possible que sur un
 * moyen l'autorisant (espèces, RG-M2-05 / CA-9). Un règlement CB part automatiquement au TPE : seul
 * un résultat « accepté » crée le règlement, un refus/timeout n'ajoute rien (US-L2-07 / CA-10). Le
 * moyen doit être autorisé sur le point de vente (acte de régie M6, RG-M2-02).
 *
 * @phpstan-type ResultatPaiement array{paiement: Paiement|null, statutTPE: StatutTPE|null}
 */
final class PaiementHandler
{
    public function __construct(
        private readonly ReferentielReglementInterface $referentiel,
        private readonly TerminalPaiementInterface $tpe,
        private readonly PanierCalculateur $calculateur,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null}
     */
    public function encaisser(Vente $vente, array $donnees): array
    {
        if ($vente->estScellee()) {
            throw new ConflictHttpException('Vente validée : encaissement clos (NF525).');
        }

        $code = \is_string($donnees['moyen'] ?? null) ? $donnees['moyen'] : '';
        $moyen = $this->referentiel->moyen($code);
        if ($moyen === null) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen de paiement inconnu : « %s ».', $code));
        }

        $pdv = $vente->getSession()?->getPointDeVente();
        if ($pdv !== null && $pdv->getMoyensAutorises() !== [] && !$pdv->autoriseMoyen($code)) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen « %s » non autorisé sur ce point de vente (RG-M2-02).', $code));
        }

        $resteCentimes = $this->calculateur->centimes($vente->getResteAPayer());
        $montantCentimes = isset($donnees['montant'])
            ? $this->calculateur->centimes(number_format((float) $donnees['montant'], 2, '.', ''))
            : $resteCentimes;

        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant du règlement doit être strictement positif.');
        }

        $differe = ($donnees['differe'] ?? false) === true;
        if ($differe && !$moyen->autoriseDiffere) {
            throw new UnprocessableEntityHttpException('Ce moyen n\'autorise pas le paiement différé.');
        }

        // Rendu de monnaie : uniquement sur un moyen l'autorisant (espèces).
        $rendu = 0;
        if ($montantCentimes > $resteCentimes) {
            if (!$moyen->autoriseRendu) {
                throw new UnprocessableEntityHttpException('Aucun rendu de monnaie possible sur ce moyen (RG-M2-05).');
            }
            $rendu = $montantCentimes - $resteCentimes;
        }

        $paiement = new Paiement();
        $paiement->setMoyenCode($code);
        $paiement->setMontant($this->calculateur->decimal($montantCentimes));
        $paiement->setRendu($this->calculateur->decimal($rendu));
        $paiement->setDiffere($differe);

        // TPE : envoi automatique ; seul « accepté » crée le règlement (CA-10).
        if ($moyen->exigeReference) {
            $resultat = $this->tpe->demander($pdv, $paiement->getMontant());
            $paiement->setStatutTPE($resultat->statut);
            if (!$resultat->estAccepte()) {
                return ['paiement' => null, 'statutTPE' => $resultat->statut];
            }
            $paiement->setRefTPE($resultat->reference);
        }

        if (isset($donnees['banque']) && \is_string($donnees['banque'])) {
            $paiement->setBanque($donnees['banque']);
        }
        if (isset($donnees['numeroCheque']) && \is_string($donnees['numeroCheque'])) {
            $paiement->setNumeroCheque($donnees['numeroCheque']);
        }
        if (isset($donnees['id']) && \is_string($donnees['id']) && Uuid::isValid($donnees['id'])) {
            $paiement->setId(Uuid::fromString($donnees['id']));
        }

        $vente->addPaiement($paiement);
        $this->calculateur->recalculerVente($vente);

        return ['paiement' => $paiement, 'statutTPE' => $paiement->getStatutTPE()];
    }
}
