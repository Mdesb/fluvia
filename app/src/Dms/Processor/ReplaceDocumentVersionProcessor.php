<?php

declare(strict_types=1);

namespace App\Dms\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dms\Entity\Document;
use App\Dms\Security\DmsScopeGuard;
use App\Dms\Service\ReplaceVersionHandler;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /documents/{id}/replace-version` (multipart, fichier seul, RG-DMS-18/19, CA-8) —
 * `DmsScopeGuard` revérifié explicitement bien que `read: true` (RG-DMS-02).
 *
 * @implements ProcessorInterface<Document, Document>
 */
final class ReplaceDocumentVersionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DmsScopeGuard $scopeGuard,
        private readonly ReplaceVersionHandler $handler,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Document
    {
        \assert($data instanceof Document);
        $this->scopeGuard->verify($data->getEstablishment());

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new UnprocessableEntityHttpException('dms.error.request_missing');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new UnprocessableEntityHttpException('dms.error.file_required');
        }

        $acteur = $this->security->getUser();
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new UnprocessableEntityHttpException('dms.error.file_unreadable');
        }

        try {
            return $this->handler->replace(
                $data->getId(),
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
}
