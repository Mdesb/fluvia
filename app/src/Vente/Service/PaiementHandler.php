<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Port\PorteMonnaieVirtuelInterface;
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
 * ── ⚠ CE QUE LA CLÉ D'IDEMPOTENCE NE FERME PAS, ET IL FAUT LE SAVOIR ───────────────────────────
 *
 * Elle ferme le cas fréquent : le terminal a ACCEPTÉ, la réponse HTTP s'est perdue, le client rejoue.
 * Un `Paiement` porte la clé, le rejeu le retrouve, la carte ne repasse pas.
 *
 * **Elle ne ferme pas le cas inverse, et c'est le dangereux.** Sur un refus ou un timeout, CA-10
 * interdit de créer un `Paiement` — la clé n'est donc écrite nulle part, et un rejeu redemande au
 * terminal. Pour un refus c'est juste : aucun argent n'a bougé. Pour un TIMEOUT, le terminal a pu
 * accepter pendant qu'on cessait de l'attendre : le rejeu débite une seconde fois.
 *
 * Le fermer demande une trace de TENTATIVE écrite AVANT l'appel au terminal, et un adaptateur TPE
 * capable de répondre « qu'est-il arrivé à la transaction T ? ». Le mock ne le peut pas — il n'a pas
 * de mémoire — donc ce lot attend l'adaptateur réel, et pas l'inverse.
 *
 * @phpstan-type ResultatPaiement array{paiement: Paiement|null, statutTPE: StatutTPE|null}
 */
final class PaiementHandler
{
    public function __construct(
        private readonly ReferentielReglementInterface $referentiel,
        private readonly TerminalPaiementInterface $tpe,
        private readonly PanierCalculateur $calculateur,
        // Frontière M4 (RG-M4-03, CA-8, §2.3 plan-crm.md) : nullable pour préserver le comportement
        // d'origine (moyen `pmv` traité comme un code de règlement ordinaire) si aucun port n'est
        // câblé — ne casse aucun test M2 existant qui n'exerce pas le moyen `pmv` en détail.
        private readonly ?PorteMonnaieVirtuelInterface $pmv = null,
        // PAY-3 — nullable pour la même raison que `$pmv` : les tests unitaires qui construisent ce
        // gestionnaire à la main n'ont pas à connaître le bus d'événements pour exercer un rendu de
        // monnaie. En service câblé, il est toujours présent.
        private readonly ?CardRejectionRecorder $refusCarte = null,
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

        // ── LE REJEU NE REPASSE PAS LA CARTE ───────────────────────────────────────────────────
        //
        // Deux effets irréversibles partent AVANT qu'une seule ligne soit écrite : le débit du
        // porte-monnaie virtuel (plus bas) et l'ordre au TPE. Une réponse perdue — nginx coupe à
        // 60 s et une transaction carte réelle dépasse ce délai — puis un client qui rejoue, et on
        // encaissait deux fois. Rien ne l'empêchait : `Vente` porte une clé d'idempotence depuis
        // l'origine, le RÈGLEMENT n'en avait aucune.
        //
        // ⚠ **La garde est ICI, pas juste avant le TPE.** Placée là, elle laisserait le débit PMV se
        // rejouer — l'autre effet, celui qu'on ne regarde pas parce qu'il ne s'appelle pas « carte ».
        // Le critère qui la place : tout ce qui sort du processus doit se trouver APRÈS cette ligne.
        //
        // L'identifiant fourni compte comme une clé lui aussi. `$donnees['id']` existait déjà pour le
        // rejeu hors-ligne et n'était protégé que par la clé primaire : le doublon était donc refusé
        // au `flush()`, c'est-à-dire une fois la carte débitée.
        $dejaEncaisse = $this->rejeu($vente, $donnees);
        if ($dejaEncaisse !== null) {
            return ['paiement' => $dejaEncaisse, 'statutTPE' => $dejaEncaisse->getStatutTPE()];
        }

        $code = \is_string($donnees['moyen'] ?? null) ? $donnees['moyen'] : '';
        $moyen = $this->referentiel->moyen($code);
        if ($moyen === null) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen de paiement inconnu : « %s ».', $code));
        }

        // D44-bis — porté par la vente : une vente directe n'a pas de session d'où le déduire.
        $pdv = $vente->getPointDeVente();
        if ($pdv !== null && $pdv->getMoyensAutorises() !== [] && !$pdv->autoriseMoyen($code)) {
            throw new UnprocessableEntityHttpException(sprintf('Moyen « %s » non autorisé sur ce point de vente (RG-M2-02).', $code));
        }

        // D44-bis — **la phrase qui justifie toute la vente directe** : sans espèces, il n'y a rien à
        // compter, donc rien à clôturer, donc pas besoin de session. Ce contrôle est ce qui rend cette
        // phrase vraie ; sans lui, on aurait ouvert un chemin pour encaisser du liquide sans fonds de
        // caisse, sans Z et sans personne pour en répondre.
        //
        // Le critère est l'absence de session, pas un drapeau sur la vente : c'est la même chose, mais
        // celle-là ne peut pas être requalifiée après coup.
        if ($vente->getSession() === null && $moyen->estFiduciaire()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Moyen « %s » impossible hors session de caisse : une vente directe n\'encaisse pas d\'espèces (D44-bis).',
                $code,
            ));
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

        // PMV comme moyen de paiement en caisse (RG-M4-03, CA-8, frontière M4 §2.3 plan-crm.md) :
        // débit atomique avant tout enregistrement de règlement ; un refus (solde insuffisant, PMV
        // expiré/inexistant) bloque le règlement sans créer de Paiement (un paiement partiel PMV +
        // complément par un autre moyen reste possible : c'est l'appelant qui scinde le montant).
        if ($code === 'pmv' && $this->pmv !== null) {
            $clientId = $vente->getClient();
            if ($clientId === null) {
                throw new UnprocessableEntityHttpException('PMV indisponible : aucun client rattaché à la vente (RG-M4-03).');
            }
            $resultatPmv = $this->pmv->debiter($clientId, $this->calculateur->decimal($montantCentimes), $vente->getId());
            if (!$resultatPmv->reussi) {
                throw new UnprocessableEntityHttpException($resultatPmv->motifRefus ?? 'Débit PMV refusé (RG-M4-03).');
            }
        }

        $paiement = new Paiement();
        $paiement->setMoyenCode($code);
        $paiement->setMontant($this->calculateur->decimal($montantCentimes));
        $paiement->setRendu($this->calculateur->decimal($rendu));
        $paiement->setDiffere($differe);
        $paiement->setCleIdempotence($this->uuidOuNull($donnees['cleIdempotence'] ?? null));

        // TPE : envoi automatique ; seul « accepté » crée le règlement (CA-10).
        if ($moyen->exigeReference) {
            $resultat = $this->tpe->demander($pdv, $paiement->getMontant());
            $paiement->setStatutTPE($resultat->statut);
            if (!$resultat->estAccepte()) {
                // PAY-3 — **le refus est un fait, et il ne laissait aucune trace.** Aucun `Paiement`
                // n'est créé (c'est la règle CA-10), donc jusqu'ici la seule chose qui restait d'une
                // carte refusée était un code de statut dans une réponse HTTP que personne ne
                // conserve. On l'écrit, puis on l'annonce — dans cet ordre, voir le service.
                $this->refusCarte?->consigner(
                    $vente,
                    $code,
                    $paiement->getMontant(),
                    $resultat->statut,
                    $resultat->reference,
                );

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

    /**
     * Le règlement déjà enregistré pour cette clé — ou pour cet identifiant fourni — sur cette vente.
     *
     * ⚠ La recherche porte sur le couple (vente, clé), exactement comme la contrainte d'unicité
     * `uniq_paiement_vente_cle`. Les deux doivent dire la même chose : une recherche plus étroite que
     * la contrainte laisse passer le doublon jusqu'au `flush()`, donc après le débit ; une contrainte
     * plus étroite que la recherche rend un 500 sur une clé parfaitement légitime.
     *
     * @param array<string, mixed> $donnees
     */
    private function rejeu(Vente $vente, array $donnees): ?Paiement
    {
        $cle = $this->uuidOuNull($donnees['cleIdempotence'] ?? null);
        $id = $this->uuidOuNull($donnees['id'] ?? null);
        if ($cle === null && $id === null) {
            return null;
        }

        foreach ($vente->getPaiements() as $paiement) {
            if ($cle !== null && $paiement->getCleIdempotence()?->equals($cle) === true) {
                return $paiement;
            }
            if ($id !== null && $paiement->getId()->equals($id)) {
                return $paiement;
            }
        }

        return null;
    }

    private function uuidOuNull(mixed $valeur): ?Uuid
    {
        return \is_string($valeur) && Uuid::isValid($valeur) ? Uuid::fromString($valeur) : null;
    }
}
