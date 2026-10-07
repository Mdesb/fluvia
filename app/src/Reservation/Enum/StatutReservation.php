<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/** Cycle de vie d'une Réservation (cahier §6, RG-M5-01/02/09). */
enum StatutReservation: string
{
    /**
     * ⚠ RESERVEE MAIS PAS ENCORE CONFIRMEE — l'etat neuf du 04/09.
     *
     * Sans lui, une reservation en attente de confirmation serait `Confirmee`, ce qui est
     * exactement le contraire. Aucune reservation n'entre dans cet etat tant qu'aucune
     * `RegleAnnulation` ne declare de delai de confirmation : le defaut reste `Confirmee`.
     */
    case AConfirmer = 'a_confirmer';

    case Confirmee = 'confirmee';
    case ListeAttente = 'liste_attente';
    case AnnuleeLibre = 'annulee_libre';
    case AnnuleeTardiveFacturee = 'annulee_tardive_facturee';
    case NoShowFacture = 'no_show_facture';
    case Honoree = 'honoree';

    /**
     * ⚠ L'ÉTAT NEUTRE DU 07/10/2026 : ni honorée, ni absente. Le créneau est passé et l'absence a été
     * levée (`POST /reservation/reservations/{id}/lever-absence`), une fois sa facturation exonérée.
     * N'occupe aucune place, n'ouvre aucun accès, et le reporting ne la compte pas comme absence.
     */
    case TermineeSansConstat = 'terminee_sans_constat';

    /**
     * Vrai si la réservation occupe encore une place sur le créneau (compte pour la jauge).
     *
     * ⚠ `AConfirmer` OCCUPE, ET IL A FALLU UN AUDIT POUR LE VOIR. Ce prédicat servait à QUATRE
     * questions à la fois, et pour ce statut-là les réponses divergent :
     *
     *     tient-elle un créneau ?               OUI   jauge, chevauchement, annulation en cascade
     *     est-elle annulable ?                  OUI   c'est l'état le MOINS engagé qui soit
     *     peut-on lui affecter une ressource ?  OUI
     *     ouvre-t-elle le portique ?            NON   elle n'est pas payée
     *
     * En rendant `false` pour `AConfirmer`, il produisait un CUL-DE-SAC prouvé en exécutant :
     * `POST /reservations/{id}/annuler` répondait `409 — n'est plus annulable`. Aucun écran ne
     * pouvait la confirmer, l'API refusait de l'annuler, et sans délai armé elle n'expirait jamais.
     * Elle occupait un créneau pour toujours.
     *
     * ⚠ ET L'ÉLARGIR SEUL AURAIT OUVERT LA PORTE À UNE RÉSERVATION IMPAYÉE : c'est la quatrième
     * question qui devait partir — cf. `donneDroitAcces()` — pas les trois premières qui devaient rester
     * fausses. Le frontal, lui, disait déjà « ⚠ `a_confirmer` OCCUPE AUSSI » (`Reservation.jsx`) :
     * les deux côtés se contredisaient, et c'est le serveur qui avait tort.
     */
    public function occupePlace(): bool
    {
        return $this === self::AConfirmer || $this === self::Confirmee || $this === self::Honoree;
    }

    /**
     * Vrai si la réservation doit ouvrir un droit d'accès — le portique, le badge, le tourniquet.
     *
     * ⚠ CE N'EST PAS `occupePlace()`, ET LES CONFONDRE DONNE L'ACCÈS AVANT LE PAIEMENT. Une
     * réservation `AConfirmer` tient sa place mais n'a rien réglé : elle doit compter dans la jauge
     * et ne pas ouvrir la porte. Tant que les deux questions partageaient un prédicat, on ne pouvait
     * répondre juste qu'à une seule.
     *
     * ⚠ NOMMÉE `donneDroitAcces` ET NON `ouvreAcces` : ce dernier EXISTE DÉJÀ, sur `Ressource`, et
     * veut dire « cette ressource a un portique ». Deux sujets différents sous un seul nom, dans le
     * même module, tous deux booléens sur l'accès — celui qui lit l'un en croyant l'autre ne s'en
     * aperçoit pas.
     */
    public function donneDroitAcces(): bool
    {
        return $this === self::Confirmee || $this === self::Honoree;
    }
}
