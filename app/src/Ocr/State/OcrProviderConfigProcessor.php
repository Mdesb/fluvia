<?php

declare(strict_types=1);

namespace App\Ocr\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Ocr\Entity\OcrProviderConfig;
use App\Ocr\Service\ChiffreurApiKeyOcr;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Création/mise à jour de la configuration OCR d'un établissement (RG-OCR-06). `establishment` est
 * **toujours** dérivé côté serveur (`ContexteEtablissement::etablissementActif()`, invariant noyau
 * commun #1) — jamais du corps de la requête (la propriété n'appartient à aucun groupe d'écriture) :
 * aucun id transmis par le client ne fait jamais autorité (échec fermé, cohérent avec le rappel
 * explicite de la mission sur les IDOR déjà corrigés ailleurs, à ne pas reproduire).
 *
 * @implements ProcessorInterface<OcrProviderConfig, OcrProviderConfig>
 */
final class OcrProviderConfigProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexteEtablissement,
        private readonly ChiffreurApiKeyOcr $chiffreur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OcrProviderConfig
    {
        \assert($data instanceof OcrProviderConfig);

        $etablissement = $this->contexteEtablissement->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('ocr.error.no_active_establishment');
        }

        if ($operation instanceof Post) {
            $existant = $this->em->getRepository(OcrProviderConfig::class)->findOneBy(['establishment' => $etablissement]);
            if ($existant !== null) {
                // 1 configuration par établissement (RG-OCR-06, contrainte unique) : geste attendu côté
                // client est un PATCH sur la ressource existante, pas un doublon silencieux.
                throw new ConflictHttpException('ocr.error.config_already_exists');
            }
            $data->setEstablishment($etablissement);
        } else {
            // Patch : périmètre serveur revérifié explicitement (échec fermé) avant toute écriture, même
            // si l'entité a déjà été filtrée par `PerimetreOcrExtension` côté lecture — défense en
            // profondeur, jamais un id client qui ferait autorité.
            $etablissementEntite = $data->getEstablishment();
            if ($etablissementEntite === null || (string) $etablissementEntite->getId() !== (string) $etablissement->getId()) {
                throw new NotFoundHttpException();
            }
        }

        $clair = $data->getApiKeyPlain();
        if (trim($clair) !== '') {
            $data->setApiKeyEncrypted($this->chiffreur->chiffrer($clair));
        }
        $data->setApiKeyPlain('');

        $data->touchUpdatedAt();
        $this->em->persist($data);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Course POST/POST concurrente sur le même établissement : la contrainte unique
            // (1 configuration/établissement, RG-OCR-06) tranche en base — on renvoie le 409 attendu
            // plutôt qu'une 500 non gérée.
            throw new ConflictHttpException('ocr.error.config_already_exists', $e);
        }

        return $data;
    }
}
