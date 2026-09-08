<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Enum\CanalResolutionImpaye;
use App\Recouvrement\Service\ResolutionImpayeHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /recouvrement/incidents/{id}/resoudre — constate qu'un impayé a été réglé.
 *
 * Corps : { "canal": "virement|caisse|autre|app_1_clic", "moyenPaiement": string,
 *           "dateEncaissement"?: "AAAA-MM-JJ", "reference"?: string }.
 *
 * ⚠ L'OPÉRATION ÉTAIT DÉCLARÉE `input: false` — aucun corps n'était accepté, et le canal était posé
 * en dur à `app_1_clic`. Lecture par `LecteurCorps` puis validation dans le handler : c'est la
 * convention déjà tenue par `ForcerReouvertureProcessor`, l'opération sœur du même module. Introduire
 * ici un DTO validé par API Platform aurait fait cohabiter deux façons de faire à quatre lignes
 * d'écart, pour le même geste.
 *
 * @implements ProcessorInterface<IncidentImpaye, IncidentImpaye>
 */
final class ResoudreImpayeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ResolutionImpayeHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IncidentImpaye
    {
        \assert($data instanceof IncidentImpaye);

        // Même garde que `ForcerReouvertureProcessor` : constater un règlement rouvre un accès et
        // éteint une dette. Le dossier doit dire QUI l'a déclaré — jusqu'ici il ne le disait pas.
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur non authentifié.');
        }

        $corps = $this->lecteur->corps();

        $canalBrut = \is_string($corps['canal'] ?? null) ? $corps['canal'] : '';
        $canal = CanalResolutionImpaye::tryFrom($canalBrut);
        if ($canal === null) {
            throw new UnprocessableEntityHttpException(sprintf(
                '« canal » est requis et doit valoir : %s.',
                implode(', ', array_map(
                    static fn (CanalResolutionImpaye $c): string => $c->value,
                    CanalResolutionImpaye::cases(),
                )),
            ));
        }

        $moyen = \is_string($corps['moyenPaiement'] ?? null) ? trim($corps['moyenPaiement']) : '';
        if ($moyen === '') {
            throw new UnprocessableEntityHttpException(
                '« moyenPaiement » est requis (code du référentiel de l\'établissement).',
            );
        }

        // ⚠ LA DATE PAR DÉFAUT EST AUJOURD'HUI, ET UNE DATE ILLISIBLE EST REFUSÉE — jamais remplacée
        //    en silence. Une date d'encaissement fausse range l'écriture dans le mauvais exercice, et
        //    personne ne s'en aperçoit avant la clôture.
        $dateBrute = $corps['dateEncaissement'] ?? null;
        if (\is_string($dateBrute) && $dateBrute !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateBrute);
            if ($date === false) {
                throw new UnprocessableEntityHttpException('« dateEncaissement » doit être au format AAAA-MM-JJ.');
            }
        } else {
            $date = new \DateTimeImmutable('today');
        }

        $reference = \is_string($corps['reference'] ?? null) ? $corps['reference'] : null;

        return $this->handler->resoudre($data, $canal, $moyen, $date, $reference, $utilisateur);
    }
}
