<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\PointDeVente;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Nf525\DailyClosureHandler;
use App\Vente\Nf525\Entity\DailyClosure;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Arrête la journée d'un point de vente (D57). Corps : `{ "journee": "2026-08-25" }` — par défaut,
 * la veille.
 *
 * **Pourquoi la veille et non le jour même.** Clore aujourd'hui à quinze heures arrêterait une journée
 * qui continue : les ventes de l'après-midi seraient scellées après un arrêté qui prétend les couvrir.
 * Le défaut par défaut est donc celui qui ne peut pas mentir. Clore le jour même reste permis — un
 * exploitant qui ferme à dix-neuf heures a le droit d'arrêter sa journée à dix-neuf heures — mais il
 * doit alors le demander explicitement.
 *
 * @implements ProcessorInterface<PointDeVente, DailyClosure>
 */
final class CloseDayProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DailyClosureHandler $handler,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $securite,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DailyClosure
    {
        if (!$data instanceof PointDeVente) {
            throw new NotFoundHttpException('Point de vente introuvable.');
        }

        // D8 — le point de vente est résolu depuis l'URL ; le contrôle porte sur l'entité résolue et
        // non sur l'en-tête. Échec fermé en 404 : un 403 confirmerait l'existence du point de vente
        // ailleurs, ce qui suffit à énumérer le parc d'un concurrent.
        $actif = $this->contexte->etablissementActif();
        if ((string) $data->getEtablissement()?->getId() !== (string) $actif?->getId()) {
            throw new NotFoundHttpException('Point de vente introuvable.');
        }

        $corps = $this->lecteur->corps();
        $journee = $this->journee($corps['journee'] ?? null);

        $auteur = $this->securite->getUser();

        return $this->handler->close($data, $journee, $auteur instanceof Utilisateur ? $auteur : null);
    }

    private function journee(mixed $brut): \DateTimeImmutable
    {
        if ($brut === null) {
            return new \DateTimeImmutable('yesterday');
        }

        if (!\is_string($brut) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $brut) !== 1) {
            // Refuser plutôt qu'interpréter : `new DateTimeImmutable('demain')` lèverait, mais
            // `'2026-13-45'` serait silencieusement reporté sur un autre mois — une clôture posée sur
            // une journée que personne n'a demandée.
            throw new UnprocessableEntityHttpException('Journée attendue au format AAAA-MM-JJ.');
        }

        $journee = \DateTimeImmutable::createFromFormat('!Y-m-d', $brut);
        if ($journee === false || $journee->format('Y-m-d') !== $brut) {
            throw new UnprocessableEntityHttpException(sprintf('Journée invalide : « %s ».', $brut));
        }

        return $journee;
    }
}
