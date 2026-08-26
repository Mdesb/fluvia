<?php

declare(strict_types=1);

namespace App\Lodging\Domain;

/**
 * Une période d'hébergement, comptée en **nuitées** (ACT-2, D16).
 *
 * C'est la couche mince que D16 décrit : `claude-G` a livré la réservation par type et l'affectation
 * différée — on réserve une chambre double, l'instance vient plus tard — et refuse déjà deux
 * affectations qui se chevauchent. Ce qu'il reste à dire, et que personne d'autre ne peut dire, c'est
 * **ce qu'occupe une nuitée**.
 *
 * **L'intervalle est semi-ouvert : `[arrivée, départ[`.** Ce n'est pas un raffinement théorique, c'est
 * la règle métier « chambre libérée le matin, relouable le soir » écrite en une ligne. Un séjour du 24
 * au 27 occupe les nuits du 24, du 25 et du 26 — **trois** nuitées, pas quatre — et la chambre est
 * disponible dès le 27 pour qui arrive ce soir-là. Compter le jour de départ ferait perdre une nuit
 * vendable par séjour et par chambre : sur un hôtel de trente chambres en août, c'est un mois de
 * chiffre d'affaires qui disparaît du calendrier sans que personne ne comprenne pourquoi.
 *
 * **Zéro nuitée est refusé, délibérément.** Une chambre prise et rendue le même jour existe — c'est
 * un « day use » — mais ce n'est pas une nuitée : c'est un créneau, et le créneau est le métier de
 * `App\Reservation`. Les confondre ferait apparaître dans le calendrier d'occupation des séjours qui
 * n'occupent aucune nuit, et le calendrier mentirait sur le taux de remplissage.
 */
final class LodgingPeriod
{
    private function __construct(
        public readonly \DateTimeImmutable $arrival,
        public readonly \DateTimeImmutable $departure,
    ) {
    }

    /**
     * @throws \InvalidArgumentException si le départ ne suit pas l'arrivée d'au moins une nuit
     */
    public static function fromDates(\DateTimeImmutable $arrival, \DateTimeImmutable $departure): self
    {
        // Normalisation à minuit : une nuitée se compte en jours, et laisser traîner une heure
        // d'arrivée ferait dépendre le nombre de nuits de l'heure à laquelle le client se présente.
        $arrival = $arrival->setTime(0, 0);
        $departure = $departure->setTime(0, 0);

        if ($departure <= $arrival) {
            throw new \InvalidArgumentException(sprintf(
                'Une période d\'hébergement compte au moins une nuitée : départ le %s pour une arrivée le %s. '
                . 'Une occupation sans nuit est un créneau, pas un séjour.',
                $departure->format('Y-m-d'),
                $arrival->format('Y-m-d'),
            ));
        }

        return new self($arrival, $departure);
    }

    public function nightCount(): int
    {
        return (int) $this->arrival->diff($this->departure)->days;
    }

    /**
     * Les nuits occupées, datées par leur **soir** — la nuit du 24 au 25 est « le 24 ».
     *
     * C'est la convention de l'hôtellerie, et elle n'est pas arbitraire : c'est le soir qu'on remet
     * la clé, et c'est par soir que se remplit un planning mural.
     *
     * @return list<\DateTimeImmutable>
     */
    public function nights(): array
    {
        $nuits = [];
        for ($nuit = $this->arrival; $nuit < $this->departure; $nuit = $nuit->modify('+1 day')) {
            $nuits[] = $nuit;
        }

        return $nuits;
    }

    public function includesNight(\DateTimeImmutable $night): bool
    {
        $night = $night->setTime(0, 0);

        return $night >= $this->arrival && $night < $this->departure;
    }

    /**
     * Deux périodes se chevauchent-elles ?
     *
     * **Un départ le jour d'une arrivée n'est pas un chevauchement** : c'est le cas le plus fréquent
     * d'une chambre bien remplie, et le refuser condamnerait une chambre à rester vide un jour sur deux.
     */
    public function overlaps(self $other): bool
    {
        return $this->arrival < $other->departure && $other->arrival < $this->departure;
    }
}
