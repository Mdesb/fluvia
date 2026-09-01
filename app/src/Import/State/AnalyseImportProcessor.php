<?php

declare(strict_types=1);

namespace App\Import\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Import\Service\ImportAnalyser;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /imports` — dépose un fichier, le juge, **n'écrit rien en base métier**.
 *
 * Corps : `{ "type": "customers", "fileName": "...", "content": "<le CSV>" }`.
 *
 * ── L'ÉTABLISSEMENT VIENT DE LA SESSION, JAMAIS DU FICHIER (D41, SPEC §5) ───────────────────────
 *
 * ⚠ Une colonne qui désignerait où écrire serait une porte ouverte chez le voisin — et le symptôme,
 * une ligne **en trop** chez quelqu'un d'autre, n'est jamais remonté à un import par celui qui le
 * subit. Le fichier ne choisit pas sa destination.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class AnalyseImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
        private readonly ImportAnalyser $analyser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportBatch
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Aucun établissement actif : impossible de rattacher cette reprise.');
        }

        $type = ImportType::tryFrom(\is_string($corps['type'] ?? null) ? $corps['type'] : '');
        if ($type === null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Champ « type » obligatoire. Valeurs reprises aujourd\'hui : %s.',
                implode(', ', array_map(static fn (ImportType $t): string => $t->value, ImportType::implemented())),
            ));
        }

        $contenu = \is_string($corps['content'] ?? null) ? $corps['content'] : '';
        if (trim($contenu) === '') {
            throw new UnprocessableEntityHttpException('Champ « content » obligatoire : le fichier à reprendre.');
        }

        $empreinte = hash('sha256', $contenu);

        // Le même fichier déposé deux fois se reconnaît. On refuse plutôt que d'ouvrir un second lot :
        // deux lots identiques rendraient l'annulation ambiguë — lequel a créé quoi ?
        $existant = $this->em->getRepository(ImportBatch::class)->findOneBy([
            'establishment' => $etablissement,
            'contentHash' => $empreinte,
        ]);
        if ($existant instanceof ImportBatch) {
            throw new ConflictHttpException(sprintf(
                'Ce fichier a déjà été déposé (lot %s, « %s »).',
                $existant->getId(),
                $existant->getStatus()->value,
            ));
        }

        $utilisateur = $this->security->getUser();

        $batch = new ImportBatch();
        $batch
            ->setEstablishment($etablissement)
            ->setType($type)
            ->setFileName(\is_string($corps['fileName'] ?? null) ? $corps['fileName'] : 'import.csv')
            ->setMimeType('text/csv')
            ->setFileSize(\strlen($contenu))
            ->setContentHash($empreinte)
            ->setContent($contenu)
            ->setCreatedBy($utilisateur instanceof Utilisateur ? $utilisateur : null);

        $this->analyser->analyse($batch, $etablissement);

        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }
}
