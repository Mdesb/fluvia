<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Padel\ApiResource\ClassementTournoi;
use App\Padel\Entity\MatchTournoi;
use App\Padel\Entity\Poule;
use App\Padel\Entity\Tournoi;
use App\Padel\Enum\FormatTournoi;
use App\Padel\Enum\StatutMatchTournoi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /padel/tournois/{id}/classement (US-PADEL-06, CA-7) : recalcule le classement (poules, nombre
 * de victoires) ou l'avancement (tableau) à la lecture, sans dénormalisation persistée.
 *
 * @implements ProviderInterface<ClassementTournoi>
 */
final class ClassementTournoiProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClassementTournoi
    {
        $id = $uriVariables['id'] ?? null;
        $tournoi = \is_string($id) && Uuid::isValid($id) ? $this->em->getRepository(Tournoi::class)->find($id) : null;
        if (!$tournoi instanceof Tournoi) {
            throw new NotFoundHttpException('Tournoi introuvable.');
        }

        $vue = new ClassementTournoi();
        $vue->id = (string) $tournoi->getId();
        $vue->format = $tournoi->getFormat()->value;

        /** @var list<MatchTournoi> $matchs */
        $matchs = $this->em->getRepository(MatchTournoi::class)->findBy(['tournoi' => $tournoi]);

        $vue->matchs = array_map(static fn (MatchTournoi $m) => [
            'id' => (string) $m->getId(),
            'tour' => $m->getTour(),
            'statut' => $m->getStatut()->value,
            'paireA' => (string) $m->getPaireA()?->getId(),
            'paireB' => (string) $m->getPaireB()?->getId(),
            'vainqueur' => $m->getVainqueur() !== null ? (string) $m->getVainqueur()->getId() : null,
        ], $matchs);

        if ($tournoi->getFormat() === FormatTournoi::Poules) {
            /** @var list<Poule> $poules */
            $poules = $this->em->getRepository(Poule::class)->findBy(['tournoi' => $tournoi]);
            foreach ($poules as $poule) {
                $victoires = [];
                foreach ($matchs as $match) {
                    if ($match->getPoule() === null || (string) $match->getPoule()->getId() !== (string) $poule->getId()) {
                        continue;
                    }
                    foreach ([$match->getPaireA(), $match->getPaireB()] as $paire) {
                        if ($paire === null) {
                            continue;
                        }
                        $cle = (string) $paire->getId();
                        $victoires[$cle] ??= ['inscription' => $paire, 'victoires' => 0, 'matchsJoues' => 0];
                    }
                    if ($match->getStatut() !== StatutMatchTournoi::Joue) {
                        continue;
                    }
                    foreach ([$match->getPaireA(), $match->getPaireB()] as $paire) {
                        if ($paire === null) {
                            continue;
                        }
                        $victoires[(string) $paire->getId()]['matchsJoues']++;
                    }
                    if ($match->getVainqueur() !== null) {
                        $victoires[(string) $match->getVainqueur()->getId()]['victoires']++;
                    }
                }

                $classement = array_values(array_map(static fn (array $e) => [
                    'inscription' => (string) $e['inscription']->getId(),
                    'joueur1' => (string) ($e['inscription']->getJoueur1()?->getId() ?? ''),
                    'joueur2' => (string) ($e['inscription']->getJoueur2()?->getId() ?? ''),
                    'victoires' => $e['victoires'],
                    'matchsJoues' => $e['matchsJoues'],
                ], $victoires));

                usort($classement, static fn (array $a, array $b) => $b['victoires'] <=> $a['victoires']);

                $vue->poules[] = ['poule' => $poule->getLibelle(), 'classement' => $classement];
            }
        }

        return $vue;
    }
}
