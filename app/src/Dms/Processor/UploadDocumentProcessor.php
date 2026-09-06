<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /documents` (multipart, plan-dms.md §2/§2.3) — `input: false` : corps lu manuellement depuis le
 * `Request` courant (idiome du dépôt, cf. `App\Vente\Service\LecteurCorps` pour le JSON). `establishment`
 * dérivé **serveur** de `ContexteEtablissement::etablissementActif()` — jamais d'un champ client
 * (RG-DMS... §3.3) : échec fermé (422) si aucun établissement actif résolvable.
 *
 * @implements ProcessorInterface<mixed, Document>
 */
final class UploadDocumentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UploadDocumentHandler $handler,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
        #[Autowire(env: 'int:DMS_MAX_UPLOAD_BYTES')] private readonly int $maxUploadBytes,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Document
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new UnprocessableEntityHttpException('dms.error.request_missing');
        }

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException(
                'dms.error.establishment_missing : aucun établissement actif résolvable, upload refusé.',
            );
        }

        $categoryValue = $request->request->get('category');
        if (!\is_string($categoryValue) || $categoryValue === '') {
            throw new UnprocessableEntityHttpException('dms.error.category_required');
        }
        try {
            $category = DocumentCategory::from($categoryValue);
        } catch (\ValueError) {
            throw new UnprocessableEntityHttpException('dms.error.category_invalid');
        }

        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            throw new UnprocessableEntityHttpException('dms.error.title_required');
        }

        $tags = $this->readTags($request);
        $sourceModuleRaw = $request->request->get('sourceModule');
        $sourceModule = \is_string($sourceModuleRaw) && $sourceModuleRaw !== '' ? $sourceModuleRaw : null;

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new UnprocessableEntityHttpException('dms.error.file_required');
        }
        if ($file->getSize() !== null && $file->getSize() > $this->maxUploadBytes) {
            throw new UnprocessableEntityHttpException('dms.error.file_too_large');
        }

        $acteur = $this->security->getUser();

        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new UnprocessableEntityHttpException('dms.error.file_unreadable');
        }

        try {
            return $this->handler->upload(
                $etablissement,
                $category,
                $title,
                $tags,
                $sourceModule,
                $stream,
                $file->getClientOriginalName(),
                // ⚠ LE TYPE DÉCLARÉ PAR LE CLIENT N'EST PAS UNE INFORMATION, C'EST UNE PRÉTENTION. Un HTML
                //   annoncé « application/pdf » était stocké puis servi comme un PDF (audit 06/09, constat
                //   6). `getMimeType()` regarde le contenu (finfo) ; ce qu'il voit est ce qu'on range.
                $file->getMimeType() ?? 'application/octet-stream',
                $acteur instanceof Utilisateur ? $acteur : null,
            );
        } finally {
            fclose($stream);
        }
    }

    /** @return list<string>|null */
    private function readTags(Request $request): ?array
    {
        $valeur = $request->request->get('tags');
        if (!\is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        $decode = json_decode($valeur, true);
        if (\is_array($decode)) {
            return array_values(array_map(static fn (mixed $v): string => (string) $v, $decode));
        }

        // Repli : liste séparée par des virgules (formulaire simple, hors JSON).
        return array_values(array_filter(
            array_map('trim', explode(',', $valeur)),
            static fn (string $t): bool => $t !== '',
        ));
    }
}
