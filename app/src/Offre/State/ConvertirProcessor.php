<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\ConversionType;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeProduit;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\MappingConversion;
use App\Offre\Service\PublicationGuard;
use App\Offre\Service\ResolveurFacettes;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Conversion de type assistée (RG-M1-11 / CA-13). Corps attendu :
 *   { "nouveauType": "<uuid|iri>", "confirmer": true|false }
 * Sans confirmation, renvoie le mapping (conservés/ajoutés/abandonnés) et les données perdues,
 * avec confirmationRequise=true (aucune modification). Avec confirmer=true, applique la conversion,
 * purge les facettes incompatibles et journalise l'opération (ConversionType, append-only).
 * Seuls les types compatibles (TypeProduit.typesCompatibles) sont acceptés.
 *
 * @implements ProcessorInterface<Produit, Produit|JsonResponse>
 */
final class ConvertirProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MappingConversion $mapping,
        private readonly ResolveurFacettes $facettes,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly PublicationGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Produit);

        $corps = $this->corps();
        $nouveau = $this->resoudreType($corps['nouveauType'] ?? null);
        $ancien = $data->getType();

        if ($ancien === null) {
            throw new UnprocessableEntityHttpException('Le produit n\'a pas de type source.');
        }
        if (!$ancien->estCompatibleAvec($nouveau)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Conversion non autorisée : le type « %s » n\'est pas compatible avec « %s ».',
                $ancien->getCode(),
                $nouveau->getCode(),
            ));
        }

        $mapping = $this->mapping->calculer($ancien, $nouveau);
        $perdues = $this->mapping->donneesPerdues($data, $nouveau);

        // Sans confirmation explicite : aperçu du mapping, aucune modification (CA-13).
        if (($corps['confirmer'] ?? false) !== true) {
            return new JsonResponse([
                'confirmationRequise' => true,
                'ancienType' => $ancien->getCode(),
                'nouveauType' => $nouveau->getCode(),
                'mapping' => $mapping,
                'donneesPerdues' => $perdues,
            ], JsonResponse::HTTP_OK);
        }

        // ⚠ UN PRODUIT EN VENTE NE SE CONVERTIT PAS EN PRODUIT INVENDABLE (lot des garde-fous, 08/10).
        // Convertir une entrée publiée en carte la laissait publiée sans carte : le défaut même que la
        // garde de publication refuse. Seuls comptent les manques que la conversion CRÉE : un défaut
        // antérieur n'interdit pas de convertir. On s'arrête avant le `flush()` : rien n'est écrit.
        $enVente = $data->getStatut() === StatutProduit::Publie;
        $avant = $enVente ? $this->guard->missing($data) : [];

        // Application de la conversion.
        $data->setType($nouveau);
        $this->facettes->purgerOrphelins($data);

        $manquants = $enVente ? array_diff_key($this->guard->missing($data), $avant) : [];
        if ($manquants !== []) {
            throw new UnprocessableEntityHttpException(
                'Conversion impossible : ce produit est en vente, et il ne serait plus vendable. '
                .implode(' ', $manquants).' Dépubliez-le, convertissez-le, complétez-le, puis republiez-le.'
            );
        }
        $data->toucherModifieLe();

        $journal = (new ConversionType())
            ->setProduit($data)
            ->setAncienType($ancien)
            ->setNouveauType($nouveau)
            ->setAuteur($this->auteurCourant())
            ->setMapping(['conserves' => $mapping['conserves'], 'ajoutes' => $mapping['ajoutes'], 'abandonnes' => $mapping['abandonnes'], 'donneesPerdues' => $perdues]);
        $this->em->persist($journal);

        $this->em->flush();

        return $data;
    }

    /** @return array<string, mixed> */
    private function corps(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        $contenu = $request->getContent();
        if ($contenu === '') {
            return [];
        }
        $decode = json_decode($contenu, true);

        return \is_array($decode) ? $decode : [];
    }

    private function resoudreType(mixed $reference): TypeProduit
    {
        if (!\is_string($reference) || $reference === '') {
            throw new UnprocessableEntityHttpException('Le nouveau type (nouveauType) est obligatoire.');
        }

        // Accepte un UUID brut ou une IRI (/api/type_produits/{id}).
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        if (!Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('nouveauType invalide (UUID ou IRI attendu).');
        }

        $type = $this->em->getRepository(TypeProduit::class)->find(Uuid::fromString($segment));
        if ($type === null) {
            throw new UnprocessableEntityHttpException('Type cible introuvable.');
        }

        return $type;
    }

    private function auteurCourant(): ?string
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur instanceof Utilisateur ? $utilisateur->getEmail() : null;
    }
}
