<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Enum\DocumentStatus;
use App\Facturation\Exception\ForbiddenDocumentTransitionException;
use App\Facturation\Service\DocumentChain;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Les gestes d'une pièce commerciale : émettre, accepter, refuser, dériver, facturer (FAC-1).
 *
 * **Un processeur pour cinq gestes, distingués par leur route.** Ils partagent le même contrôle
 * d'accès, la même résolution et le même traitement d'erreur ; cinq classes jumelles divergeraient au
 * premier correctif appliqué à une seule.
 *
 * **Les refus métier deviennent des 422, jamais des 500.** `ForbiddenDocumentTransitionException`
 * porte un message écrit pour l'exploitant — « ce devis est déjà converti », « seule une pièce
 * acceptée se facture ». Le laisser remonter en erreur serveur transformerait une information utile
 * en incident.
 *
 * **Le cloisonnement n'est pas rejoué ici.** `PerimetreFacturationExtension` restreint déjà la
 * résolution aux établissements où l'utilisateur a une affectation : une pièce d'un autre
 * établissement est introuvable avant d'arriver dans ce processeur. Redoubler le contrôle
 * produirait deux règles qui divergent, et on ne saurait plus laquelle fait foi.
 *
 * @implements ProcessorInterface<CommercialDocument, CommercialDocument>
 */
final class DocumentActionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentChain $chaine,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommercialDocument
    {
        \assert($data instanceof CommercialDocument);

        $auteur = $this->security->getUser();
        if (!$auteur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Une pièce commerciale porte le nom de qui l\'a traitée.');
        }

        $maintenant = new \DateTimeImmutable();
        $geste = $this->geste($operation);

        try {
            return match ($geste) {
                'issue' => $this->transiter($data, DocumentStatus::Issued, $maintenant),
                'accept' => $this->transiter($data, DocumentStatus::Accepted, $maintenant),
                'reject' => $this->transiter($data, DocumentStatus::Rejected, $maintenant),
                'derive' => $this->chaine->deriver($data, $auteur, $maintenant),
                'invoice' => $this->facturer($data, $auteur, $maintenant),
                default => throw new UnprocessableEntityHttpException('Geste inconnu.'),
            };
        } catch (ForbiddenDocumentTransitionException $refus) {
            // Message écrit pour l'exploitant : il dit où en est la pièce et pourquoi le geste
            // n'est pas possible. Voir le commentaire de classe.
            throw new UnprocessableEntityHttpException($refus->getMessage(), $refus);
        }
    }

    private function transiter(CommercialDocument $document, DocumentStatus $cible, \DateTimeImmutable $quand): CommercialDocument
    {
        $document->transitionVers($cible, $quand);
        $this->em->flush();

        return $document;
    }

    /**
     * Facture la pièce et rend la pièce, pas la facture.
     *
     * L'appelant a demandé une action sur un document : il doit recevoir ce document, avec son
     * nouvel état et sa référence de facture. Lui rendre la facture l'obligerait à recharger la
     * pièce pour savoir ce qu'elle est devenue.
     */
    private function facturer(CommercialDocument $document, Utilisateur $auteur, \DateTimeImmutable $quand): CommercialDocument
    {
        $this->chaine->facturer($document, $auteur, $quand);

        return $document;
    }

    private function geste(Operation $operation): string
    {
        $chemin = (string) $operation->getUriTemplate();

        foreach (['issue', 'accept', 'reject', 'derive', 'invoice'] as $geste) {
            if (str_ends_with($chemin, '/'.$geste)) {
                return $geste;
            }
        }

        return '';
    }
}
