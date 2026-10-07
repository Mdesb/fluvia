<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/echeances/{id}/annuler — abandonne définitivement une échéance à venir.
 *
 * ── POURQUOI CETTE OPÉRATION EXISTE ────────────────────────────────────────────────────────────
 *
 * Elle n'existait pas. L'échéancier connaissait « à venir », « prélevée », « rejetée » et « gelée »,
 * et rien d'autre — or une échéance abandonnée est un fait courant : un adhérent résilie, un
 * échéancier est refait, un essai est nettoyé.
 *
 * Faute de mot pour le dire, les échéances abandonnées restaient « à venir » indéfiniment. Elles ne
 * cassaient rien — la collecte les écarte faute de préavis — mais elles s'accumulaient dans les
 * écrans en se présentant comme dues. Mesure du 02/09 : **38 échéances « à venir » dont la plus
 * ancienne remontait à septembre 2025**.
 *
 * ⚠ ET SURTOUT, LA TENTATION ÉTAIT D'EMPLOYER `Gelee`. C'est le seul état qui ressemble — mais il
 * veut dire « en pause » (RG-SPORT-05), il est posé par `DemanderPauseHandler` quand un adhérent
 * demande une suspension, et une reprise le lève. Il aurait affiché « en pause » sur des échéances
 * abandonnées, et une reprise d'abonnement les aurait réveillées.
 *
 * ── LE MOTIF EST OBLIGATOIRE, ET CE N'EST PAS DE LA BUREAUCRATIE ────────────────────────────────
 *
 * Une échéance annulée est une somme que le club n'encaissera jamais. Six mois plus tard, la seule
 * question posée sera « pourquoi ? », et un état sans motif y répond « on ne sait pas ». Le motif
 * est donc exigé à l'écriture, pas proposé.
 *
 * Corps : `{ "motif": string }`.
 *
 * @implements ProcessorInterface<EcheanceSepa, EcheanceSepa>
 */
final class CancelScheduledDebitProcessor implements ProcessorInterface
{
    private const MOTIF_MAX = 200;

    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcheanceSepa
    {
        // ── ⚠ `assert()` ICI RENDAIT UN 500, ET LE CAS N'EST PAS THEORIQUE ────────────────────
        //
        // Quand l'echeance visee est hors du perimetre de l'etablissement actif, le fournisseur
        // d'API Platform rend `null` — le cloisonnement fait son travail — et ce `null` arrive
        // jusqu'ici. Un `assert()` le transforme en `AssertionError`, donc en **500 « Internal
        // Server Error »** : le logiciel s'accuse lui-meme d'etre casse alors qu'il vient
        // precisement de proteger la donnee d'un autre exploitant.
        //
        // Mesure du 02/09 : decouvert en ecrivant le test de cloisonnement de l'annulation, qui
        // attendait 403 ou 404 et a lu 500.
        //
        // 404 est aussi la bonne reponse du point de vue de la confidentialite : « introuvable »
        // ne dit pas si la ressource existe ailleurs, la ou « interdit » l'avouerait.
        if (!$data instanceof EcheanceSepa) {
            throw new NotFoundHttpException('Echeance introuvable dans le perimetre de l etablissement actif.');
        }

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? trim($corps['motif']) : '';

        if ($motif === '') {
            throw new UnprocessableEntityHttpException(
                '« motif » est requis : une échéance annulée est une somme qui ne sera jamais encaissée, '
                . 'et un état sans motif ne répond pas à la seule question qu on posera ensuite.',
            );
        }

        if (mb_strlen($motif) > self::MOTIF_MAX) {
            throw new UnprocessableEntityHttpException(sprintf(
                '« motif » dépasse %d caractères (%d).',
                self::MOTIF_MAX,
                mb_strlen($motif),
            ));
        }

        // ⚠ CLOISONNEMENT (RG-SOCLE-05). L'échéance est atteinte par son identifiant : sans ce
        // contrôle, connaître un UUID suffirait à annuler l'échéance d'un autre établissement.
        $etablissement = $data->getAbonnement()?->getEtablissement();
        $actif = $this->contexte->idActif();

        if ($actif === null || $etablissement === null || !$actif->equals($etablissement->getId())) {
            throw new AccessDeniedHttpException("Échéance hors du périmètre de l'établissement actif (RG-SOCLE-05).");
        }

        // ⚠ SEULE UNE ÉCHÉANCE « À VENIR » S'ANNULE, ET LE REFUS NOMME L'ÉTAT TROUVÉ.
        //
        // Une échéance PRÉLEVÉE correspond à de l'argent parti : l'annuler après coup effacerait la
        // trace d'un mouvement bancaire réel sans le rembourser. Une REJETÉE a déjà ouvert un
        // incident d'impayé côté recouvrement, et la faire disparaître laisserait cet incident
        // orphelin. Le geste qui convient à celles-là n'est pas l'annulation.
        if ($data->getStatut() !== StatutEcheanceSepa::AVenir) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Seule une échéance « à venir » s annule ; celle-ci est « %s ». '
                . 'Une échéance prélevée correspond à un mouvement bancaire réel, et une échéance rejetée '
                . 'a ouvert un incident d impayé : ni l une ni l autre ne se raye.',
                $data->getStatut()->value,
            ));
        }

        $data->setStatut(StatutEcheanceSepa::Annulee)
            ->setCancellationReason($motif)
            ->setCancelledAt(new \DateTimeImmutable());

        $this->em->flush();

        return $data;
    }
}
