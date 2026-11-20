<?php

declare(strict_types=1);

namespace App\Platform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Platform\ApiResource\EditorNf525Alert;
use App\Platform\Entity\Nf525Alert;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Security\EditorOnly;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * La note de l'éditeur sur une alerte NF525 : le seul geste d'écriture de l'API (G-6).
 *
 * Elle ne modifie ni le statut ni les compteurs. Bornée (2000 caractères) AVANT toute écriture ; vide =
 * efface. Tracée au journal d'audit : sa longueur et son effacement, pas son texte (elle peut citer du
 * technique, Q-4).
 *
 * @cloisonnement-verifie: l'alerte n'appartient à aucun établissement client : `EditorOnly::assertEditor('editor.nf525_alerts')`, première ligne, ne laisse passer que le tenant éditeur et le droit ; l'éditeur voit toutes les alertes, c'est le sens de la ressource.
 */
final class EditorNf525AlertNoteProcessor implements ProcessorInterface
{
    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly LecteurCorps $lecteur,
        private readonly JournalAudit $journal,
        private readonly Security $security,
        private readonly EditorNf525AlertProvider $provider,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EditorNf525Alert
    {
        $this->editorOnly->assertEditor('editor.nf525_alerts');

        $id = (string) ($uriVariables['id'] ?? '');
        $alert = Uuid::isValid($id) ? $this->em->getRepository(Nf525Alert::class)->find(Uuid::fromString($id)) : null;
        if (!$alert instanceof Nf525Alert) {
            throw new NotFoundHttpException();
        }

        $body = $this->lecteur->corps();
        $raw = $body['note'] ?? null;
        if (!\is_string($raw)) {
            throw new UnprocessableEntityHttpException('Le corps doit contenir « note » (texte ; vide pour l\'effacer).');
        }
        // Espaces Unicode compris (insécables, etc.) : une note faite de blancs efface.
        $note = (string) preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $raw);
        if (mb_strlen($note) > self::MAX_LENGTH) {
            throw new UnprocessableEntityHttpException(sprintf('La note ne peut pas dépasser %d caractères.', self::MAX_LENGTH));
        }

        $now = new \DateTimeImmutable();
        $user = $this->security->getUser();
        $author = $user instanceof Utilisateur ? $user->getId()->toRfc4122() : null; // RGPD : l'identifiant, pas l'e-mail
        $had = null !== $alert->getNote();

        $alert->annotate('' === $note ? null : $note, $now, $author);

        $this->journal->enregistrer('nf525.alerte.note', 'Nf525Alert', $alert->getId()->toRfc4122())
            ->setValeurApres([
                'reference' => $alert->getReference(),
                'effacee' => '' === $note,
                'longueur' => mb_strlen($note),
                'remplacait' => $had,
            ]);

        $this->em->flush();

        return $this->provider->present($alert);
    }
}
