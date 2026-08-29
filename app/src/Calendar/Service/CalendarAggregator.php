<?php

declare(strict_types=1);

namespace App\Calendar\Service;

use App\Calendar\Entity\CalendarEvent;
use App\Calendar\Port\CalendarSourceInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * CE QUI SE PASSE ICI CETTE SEMAINE — et ce que MOI j'ai à faire.
 *
 * ── L'AGENDA NE POSSÈDE QU'UNE CHOSE : CE QU'ON NOTE À LA MAIN ──────────────────────────────────
 *
 * Les créneaux de réservation, les créneaux de travail et les plages d'ouverture appartiennent à
 * leurs modules. Les recopier ici créerait une seconde vérité qui dériverait dès la première
 * annulation : un cours annulé la veille resterait affiché, et l'exploitant croirait le logiciel
 * plutôt que son planning.
 *
 * > **Un agenda ne possède pas ce qu'il montre.** Il le lit, à la demande, chez ceux qui le
 * > possèdent — et par la porte qu'ils ont ouverte, pas par la fenêtre.
 *
 * ── POURQUOI CETTE CLASSE A MAIGRI ──────────────────────────────────────────────────────────────
 *
 * Elle lisait `reservation_creneau` et `personnel_creneau_travail` en SQL brut. Ça marchait, et
 * c'était une dette payée le jour même : le passage des modules en anglais a traduit
 * `c.establishment_id` sur une table dont la colonne s'appelle `etablissement_id`. **Du SQL en
 * chaîne de caractères échappe à tous les outils** — au lint, au garde-fou de nommage, à l'analyse
 * statique. Rien ne l'aurait dit avant l'exécution.
 *
 * Chaque module publie désormais ce qu'il a, derrière `CalendarSourceInterface`. L'agenda ne
 * connaît que l'interface ; un module qui n'a rien à publier ne fournit pas d'implémentation, et
 * l'agenda ne s'en aperçoit pas.
 *
 * ── LES ÉVÉNEMENTS PERSONNELS DES AUTRES N'APPARAISSENT NULLE PART ──────────────────────────────
 *
 * Pas même dans la vue site, pas même pour un administrateur. Un blocage personnel dans un agenda
 * professionnel dit parfois autre chose qu'un horaire.
 */
final readonly class CalendarAggregator
{
    /**
     * @param iterable<CalendarSourceInterface> $sources
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator('calendar.source')]
        private iterable $sources,
    ) {
    }

    /**
     * @return list<array{id: string, source: string, title: string, start: string, end: string, allDay: bool, type: string, scope: string, detail: string|null}>
     */
    public function evenements(
        Etablissement $etablissement,
        Utilisateur $utilisateur,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        string $portee,
    ): array {
        // ⚠ UNE PORTEE INCONNUE LEVE, ELLE NE RETOMBE PAS SUR « site ».
        //
        // La ligne suivante lit `$portee === 'mine'`. Sans ce controle, toute autre valeur donnait
        // silencieusement l'agenda DU SITE — et c'est exactement ce qui est arrive : le controleur
        // ICS demandait « moi », le vocabulaire des ONGLETS, et les abonnes n'ont jamais recu leurs
        // evenements personnels. Aucun test, aucun journal, aucun ecran ne l'a dit.
        //
        // `CalendarFeedProvider` a un repli, et il a raison : il recoit un parametre d'URL tape par
        // un humain, et un mot mal orthographie ne doit pas produire un ecran en erreur. Ici
        // l'appelant est du CODE : un repli n'y masque pas une faute de frappe, il masque un bogue.
        if (!\in_array($portee, ['mine', 'site'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Portee d\'agenda inconnue : "%s". Les valeurs du fil sont "mine" et "site" ; "moi" est le vocabulaire des onglets de l\'ecran.',
                $portee,
            ));
        }

        $lignes = $this->evenementsSaisis($etablissement, $du, $au, $portee === 'mine' ? $utilisateur : null);

        foreach ($this->sources as $source) {
            $lignes = [...$lignes, ...$source->occurrences($etablissement, $utilisateur, $du, $au, $portee)];
        }

        usort($lignes, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_values($lignes);
    }

    /**
     * Les événements saisis à la main — la seule chose que ce module possède.
     *
     * `$proprietaire === null` rend ceux DU SITE ; sinon, ceux de cette personne — jamais les deux,
     * jamais ceux d'un tiers.
     *
     * @return list<array<string, mixed>>
     */
    private function evenementsSaisis(
        Etablissement $etablissement,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
        ?Utilisateur $proprietaire,
    ): array {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(CalendarEvent::class, 'e')
            ->andWhere('IDENTITY(e.establishment) = :etab')
            ->andWhere('e.start < :au AND e.end > :du')
            // Type `'uuid'` explicite (D58) : sans lui, la requête rend zéro ligne sans lever.
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('du', $du)
            ->setParameter('au', $au);

        if ($proprietaire === null) {
            $qb->andWhere('e.owner IS NULL');
        } else {
            $qb->andWhere('IDENTITY(e.owner) = :moi')
                ->setParameter('moi', $proprietaire->getId(), 'uuid');
        }

        /** @var list<CalendarEvent> $evenements */
        $evenements = $qb->getQuery()->getResult();

        return array_map(static fn (CalendarEvent $e): array => [
            'id' => (string) $e->getId(),
            'source' => 'event',
            'title' => $e->getTitle(),
            'start' => ($e->getStart() ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'end' => ($e->getEnd() ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'allDay' => $e->isAllDay(),
            'type' => $e->getType()->value,
            'scope' => $e->isSiteWide() ? 'site' : 'mine',
            'detail' => $e->getNotes(),
        ], $evenements);
    }
}
