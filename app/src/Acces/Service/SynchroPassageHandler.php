<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Passage;
use App\Acces\Enum\SensPassage;
use App\Acces\Enum\CodeMotifRefus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Rejeu chronologique idempotent des passages hors-ligne (US-L3-08, RG-ACC-05, CA-9). Réutilise le
 * pattern M2 §4 : tri par horodatage d'origine, anti-doublon par `cleIdempotence` (clé déjà présente
 * = no-op), crédits/FMI recalés « en marchant » (chaque rejeu applique le même algorithme atomique
 * que la validation en ligne), conflits (révocation postérieure) détectés et tracés sans annulation
 * rétroactive du passage déjà advenu (hypothèse retenue, §4.6 Risque n°5).
 *
 * Généralisation `Controleur` → `Terminal` (plan-acces-terminal.md §4.3) : `synchroniser()` (contrat
 * `/acces/synchro` historique) et `synchroniserPourTerminal()` (contrat `/terminal/passages/lot`,
 * plusieurs `Controleur`/`Equipement` d'un même `itboxRef`) partagent le même cœur de boucle
 * (`rejouer()`) — seul diffère le périmètre du recalage FMI de fin de rejeu (1 espace pour
 * `synchroniser()`, comme aujourd'hui ; tous les espaces effectivement touchés par le lot pour
 * `synchroniserPourTerminal()`). `autoriserCreditNegatifSiHorsLigne` (CA-8) reste `false` dans les
 * **deux** méthodes : la réconciliation gracieuse du crédit négatif est implémentée (cf.
 * `ValidationPassageHandler`) mais volontairement non activée sur ce chemin non plus, en attendant
 * validation IT Cotation (Risque R-6 du plan, alternative de repli documentée) — voir le commentaire
 * de `synchroniserPourTerminal()` ci-dessous.
 */
final class SynchroPassageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidationPassageHandler $validation,
        private readonly RecalageFmiHandler $recalage,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lot
     *
     * @return array{inseres: list<string>, doublons: list<string>, conflits: list<string>}
     */
    public function synchroniser(Controleur $controleur, array $lot): array
    {
        $resultat = $this->rejouer($lot, autoriserCreditNegatifSiHorsLigne: false);

        $inseres = [];
        $doublons = [];
        $conflits = [];
        foreach ($resultat['details'] as $detail) {
            if ($detail['statut'] === 'doublon') {
                $doublons[] = $detail['cleIdempotence'];
                continue;
            }
            /** @var Passage $passage */
            $passage = $detail['passage'];
            $inseres[] = (string) $passage->getId();
            if ($detail['enConflit']) {
                $conflits[] = (string) $passage->getId();
            }
        }

        // Recalage FMI de fin de rejeu (§4.6) : la jauge de l'espace du contrôleur est déjà à jour
        // (chaque replay applique l'UPDATE atomique), ce recalage garantit sa présence/fraîcheur.
        // Comportement strictement inchangé (espace du contrôleur passé en paramètre, quel que soit
        // le contenu du lot) — aucune régression sur le contrat `/acces/synchro`.
        $espace = $controleur->getEspace();
        if ($espace !== null) {
            $this->recalage->recalerApresSynchro($espace);
        }

        return ['inseres' => $inseres, 'doublons' => $doublons, 'conflits' => $conflits];
    }

    /**
     * Contrat `/terminal/passages/lot` (US-TERM-06/07/08, plan-acces-terminal.md §2.4/§4.3) : même
     * cœur de rejeu, et recale la jauge FMI de **chaque espace distinct** effectivement touché par le
     * lot (potentiellement plusieurs `Controleur`/`Equipement` d'un même `itboxRef`), au lieu d'un seul
     * espace comme `synchroniser()`.
     *
     * ⚠ **Réconciliation gracieuse du crédit épuisé hors-ligne (CA-8) — implémentée mais VOLONTAIREMENT
     * NON ACTIVÉE dans ce lot** (écart assumé avec le plan §4.3, qui active le flag ici par défaut) :
     * ce changement de comportement métier (accepter un dépassement au lieu de le refuser, Risque R-6
     * du plan) doit être validé explicitement par IT Cotation avant mise en production — cf. l'alternative
     * de repli documentée par le plan lui-même (§8 Risque R-6 : « Lot D livré avec
     * `autoriserCreditNegatifSiHorsLigne` toujours `false` »). `/terminal/passages/lot` se comporte donc
     * aujourd'hui **exactement comme `/acces/synchro`** sur ce point précis (refus au rejeu si crédit
     * épuisé) ; activer la réconciliation gracieuse se limite à passer `true` ci-dessous, une fois
     * validé (le reste du mécanisme — `ValidationPassageHandler`, `JournalReconciliation`,
     * `CodeMotifRefus::CreditEpuiseHorsLigneLitige` — est déjà testé et opérationnel,
     * cf. `ValidationPassageHandlerCreditNegatifTest`).
     *
     * @param list<array<string, mixed>> $lot
     *
     * @return array{
     *     details: list<array{cleIdempotence: string, statut: string, codeMotif: ?string, enConflit: bool, passage: ?Passage}>,
     *     passages: list<Passage>,
     * }
     */
    public function synchroniserPourTerminal(array $lot, bool $origineTerminal = true): array
    {
        $resultat = $this->rejouer($lot, autoriserCreditNegatifSiHorsLigne: false);

        /** @var array<string, EspaceAcces> $espaces */
        $espaces = [];
        foreach ($resultat['passages'] as $passage) {
            $espace = $passage->getEspace();
            if ($espace !== null) {
                $espaces[(string) $espace->getId()] = $espace;
            }
        }
        foreach ($espaces as $espace) {
            $this->recalage->recalerApresSynchro($espace);
        }

        return $resultat;
    }

    /**
     * Cœur de rejeu partagé (§4.3 du plan) : tri chronologique, anti-doublon par `cleIdempotence`
     * (inchangé), délégation à `ValidationPassageHandler::valider()` pour chaque entrée non dupliquée.
     *
     * @param list<array<string, mixed>> $lot
     *
     * @return array{
     *     details: list<array{cleIdempotence: string, statut: string, codeMotif: ?string, enConflit: bool, passage: ?Passage}>,
     *     passages: list<Passage>,
     * }
     */
    private function rejouer(array $lot, bool $autoriserCreditNegatifSiHorsLigne): array
    {
        usort($lot, static fn (array $a, array $b): int => strcmp(
            (string) ($a['horodatage'] ?? $a['horodatageBorne'] ?? ''),
            (string) ($b['horodatage'] ?? $b['horodatageBorne'] ?? ''),
        ));

        $details = [];
        $passages = [];

        foreach ($lot as $entree) {
            // ⚠ UNE CLE FOURNIE ET JETEE EST PIRE QUE PAS DE CLE DU TOUT.
            //
            // Ce code faisait `?? Uuid::v4()` sur les DEUX cas : cle absente, et cle presente mais
            // malformee. Le second engendrait une cle NEUVE a chaque envoi, donc un rejeu du meme
            // lot — le geste normal apres une reprise reseau — recreait tous les passages. Mesure du
            // 31/08 : deux envois d'un lot d'une entree portant une cle en chaine libre ont produit
            // deux passages et deux `statut: accepte`. Avec un UUID, le second repond `doublon`.
            //
            // On ne peut pas refuser l'absence : `rejouer()` est partage avec `POST /acces/synchro`,
            // dont le contrat est declare inchange et dont des appelants s'appuient sur la
            // generation serveur. L'absence reste donc engendree ; seule la MALFORMATION est
            // refusee, parce qu'elle seule trahit une intention d'idempotence.
            $cleBrute = $entree['cleIdempotence'] ?? null;
            $cle = $this->uuid($cleBrute);

            if ($cle === null && \is_string($cleBrute) && $cleBrute !== '') {
                $details[] = [
                    'cleIdempotence' => $cleBrute,
                    'statut' => 'rejete',
                    'codeMotif' => CodeMotifRefus::CleIdempotenceInvalide->value,
                    'enConflit' => false,
                    'passage' => null,
                ];

                continue;
            }

            $cle ??= Uuid::v4();

            $existant = $this->em->getRepository(Passage::class)->findOneBy(['cleIdempotence' => $cle]);
            if ($existant instanceof Passage) {
                // Double décompte absorbé par idempotence (no-op) : le rejeu réseau d'un lot déjà
                // remonté ne journalise jamais deux fois le même passage (CA-7/CA-9, inchangé).
                $details[] = ['cleIdempotence' => (string) $cle, 'statut' => 'doublon', 'codeMotif' => null, 'enConflit' => $existant->isEnConflit(), 'passage' => $existant];
                continue;
            }

            $equipementId = $this->uuid($entree['equipementId'] ?? null);
            if ($equipementId === null) {
                continue;
            }

            $sens = isset($entree['sens']) ? SensPassage::tryFrom((string) $entree['sens']) : null;
            $horodatageBorne = isset($entree['horodatageBorne']) ? new \DateTimeImmutable((string) $entree['horodatageBorne']) : null;
            $horodatage = isset($entree['horodatage'])
                ? new \DateTimeImmutable((string) $entree['horodatage'])
                : ($horodatageBorne ?? new \DateTimeImmutable());

            $evt = new EvenementPassageDto(
                equipementId: $equipementId,
                identifiantSupport: isset($entree['identifiantSupport']) ? (string) $entree['identifiantSupport'] : null,
                sens: $sens,
                horodatage: $horodatage,
                cleIdempotence: $cle,
                origineHorsLigne: true,
                ignorerRevocationSiPosterieure: true,
                horodatageBorne: $horodatageBorne,
                autoriserCreditNegatifSiHorsLigne: $autoriserCreditNegatifSiHorsLigne,
            );

            $passage = $this->validation->valider($evt);
            $passages[] = $passage;
            $details[] = [
                'cleIdempotence' => (string) $cle,
                'statut' => 'accepte',
                'codeMotif' => $passage->getCodeMotif()?->value,
                'enConflit' => $passage->isEnConflit(),
                'passage' => $passage,
            ];
        }

        return ['details' => $details, 'passages' => $passages];
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }

        return Uuid::isValid($reference) ? Uuid::fromString($reference) : null;
    }
}
