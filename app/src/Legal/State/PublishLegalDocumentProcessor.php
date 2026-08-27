<?php

declare(strict_types=1);

namespace App\Legal\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Legal\Entity\LegalDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * `POST /legal/documents/{id}/publier` — fige le texte et **conserve la version précédente**.
 *
 * **Publier n'est pas enregistrer.** Un document publié est celui que le client lira et, pour les CGV,
 * celui qu'il acceptera. Il ne peut donc plus bouger : toute modification ultérieure passe par une
 * nouvelle version, et l'ancienne reste lisible telle qu'elle engageait.
 *
 * C'est le raisonnement du scellement NF525 transposé au texte — à ceci près qu'ici rien ne hache
 * quoi que ce soit : la garantie tient à ce qu'**aucun chemin n'écrase une ligne publiée**, et c'est
 * la seule raison pour laquelle ce processeur duplique au lieu de modifier.
 *
 * **Le brouillon d'avertissement est refusé, et ce n'est pas de la rigueur pour la rigueur.** Le
 * générateur place en tête de chaque texte un bloc « à faire relire ». Le laisser publier signifierait
 * que personne n'a relu — et le publier avec l'avertissement visible sur la boutique serait pire que
 * de ne rien publier.
 */
final class PublishLegalDocumentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LegalDocument
    {
        \assert($data instanceof LegalDocument);

        if (str_contains($data->getContent(), 'Brouillon généré automatiquement')) {
            throw new ConflictHttpException(
                'Le texte porte encore l’avertissement de brouillon : relisez-le et retirez ce bloc '
                . 'avant de publier.',
            );
        }

        if (trim($data->getContent()) === '') {
            throw new ConflictHttpException('Un document vide ne se publie pas.');
        }

        // La version publiée précédente ne disparaît pas : elle passe en « remplacée » et reste lisible.
        // Sans ça, on ne pourrait pas montrer ce que disaient les CGV le jour d'une commande contestée.
        $anciennes = $this->em->getRepository(LegalDocument::class)->findBy([
            'establishment' => $data->getEstablishment(),
            'type' => $data->getType(),
            'status' => LegalDocument::STATUS_PUBLISHED,
        ]);

        $versionMax = 0;
        foreach ($anciennes as $ancienne) {
            \assert($ancienne instanceof LegalDocument);
            if ($ancienne->getId()->equals($data->getId())) {
                continue;
            }
            $versionMax = max($versionMax, $ancienne->getVersion());
            $ancienne->setStatus(LegalDocument::STATUS_SUPERSEDED);
        }

        $data
            ->setStatus(LegalDocument::STATUS_PUBLISHED)
            ->setVersion(max($versionMax, $data->getVersion()) + 1)
            ->setPublishedAt(new \DateTimeImmutable());

        $this->em->flush();

        return $data;
    }
}
