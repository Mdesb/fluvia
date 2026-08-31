<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Subscription\ApiResource\EditorSupportAccess;
use App\Subscription\Entity\SupportAccess;
use App\Subscription\Security\EditorOnly;
use App\Subscription\Service\SupportAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /editor/support-accesses/{id}/revoke` — refermer un accès avant son terme.
 *
 * **La révocation est un geste, pas une expiration.** Un accès qui ne se referme que par le temps est
 * un accès qu'on oublie ouvert : l'intervention dure vingt minutes, la fenêtre en dure deux heures, et
 * pendant une heure quarante quelqu'un peut lire les données d'un client sans raison. Le terme est un
 * filet de sécurité, pas une méthode de travail.
 *
 * **L'entrée n'est pas supprimée : elle est datée.** C'est l'historique de qui a pu voir quoi, et il
 * doit survivre à la fermeture. Effacer l'accès effacerait la preuve qu'il a existé — exactement ce
 * qu'on veut pouvoir montrer au client qui demande.
 *
 * **Révoquer deux fois ne lève pas.** Deux agents peuvent fermer le même accès depuis deux écrans ;
 * transformer ça en erreur ferait chercher un problème là où il n'y en a pas. La première date
 * l'emporte, parce que c'est elle qui dit quand la porte s'est refermée.
 */
final class RevokeSupportAccessProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EditorOnly $editorOnly,
        private readonly SupportAccessGuard $guard,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EditorSupportAccess
    {
        $this->editorOnly->assertEditor('editor.support_access');

        $reference = $uriVariables['id'] ?? null;
        if (!\is_string($reference) || !Uuid::isValid($reference)) {
            throw new NotFoundHttpException();
        }

        // @cloisonnement-verifie : un `SupportAccess` n'appartient à aucun tenant client — il vit du
        // côté éditeur, et `EditorOnly::assertEditor()` ci-dessus restreint déjà l'appelant au tenant
        // éditeur (404, pas 403). Il n'y a donc pas d'établissement auquel comparer `$access`.
        //
        // Et le droit de révocation est volontairement large : révoquer ne fait que **retirer** un
        // accès, l'opération est idempotente, et n'importe quel agent de l'éditeur doit pouvoir
        // refermer une porte qu'il voit ouverte — y compris celle qu'un collègue a oublié de fermer.
        // Restreindre la révocation à celui qui a ouvert produirait exactement l'accès qui traîne.
        $access = $this->em->getRepository(SupportAccess::class)->find(Uuid::fromString($reference));
        if (!$access instanceof SupportAccess) {
            throw new NotFoundHttpException();
        }

        $maintenant = new \DateTimeImmutable();

        if (null === $access->getRevokedAt()) {
            $this->guard->revoke($access, $maintenant, $this->auteur());
        }

        return EditorSupportAccessProvider::fiche($access, $maintenant);
    }

    private function auteur(): ?string
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;
    }
}
