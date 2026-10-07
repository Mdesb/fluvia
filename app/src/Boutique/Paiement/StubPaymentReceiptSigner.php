<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Vente\Enum\StatutTPE;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LE REÇU SIGNÉ D'UN PRESTATAIRE DE PAIEMENT SIMULÉ — audit du 06/09, constat 1.
 *
 * ── CE QUE LES BOUCHONS FAISAIENT ─────────────────────────────────────────────────────────────
 *
 * `POST /boutique/paniers/{id}/retour-paiement` recevait `{ "statut": "accepte" }` et confirmait la
 * commande. Le statut venait DU CORPS de la requête — c'est-à-dire de l'acheteur. Aujourd'hui le
 * prestataire est un bouchon, donc « tout le monde le sait » ; mais le contrat `traiterRetour(array)`
 * disait au futur vrai adaptateur : « voici les données, décide ». Il aurait hérité du défaut.
 *
 * ── CE QU'UN VRAI PRESTATAIRE FAIT, ET QUE LE BOUCHON IMITE DÉSORMAIS ─────────────────────────
 *
 * Un prestataire SIGNE ce qu'il renvoie (HMAC sur la référence, le résultat et le montant, avec un
 * secret partagé), ou se laisse interroger serveur à serveur sur une référence. Le contrat
 * (`verifierRetour`) impose désormais à l'adaptateur de VÉRIFIER avant de répondre, et de rendre `null`
 * quand il ne peut pas. Le bouchon vérifie une signature — la sienne : à l'initiation il remet à
 * l'acheteur un reçu par issue possible (« accepté », « refusé »…), et au retour il n'accepte que ce
 * qu'il a signé, pour cette référence et ce montant.
 *
 * ⚠ CE N'EST PAS UNE SÉCURITÉ DE PRODUCTION, ET ÇA NE PRÉTEND PAS L'ÊTRE. L'acheteur reçoit le reçu
 * « accepté » sans avoir payé — c'est la nature d'un bouchon, et la démonstration en préproduction en
 * a besoin. Ce qui est réglé est STRUCTUREL : le processeur ne lit plus jamais un statut dans le corps,
 * un tiers qui n'a pas initié la transaction ne peut rien forger, et le vrai adaptateur remplacera ce
 * signataire par la vérification du prestataire sans toucher au processeur.
 *
 * Signé avec `APP_SECRET`, déjà requis par le noyau : pas une clé de plus à générer au déploiement.
 */
final class StubPaymentReceiptSigner
{
    public function __construct(
        #[Autowire(env: 'APP_SECRET')] private readonly string $secret,
        // ⚠ FAIL-CLOSED (audit du 14/09, bloquant 1). Ce signataire fabrique ET atteste des recus de
        //   paiement sans qu'aucun paiement reel ait eu lieu — c'est la nature d'un bouchon, voulu en
        //   preproduction, dangereux partout ailleurs. Il refuse donc d'operer tant que l'opt-in
        //   explicite n'est pas pose. Defaut FERME : la production ne mint rien tant que rien n'est
        //   active. Pose en preproduction (infra/.env.preprod) et en test (.env.test), jamais en prod.
        #[Autowire(env: 'bool:PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE')] private readonly bool $autorise = false,
    ) {
    }

    /**
     * Un reçu par issue possible, remis à l'initiation. La page « prestataire » du frontal public
     * présente ces issues comme des boutons ; le vrai prestataire, lui, n'en rendra qu'une.
     *
     * @return array<string, string> statut => reçu
     */
    public function receipts(string $referenceTransaction, int $montantCentimes): array
    {
        $this->garantirBouchonAutorise();

        $receipts = [];
        foreach (StatutTPE::cases() as $statut) {
            $receipts[$statut->value] = $this->sign($referenceTransaction, $statut, $montantCentimes);
        }

        return $receipts;
    }

    /** Le statut que ce reçu atteste — `null` s'il n'atteste rien pour cette référence et ce montant. */
    public function verify(string $referenceTransaction, int $montantCentimes, mixed $receipt): ?StatutTPE
    {
        $this->garantirBouchonAutorise();

        if (!\is_string($receipt) || $receipt === '') {
            return null;
        }

        foreach (StatutTPE::cases() as $statut) {
            if (hash_equals($this->sign($referenceTransaction, $statut, $montantCentimes), $receipt)) {
                return $statut;
            }
        }

        return null;
    }

    /**
     * ⚠ REFUSE D'OPERER TANT QUE LE BOUCHON N'EST PAS EXPLICITEMENT AUTORISE. Un recu que ce
     * signataire fabrique atteste un paiement qui n'a pas eu lieu ; hors preproduction, ce serait un
     * faux « accepte » pris pour un encaissement reel. Fail-closed : on echoue bruyamment plutot que
     * de mentir en silence. Un vrai prestataire (PSP CB, vrai PayFiP) n'appelle pas ce signataire.
     */
    private function garantirBouchonAutorise(): void
    {
        if ($this->autorise) {
            return;
        }

        throw new \RuntimeException(
            'Prestataire de paiement en ligne de DEMONSTRATION invoque alors que '
            . 'PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE n\'est pas active. Ce bouchon signe lui-meme ses '
            . 'recus : le laisser servir ferait passer un faux « accepte » pour un paiement reel. '
            . 'Branchez un prestataire reel, ou posez PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE=1 en preproduction.'
        );
    }

    private function sign(string $referenceTransaction, StatutTPE $statut, int $montantCentimes): string
    {
        return hash_hmac('sha256', sprintf('psp-stub|%s|%s|%d', $referenceTransaction, $statut->value, $montantCentimes), $this->secret);
    }
}
