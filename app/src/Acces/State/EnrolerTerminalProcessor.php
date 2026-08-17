<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutTerminal;
use App\Acces\Service\JetonTerminalFactory;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Enrôlement d'un `Terminal` (POST /acces/terminaux, US-TERM-01, CA-1). Crée le `Terminal` (statut
 * `actif` dès la création, décision §1.1 du plan) + son premier `JetonTerminal`, renvoie **le secret en
 * clair une seule fois** (même patron que la génération de mot de passe temporaire, RG-SOCLE-06).
 * Corps : { "nom": string, "itboxRef": string }.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class EnrolerTerminalProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly JetonTerminalFactory $factory,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();

        $nom = trim((string) ($corps['nom'] ?? ''));
        $itboxRef = trim((string) ($corps['itboxRef'] ?? ''));
        if ($nom === '' || $itboxRef === '') {
            throw new UnprocessableEntityHttpException('Nom et référence ITBOX obligatoires.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $terminal = new Terminal();
        $terminal->setNom($nom)
            ->setItboxRef($itboxRef)
            ->setStatut(StatutTerminal::Actif)
            ->setEtablissement($etablissement);
        $this->em->persist($terminal);

        [$jeton, $secret] = $this->factory->emettre($terminal);
        $this->em->persist($jeton);
        $this->em->flush();

        return new JsonResponse([
            'id' => (string) $terminal->getId(),
            'nom' => $terminal->getNom(),
            'itboxRef' => $terminal->getItboxRef(),
            'statut' => $terminal->getStatut()->value,
            'secret' => $secret,
        ], JsonResponse::HTTP_CREATED);
    }
}
