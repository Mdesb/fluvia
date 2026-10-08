<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Port\PorteMonnaieVirtuelInterface;
use App\Vente\Port\ReferentielReglementInterface;
use App\Vente\Tpe\TerminalPaiementInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\MaxUuid;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;

/**
 * Encaissement scindé (RG-M2-03). Ajoute un règlement : le montant par défaut vaut le reste dû, le
 * reste à payer est recalculé après chaque ajout. Le rendu de monnaie n'est possible que sur un
 * moyen l'autorisant (espèces, RG-M2-05 / CA-9). Un règlement CB part automatiquement au TPE : seul
 * un résultat « accepté » crée le règlement, un refus/timeout n'ajoute rien (US-L2-07 / CA-10). Le
 * moyen doit être autorisé sur le point de vente (acte de régie M6, RG-M2-02).
 *
 * ── LE REJEU D'UN RÈGLEMENT (G-1, G-4 du ticket opposable) ─────────────────────────────────────
 *
 * Un appelant qui fournit une `cleIdempotence` (ou un `id`) peut rejouer sans risque : le règlement
 * déjà enregistré lui est rendu, sans solliciter ni le TPE ni le porte-monnaie. La recherche porte
 * sur toute la table, comme l'index unique et comme la clé primaire ; une clé déjà employée sur une
 * autre vente, ou pour un autre contenu, est refusée avant tout effet.
 *
 * ── ⚠ CE QUE CE GESTIONNAIRE SEUL NE FERME PAS, ET IL FAUT LE SAVOIR ───────────────────────────
 *
 * Seul, il n'écrit rien avant l'effet. Le chemin de l'écran passe donc par `SettlementCoordinator`,
 * qui écrit une TENTATIVE avant de l'appeler, l'appelle dans une transaction (ou le terminal hors
 * transaction) et publie après le commit. Le no-show l'appelle dans sa propre unité, sérialisée sur
 * sa facturation, avec une clé qui en est tirée (`DebitPmvStrategie`, lot 4). Appelé directement par la
 * synchronisation hors ligne (Q-A2), il garde ses limites d'avant :
 *
 * 1. **Deux appels SIMULTANÉS avec la même clé** passent tous deux la recherche et sollicitent tous
 *    deux le TPE ou le PMV ; l'index unique refuse le second au `flush()`, après son effet.
 * 2. **Un timeout du TPE** n'écrit la clé nulle part : un rejeu redemande au terminal.
 * 3. **Un appel sans clé ni `id`** n'est pas rejouable, et c'est voulu : un paiement scindé légitime
 *    envoie deux fois le même moyen et le même montant. Le coordinateur lui donne une clé de serveur :
 *    il est sérialisé avec les autres, il ne devient pas rejouable pour autant.
 *
 * @phpstan-type ResultatPaiement array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
 */
final class PaiementHandler
{
    /** Le refus d'une clé ou d'un `id` déjà employé ailleurs. Il ne dit rien de l'autre vente : elle peut être celle d'un autre établissement. */
    public const AUTRE_VENTE = 'Cette clé d\'idempotence (ou cet identifiant de règlement) a déjà servi pour une autre vente : '
        . 'ce règlement n\'a pas été encaissé. Un nouveau règlement prend une nouvelle clé.';

    public function __construct(
        private readonly ReferentielReglementInterface $referentiel,
        private readonly TerminalPaiementInterface $tpe,
        private readonly PanierCalculateur $calculateur,
        private readonly EntityManagerInterface $em,
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
     * @param SettlementEvents|null $evenements fournis par qui tient la transaction : le refus de carte
     *                                          y est retenu, sans `flush()`, jusqu'à son commit. Nul : le
     *                                          refus est écrit et annoncé sur-le-champ, comme avant.
     *
     * @return array{paiement: Paiement|null, statutTPE: StatutTPE|null, dejaEnregistre: bool}
     */
    public function encaisser(Vente $vente, array $donnees, ?SettlementEvents $evenements = null): array
    {
        // ── LE REJEU SE JUGE AVANT TOUT ────────────────────────────────────────────────────────
        //
        // Avant le 409 « vente validée » : le règlement qui soldait la vente a pu être enregistré,
        // sa réponse se perdre, et la vente être validée avant le rejeu. Avant aussi le débit du
        // porte-monnaie et l'ordre au TPE : tout ce qui sort du processus est plus bas.
        $cle = $this->idempotencyKey($donnees);
        $dejaEnregistre = $this->replay($vente, $donnees, $cle);
        if ($dejaEnregistre !== null) {
            return ['paiement' => $dejaEnregistre, 'statutTPE' => $dejaEnregistre->getStatutTPE(), 'dejaEnregistre' => true];
        }

        if ($vente->estScellee()) {
            throw new ConflictHttpException('Vente validée : encaissement clos (NF525).');
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
        $montantCentimes = $this->requestedAmountCents($vente, $donnees);

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
        $paiement->setCleIdempotence($cle);

        // TPE : envoi automatique ; seul « accepté » crée le règlement (CA-10).
        if ($moyen->exigeReference) {
            if ($pdv === null) {
                throw new UnprocessableEntityHttpException('Aucun point de vente sur cette vente : aucun terminal à solliciter.');
            }
            if ($evenements !== null) {
                $evenements->terminalAsked = true;
            }
            $resultat = $this->tpe->demander($pdv, $paiement->getMontant());
            $paiement->setStatutTPE($resultat->statut);
            if (!$resultat->estAccepte()) {
                // PAY-3 — **le refus est un fait, et il ne laissait aucune trace.** Aucun `Paiement`
                // n'est créé (c'est la règle CA-10), donc jusqu'ici la seule chose qui restait d'une
                // carte refusée était un code de statut dans une réponse HTTP que personne ne
                // conserve. On l'écrit, puis on l'annonce — dans cet ordre, voir le service.
                if ($evenements !== null) {
                    $this->refusCarte?->record($vente, $code, $paiement->getMontant(), $resultat->statut, $resultat->reference, $evenements);
                } else {
                    $this->refusCarte?->consigner($vente, $code, $paiement->getMontant(), $resultat->statut, $resultat->reference);
                }

                return ['paiement' => null, 'statutTPE' => $resultat->statut, 'dejaEnregistre' => false];
            }
            $paiement->setRefTPE($resultat->reference);
        }

        if (isset($donnees['banque']) && \is_string($donnees['banque'])) {
            $paiement->setBanque($donnees['banque']);
        }
        if (isset($donnees['numeroCheque']) && \is_string($donnees['numeroCheque'])) {
            $paiement->setNumeroCheque($donnees['numeroCheque']);
        }
        $id = $this->providedId($donnees);
        if ($id !== null) {
            $paiement->setId($id);
        }

        $vente->addPaiement($paiement);
        $this->calculateur->recalculerVente($vente);

        return ['paiement' => $paiement, 'statutTPE' => $paiement->getStatutTPE(), 'dejaEnregistre' => false];
    }

    /**
     * Le montant demandé, en centimes : celui du corps, sinon le reste dû (RG-M2-03). Une seule
     * règle, pour encaisser comme pour juger un rejeu.
     *
     * @param array<string, mixed> $donnees
     */
    public function requestedAmountCents(Vente $vente, array $donnees): int
    {
        return isset($donnees['montant'])
            ? $this->calculateur->centimes(number_format((float) $donnees['montant'], 2, '.', ''))
            : $this->calculateur->centimes($vente->getResteAPayer());
    }

    /**
     * Le règlement que cet appel a déjà enregistré, s'il existe — sinon `null`, ou un refus.
     *
     * La clé d'abord, puis l'`id` fourni, cherchés **en base et sur toute la table** : exactement la
     * portée de l'index unique `uniq_paiement_cle_idempotence` et de la clé primaire. Une recherche
     * dans la seule collection de la vente laisserait passer la clé d'une autre vente jusqu'au
     * `flush()`, donc jusqu'après le débit.
     *
     * Trouvé sur une autre vente : refus. Trouvé sur cette vente avec un autre contenu (moyen,
     * montant, ou l'autre identifiant) : refus aussi — rendre l'ancien règlement en silence ferait
     * croire à l'appelant que SA demande a été encaissée (G-4). Un montant absent du rejeu n'est pas
     * comparé ici : il voulait dire « le reste dû », que le règlement d'origine a justement réduit.
     * Le coordinateur, lui, compare au montant DEMANDÉ que sa tentative a retenu.
     *
     * @param array<string, mixed> $donnees
     */
    public function replay(Vente $vente, array $donnees, ?Uuid $cle): ?Paiement
    {
        $id = $this->providedId($donnees);
        if ($cle === null && $id === null) {
            return null;
        }

        $paiements = $this->em->getRepository(Paiement::class);
        $existant = ($cle !== null ? $paiements->findOneBy(['cleIdempotence' => $cle]) : null)
            ?? ($id !== null ? $paiements->find($id) : null);
        if ($existant === null) {
            return null;
        }

        if ($existant->getVente()?->getId()->equals($vente->getId()) !== true) {
            throw new UnprocessableEntityHttpException(self::AUTRE_VENTE);
        }

        $memeContenu = ($cle === null || $existant->getCleIdempotence()?->equals($cle) === true)
            && ($id === null || $existant->getId()->equals($id))
            && ($donnees['moyen'] ?? null) === $existant->getMoyenCode()
            && (!isset($donnees['montant'])
                || $this->requestedAmountCents($vente, $donnees) === $this->calculateur->centimes($existant->getMontant()));
        // Le message NOMME le règlement déjà enregistré : « rien n'a été encaissé » seul pousserait à
        // réencaisser avec une nouvelle clé, alors que de l'argent est peut-être déjà passé.
        if (!$memeContenu) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Un règlement de %s € en « %s » est déjà enregistré sur cette vente sous cette clé (ou cet identifiant) ; '
                . 'cette demande diffère (moyen, montant ou identifiant) et n\'a pas été encaissée. Vérifiez le reste dû avant d\'encaisser de nouveau.',
                $existant->getMontant(),
                $existant->getMoyenCode(),
            ));
        }

        return $existant;
    }

    /**
     * La clé fournie, ou `null` si l'appelant n'en envoie pas. Une clé présente mais illisible est
     * REFUSÉE : l'ignorer laisserait l'appelant croire son règlement rejouable alors qu'il ne l'est pas.
     * Les UUID « nul » et « max » aussi : constants, ils ne sont la clé d'aucune intention, et un
     * client qui les enverrait toujours se heurterait aux règlements de tous les établissements.
     *
     * @param array<string, mixed> $donnees
     */
    public function idempotencyKey(array $donnees): ?Uuid
    {
        $valeur = $donnees['cleIdempotence'] ?? null;
        if ($valeur === null) {
            return null;
        }
        $cle = \is_string($valeur) && Uuid::isValid($valeur) ? Uuid::fromString($valeur) : null;
        if ($cle === null || $cle instanceof NilUuid || $cle instanceof MaxUuid) {
            throw new UnprocessableEntityHttpException('cleIdempotence doit être un UUID propre à ce règlement (forme 8-4-4-4-12).');
        }

        return $cle;
    }

    /**
     * L'`id` fourni, ou `null`. Un `id` illisible reste ignoré, comme avant : la synchronisation
     * hors-ligne l'envoie, et ce lot ne change pas son comportement (Q-A2).
     *
     * ⚠ Un `id` nul ou max est donc accepté, à la différence de la clé : le premier règlement qui le
     * prend le garde, et tout autre appel qui le réemploie, quel que soit son établissement, reçoit
     * un 422 avant tout effet. C'est mieux qu'avant (une 500 au `flush()`, après le débit), et le
     * refuser changerait le comportement de la synchronisation, que ce lot ne touche pas.
     *
     * @param array<string, mixed> $donnees
     */
    public function providedId(array $donnees): ?Uuid
    {
        $valeur = $donnees['id'] ?? null;

        return \is_string($valeur) && Uuid::isValid($valeur) ? Uuid::fromString($valeur) : null;
    }
}
