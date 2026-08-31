<?php

declare(strict_types=1);

namespace App\Legal\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Legal\Entity\LegalDocument;
use App\Legal\Entity\LegalIdentity;
use App\Legal\Enum\LegalDocumentType;
use App\Legal\Service\LegalDocumentGenerator;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /legal/identites/{id}/generer` — (re)compose les six brouillons depuis la fiche.
 *
 * **Ce processeur ne touche jamais un document publié.** Régénérer après avoir complété la fiche est le
 * geste normal — l'exploitant ajoute son SIRET, relance, et retrouve ses textes remplis. Si la
 * régénération écrasait le publié, elle réintroduirait l'avertissement de brouillon **sur la boutique
 * en ligne**, et remplacerait un texte relu par un texte qui ne l'est pas.
 *
 * > **Un geste de confort ne doit jamais pouvoir défaire un geste d'engagement.**
 *
 * Les brouillons existants sont donc remplis en place — l'exploitant qui avait commencé à retoucher un
 * texte perd sa retouche, et c'est assumé : il a demandé une régénération. Les publiés sont laissés
 * intacts et **comptés dans la réponse**, pour que l'écran puisse dire lesquels n'ont pas bougé plutôt
 * que de laisser croire que tout a été refait.
 */
final class GenerateLegalDocumentsProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LegalDocumentGenerator $generator,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof LegalIdentity);

        $etablissement = $data->getEstablishment();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('La fiche n’est rattachée à aucun établissement.');
        }

        $corps = $this->lecteur->corps();
        $nomSite = \is_string($corps['nomSite'] ?? null) && trim($corps['nomSite']) !== ''
            ? trim($corps['nomSite'])
            : $etablissement->getNom();

        $ecrits = [];
        $intacts = [];

        foreach (LegalDocumentType::cases() as $type) {
            $existants = $this->em->getRepository(LegalDocument::class)->findBy([
                'establishment' => $etablissement,
                'type' => $type,
            ]);

            $brouillon = null;
            $aUnPublie = false;
            foreach ($existants as $document) {
                \assert($document instanceof LegalDocument);
                if ($document->getStatus() === LegalDocument::STATUS_PUBLISHED) {
                    $aUnPublie = true;
                    continue;
                }
                if ($document->getStatus() === LegalDocument::STATUS_DRAFT && $brouillon === null) {
                    $brouillon = $document;
                }
            }

            if ($aUnPublie && $brouillon === null) {
                // Un texte publié et aucun brouillon en cours : on en ouvre un NOUVEAU plutôt que de
                // ne rien faire. Sans ça, l'exploitant qui corrige sa fiche après publication n'aurait
                // aucun moyen de reprendre le texte — il n'aurait que le bouton qui ne fait rien.
                $brouillon = (new LegalDocument())->setEstablishment($etablissement)->setType($type);
                $this->em->persist($brouillon);
                $intacts[] = $type->value;
            } elseif ($brouillon === null) {
                $brouillon = (new LegalDocument())->setEstablishment($etablissement)->setType($type);
                $this->em->persist($brouillon);
            }

            $this->generator->fill($brouillon, $data, $nomSite);
            $ecrits[] = $type->value;
        }

        $this->em->flush();

        return new JsonResponse([
            'etablissement' => (string) $etablissement->getId(),
            'brouillonsEcrits' => $ecrits,
            // Ce que la régénération n'a PAS remplacé : les textes publiés restent en ligne tels quels.
            'publiesInchanges' => $intacts,
        ]);
    }
}
