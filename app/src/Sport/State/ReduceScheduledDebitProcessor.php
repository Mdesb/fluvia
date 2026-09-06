<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Sepa\Service\DebitPreNotifier;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Service\ScheduledDebitReductionHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /sport/echeances/{id}/reduire` — une offre sur un prélèvement à venir.
 *
 * Corps : `{ "montantCentimes": int, "motif": string }` — `montantCentimes` est CE QU'ON RETIRE,
 * pas le montant d'arrivée.
 *
 * ⚠ CE CHOIX DE NOMMAGE EST DÉLIBÉRÉ, ET IL EST LE PLUS RISQUÉ DE CE FICHIER. « moins 10 € » et
 * « à 10 € » se confondent dans une tête pressée, et la confusion ne produit ni erreur ni message :
 * elle produit un prélèvement faux. On nomme donc la réduction, jamais le solde — c'est la formule
 * de Maxime, « moins X euros sur son prochain prélèvement » — et la réponse rend `montantCentimes`
 * ET `montantInitialCentimes`, pour que l'écran montre les deux et que l'écart se lise.
 *
 * @implements ProcessorInterface<mixed, EcheanceSepa>
 */
final class ReduceScheduledDebitProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ScheduledDebitReductionHandler $handler,
        private readonly Security $security,
        private readonly DebitPreNotifier $preavis,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcheanceSepa
    {
        \assert($data instanceof EcheanceSepa);

        $corps = $this->lecteur->corps();

        /*
         * ⚠ ON EXIGE UN ENTIER, ON N'EN FABRIQUE PAS UN. `(int) "dix"` vaut 0, et `(int) "10,50"`
         * vaut 10 : deux saisies fausses qui passeraient en silence, l'une en ne retirant rien,
         * l'autre en retirant la moitié de ce qu'on croyait. Sur un prélèvement, une conversion
         * indulgente est un montant faux sans message.
         */
        $brut = $corps['montantCentimes'] ?? null;
        if (!\is_int($brut)) {
            throw new UnprocessableEntityHttpException(
                '« montantCentimes » est requis et doit être un entier de centimes — ce qu\'on RETIRE '
                . 'du prélèvement, pas le montant d\'arrivée.',
            );
        }

        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : '';

        $auteur = $this->security->getUser();

        $echeance = $this->handler->reduce($data, $brut, $motif, $auteur instanceof Utilisateur ? $auteur : null);

        /*
         * ⚠ LA CONSÉQUENCE QUE PERSONNE NE DEVINERAIT, DITE AU MOMENT DU GESTE.
         *
         * `DebitPreNotifier::reasonNotCovered()` refuse un prélèvement dont le montant diffère de
         * celui annoncé : « préavis émis pour X €, prélèvement de Y € ». Le préavis sera donc réémis,
         * et `announce()` remet `sentAt` à l'instant courant — délibérément : « un montant qui change
         * doit rendre au client la totalité du délai ». Un geste consenti trois jours avant
         * l'échéance la décale de deux semaines.
         *
         * ⚠ `null` PLUTÔT QU'UNE DATE INVENTÉE quand le mandat manque : sans mandat il n'y a pas de
         *   délai à appliquer, et surtout pas de prélèvement. Rendre aujourd'hui ferait croire que
         *   tout est prêt.
         */
        $mandat = $echeance->getAbonnement()?->getMandatSepa();

        return $echeance->setPrelevementPasAvant(
            $mandat === null ? null : $this->preavis->earliestDebitDate($mandat, new \DateTimeImmutable()),
        );
    }
}
