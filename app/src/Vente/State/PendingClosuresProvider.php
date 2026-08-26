<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Caisse\Entity\PointDeVente;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Enum\StatutVente;
use App\Vente\Nf525\Dto\PendingClosure;
use App\Vente\Nf525\Entity\DailyClosure;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La file des journées non closes de l'établissement actif (D57, D55).
 *
 * **Le détecteur existait déjà** — le refus « journée sautée » du `DailyClosureHandler`. Ce qui
 * manquait est qu'il ne parlait qu'à celui qui tentait une clôture : une journée oubliée restait donc
 * invisible jusqu'à ce que quelqu'un bute dessus, éventuellement des semaines plus tard. Ce
 * fournisseur retourne la détection : la liste se lit, elle descend, et elle se vide.
 *
 * **Le regroupement par journée est fait en PHP, délibérément.** Une journée d'exploitation se compte
 * dans le fuseau de l'établissement, or `vente_vente.date` est un `DATETIME` sans fuseau écrit à
 * l'heure du serveur. Laisser la base grouper par `DATE(date)` donnerait les journées **du serveur** :
 * juste tant que les deux coïncident, faux ensuite, et faux **sans rien signaler** — la requête
 * marcherait parfaitement et rendrait les mauvaises journées. `CONVERT_TZ()` de MariaDB serait exact
 * mais exige les tables de fuseaux, qui ne sont pas chargées partout ; s'en remettre à elles ferait
 * dépendre une clôture NF525 d'une option d'installation.
 *
 * Le coût est borné par la donnée : on ne lit que les dates postérieures au dernier arrêté, c'est-à-dire
 * une journée en régime normal. Sur un arriéré de trois semaines, quelques milliers de valeurs d'une
 * seule colonne — et c'est précisément le cas où l'on veut une réponse exacte.
 *
 * @implements ProviderInterface<PendingClosure>
 */
final class PendingClosuresProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /** @return list<PendingClosure> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            // Fermeture par défaut : sans établissement actif, on ne montre pas « tout », on ne montre
            // rien. Un écran vide se signale ; une liste inter-établissements ne se remarquerait pas.
            return [];
        }

        /** @var list<PointDeVente> $points */
        $points = $this->em->getRepository(PointDeVente::class)->findBy(['etablissement' => $etablissement]);

        $file = [];
        foreach ($points as $pdv) {
            foreach ($this->journeesOuvertes($pdv, $etablissement->getFuseauHoraire()) as $journee => $nombre) {
                $file[] = new PendingClosure(
                    pointDeVente: (string) $pdv->getId(),
                    libelle: $pdv->getLibelle(),
                    etablissement: $etablissement->getNom(),
                    fuseauHoraire: $etablissement->getFuseauHoraire(),
                    journee: (string) $journee,
                    nombreVentes: $nombre,
                    joursDeRetard: $this->retard((string) $journee, $etablissement->getFuseauHoraire()),
                    raison: 'Journée porteuse de ventes, jamais arrêtée.',
                );
            }
        }

        // La plus ancienne d'abord : c'est celle qu'il faut clôturer en premier, puisque le cumul
        // perpétuel refuse qu'on saute une journée.
        usort($file, static fn (PendingClosure $a, PendingClosure $b): int => $a->journee <=> $b->journee);

        return $file;
    }

    /**
     * Journées porteuses de ventes, postérieures au dernier arrêté, et déjà terminées.
     *
     * @return array<string, int> journée (AAAA-MM-JJ dans le fuseau) => nombre de ventes
     */
    private function journeesOuvertes(PointDeVente $pdv, string $fuseau): array
    {
        $tz = new \DateTimeZone($fuseau);
        $serveur = new \DateTimeZone(date_default_timezone_get());

        $derniere = $this->derniereCloture($pdv);
        $depuis = $derniere !== null
            ? (new \DateTimeImmutable($derniere->format('Y-m-d') . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($serveur)
            : new \DateTimeImmutable('@0');

        // La journée en cours n'est pas en retard : elle n'est pas finie.
        $aujourdhui = (new \DateTimeImmutable('now', $tz))->setTime(0, 0);
        $fin = (new \DateTimeImmutable($aujourdhui->format('Y-m-d') . ' 00:00:00', $tz))->setTimezone($serveur);

        /** @var list<array{date: \DateTimeInterface}> $lignes */
        $lignes = $this->em->getRepository(\App\Vente\Entity\Vente::class)->createQueryBuilder('v')
            ->select('v.date')
            ->andWhere('v.pointDeVente = :pdv')
            ->andWhere('v.statut != :encours')
            ->andWhere('v.date >= :depuis')
            ->andWhere('v.date < :fin')
            // D58 — l'identifiant et son type, jamais l'entité.
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->setParameter('encours', StatutVente::EnCours->value)
            ->setParameter('depuis', $depuis)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult();

        $parJournee = [];
        foreach ($lignes as $ligne) {
            $instant = $ligne['date'];
            if (!$instant instanceof \DateTimeInterface) {
                continue;
            }
            $journee = \DateTimeImmutable::createFromInterface($instant)->setTimezone($tz)->format('Y-m-d');
            $parJournee[$journee] = ($parJournee[$journee] ?? 0) + 1;
        }

        // Une journée déjà arrêtée n'est pas en attente — cas possible si un arrêté a été posé sur une
        // journée sans vente puis que des ventes antidatées sont apparues.
        foreach (array_keys($parJournee) as $journee) {
            $existante = $this->em->getRepository(DailyClosure::class)->findOneBy([
                'pointDeVente' => $pdv,
                'businessDay' => new \DateTimeImmutable((string) $journee),
            ]);
            if ($existante !== null) {
                unset($parJournee[$journee]);
            }
        }

        ksort($parJournee);

        return $parJournee;
    }

    private function derniereCloture(PointDeVente $pdv): ?\DateTimeImmutable
    {
        /** @var DailyClosure|null $cloture */
        $cloture = $this->em->getRepository(DailyClosure::class)->createQueryBuilder('c')
            ->andWhere('c.pointDeVente = :pdv')
            ->setParameter('pdv', $pdv->getId(), 'uuid')
            ->orderBy('c.businessDay', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $cloture?->getBusinessDay();
    }

    private function retard(string $journee, string $fuseau): int
    {
        $tz = new \DateTimeZone($fuseau);
        $jour = new \DateTimeImmutable($journee . ' 00:00:00', $tz);
        $aujourdhui = (new \DateTimeImmutable('now', $tz))->setTime(0, 0);

        return (int) $jour->diff($aujourdhui)->days;
    }
}
