<?php

declare(strict_types=1);

namespace App\Stay\Service;

use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Porte une ligne au compte d'un séjour, **une seule fois quoi qu'il arrive** (ACT-3).
 *
 * L'idempotence est défendue à trois niveaux, et ce n'est pas de la paranoïa décorative :
 *
 * 1. **Le schéma** (`UNIQ_STAY_CHARGE_SOURCE`) — la seule protection qui survit à un bogue d'appelant ;
 * 2. **La lecture préalable** — elle évite le coût d'une exception dans le cas normal, celui du
 *    message simplement relivré par le transport asynchrone (D7-bis) ;
 * 3. **Le rattrapage de la violation d'unicité** — parce que entre 2 et l'écriture il y a une fenêtre,
 *    et que deux abonnés concurrents sur le même fait la trouveront tôt ou tard.
 *
 * Retirer l'un des trois donne un système qui marche en test et facture deux fois en production.
 *
 * **Le retour est `?StayCharge` et non `bool`** : l'appelant doit pouvoir distinguer « j'ai créé la
 * ligne » de « elle existait déjà », ne serait-ce que pour ne pas émettre deux fois l'événement qui
 * suivra, une fois `stay.charged` catalogué.
 */
final class StayChargeRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StayChargeLookup $lookup,
    ) {
    }

    /**
     * @param string $amount          montant décimal (`'9.00'`), convention monétaire du dépôt
     * @param string $sourceModule    module émetteur (`vente`, `acces`), ou `manual` au comptoir
     * @param string $sourceEvent     nom catalogué du fait, ou `manual.entry` pour une saisie
     * @param string $sourceSubjectId identifiant du sujet — avec `$sourceEvent`, la clé d'idempotence
     *
     * @return ?StayCharge la ligne créée, ou `null` si ce fait avait déjà été porté au compte
     */
    public function record(
        Stay $stay,
        string $label,
        string $amount,
        \DateTimeImmutable $occurredAt,
        string $sourceModule,
        string $sourceEvent,
        string $sourceSubjectId,
    ): ?StayCharge {
        if (null !== $this->lookup->findBySource($stay, $sourceEvent, $sourceSubjectId)) {
            return null;
        }

        // Le constructeur refuse un séjour clos — l invariant vit dans l entité, pas ici, pour qu il
        // tienne aussi quand la ligne est créée par un autre chemin.
        $charge = new StayCharge($stay, $label, $amount, $occurredAt, $sourceModule, $sourceEvent, $sourceSubjectId);

        try {
            $this->em->persist($charge);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Course perdue : un autre processus a porté le même fait entre la lecture et l écriture.
            // Ce n est pas une erreur — c est exactement le résultat voulu, obtenu par quelqu un d autre.
            return null;
        }

        return $charge;
    }
}
