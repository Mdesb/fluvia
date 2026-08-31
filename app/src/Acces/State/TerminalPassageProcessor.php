<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Dto\EvenementPassageDto;
use App\Acces\Entity\Passage;
use App\Acces\Enum\SensPassage;
use App\Acces\Security\TerminalPorteeChecker;
use App\Acces\Security\TerminalUtilisateur;
use App\Acces\Service\AffichagePorteurResolver;
use App\Acces\Service\CatalogueMessageAffichage;
use App\Acces\Service\ValidationPassageHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Validation en ligne d'un passage côté borne (POST /terminal/passages, US-TERM-02, CA-2/3/4). Décore
 * `PassageIngestionProcessor` (§2.2 du plan) : vérifie la portée (`equipementId` ∈ portée du `Terminal`
 * authentifié, sinon **403 avant tout appel moteur**, §4.1/4.2 spec), délègue à
 * `ValidationPassageHandler::valider()` (inchangé, `autoriserCreditNegatifSiHorsLigne` reste à `false`
 * par défaut = comportement en ligne strictement identique), puis **enrichit** la réponse technique
 * avec `message` et `affichage`. `POST /acces/passages` reste strictement inchangé (opération,
 * security, processor, réponse).
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class TerminalPassageProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ValidationPassageHandler $handler,
        private readonly Security $security,
        private readonly TerminalPorteeChecker $porteeChecker,
        private readonly CatalogueMessageAffichage $catalogue,
        private readonly AffichagePorteurResolver $affichageResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $terminalUtilisateur = $this->security->getUser();
        if (!$terminalUtilisateur instanceof TerminalUtilisateur) {
            throw new AccessDeniedHttpException('Terminal non authentifié.');
        }

        $corps = $this->lecteur->corps();

        $equipementId = $this->uuid($corps['equipementId'] ?? null);
        if ($equipementId === null) {
            throw new UnprocessableEntityHttpException('Référence d\'équipement obligatoire.');
        }

        // `cleIdempotence` obligatoire (durcissement revue sécurité) : une clé générée côté serveur ne
        // protège pas contre une retransmission réseau (chaque tentative recevrait une clé différente,
        // cassant l'anti-doublon). La borne doit fournir sa propre clé stable, rejouée à l'identique en
        // cas de retransmission.
        $cleBrute = $corps['cleIdempotence'] ?? null;
        $cleIdempotence = $this->uuid($cleBrute);
        if ($cleIdempotence === null) {
            // ⚠ DEUX CAUSES, DEUX MESSAGES. L'ancien disait « obligatoire » a un appelant qui
            // l'avait ENVOYEE : elle n'etait simplement pas un UUID. Il relisait son code, y voyait
            // le champ, et cherchait ailleurs. Un message qui designe une cause fausse coute plus
            // cher qu'un message vague — il envoie chercher au mauvais endroit, avec l'autorite
            // d'une reponse du serveur. Rencontre en exercant l'API pour le guide IT Cotation.
            throw new UnprocessableEntityHttpException(
                \is_string($cleBrute) && $cleBrute !== ''
                    ? 'Clé d\'idempotence invalide : un UUID est attendu.'
                    : 'Clé d\'idempotence obligatoire : engendrez un UUID au moment du scan et conservez-le pour le rejeu.'
            );
        }

        // Résolution de portée AVANT tout appel moteur (§4.1/4.2 spec) : refus sans fuite d'info.
        $this->porteeChecker->verifierEquipement($terminalUtilisateur, $equipementId);

        // Idempotence (retransmission réseau) : une clé déjà vue rejoue la même réponse sans
        // reconsommer le crédit/la jauge (mêmes garanties que `/terminal/passages/lot`, CA-7) — évite
        // aussi la violation de la contrainte unique `uniq_passage_cle_idempotence` en base.
        $existant = $this->em->getRepository(Passage::class)->findOneBy(['cleIdempotence' => $cleIdempotence]);
        if ($existant instanceof Passage) {
            return $this->reponse($existant);
        }

        $horodatageBorne = isset($corps['horodatageBorne']) ? new \DateTimeImmutable((string) $corps['horodatageBorne']) : null;

        $evt = new EvenementPassageDto(
            equipementId: $equipementId,
            identifiantSupport: isset($corps['identifiantSupport']) ? (string) $corps['identifiantSupport'] : null,
            sens: isset($corps['sens']) ? SensPassage::tryFrom((string) $corps['sens']) : null,
            horodatage: $horodatageBorne ?? new \DateTimeImmutable(),
            cleIdempotence: $cleIdempotence,
            horodatageBorne: $horodatageBorne,
        );

        $passage = $this->handler->valider($evt);

        return $this->reponse($passage);
    }

    private function reponse(Passage $passage): JsonResponse
    {
        $codeMessage = $this->catalogue->pour($passage->getCodeMotif(), $passage->getResultat());

        return new JsonResponse([
            'resultat' => $passage->getResultat()->value,
            'codeMotif' => $passage->getCodeMotif()?->value,
            'horodatageServeur' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'message' => [
                'codeMessage' => $codeMessage->value,
                'libelle' => $this->catalogue->libelle($codeMessage, $passage->getEtablissement()),
            ],
            'affichage' => $this->affichageResolver->resoudre($passage),
        ], JsonResponse::HTTP_OK);
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
