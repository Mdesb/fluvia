<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Offre\Entity\Produit;
use App\Offre\Entity\TypeTarif;
use App\Offre\Enum\Canal;
use App\Vente\Dto\PriceQuote;
use App\Vente\Service\PriceQuoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;
use App\Securite\Service\ContexteEtablissement;

/**
 * `GET /produits/{id}/tarif` — **quel prix, et pourquoi celui-là**, avant que la vente existe.
 *
 * Le besoin vient de `claude-H` : tant qu'aucune vente n'a été ouverte, la caisse ne peut
 * qu'**estimer**, et elle estimait mal — elle retenait la première grille vendable du produit, quand
 * le serveur applique le tarif réellement dû. Maxime a vu le résultat : « 1 × Test 10,00 € » pour un
 * total de 15,00 €.
 *
 * **Ce fournisseur ne recalcule rien.** Il résout les paramètres et appelle `PriceQuoter`, qui est le
 * même service que celui appelé pour composer une ligne de vente. Une seconde implémentation aurait
 * reproduit le défaut dans une couche où **plus personne ne verrait la divergence**.
 *
 * **Il ne prend pas de `beneficiaire`, alors que la demande le mentionnait.** Le bénéficiaire n'entre
 * dans aucune règle de tarif du dépôt : c'est le **quotient familial** qui compte, et il est fourni
 * par l'appelant — exactement comme à la création d'une ligne, où `AjoutLigneHandler` lit `qf` dans le
 * corps de la requête. Accepter un paramètre pour l'ignorer aurait laissé croire à un prix
 * contextualisé qu'il n'est pas.
 *
 * @implements ProviderInterface<PriceQuote>
 */
final class PriceQuoteProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $item,
        private readonly EntityManagerInterface $em,
        private readonly PriceQuoter $tarif,
        private readonly RequestStack $requetes,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PriceQuote
    {
        // Le produit passe par le fournisseur Doctrine standard : il hérite ainsi du cloisonnement
        // porté par `PerimetreProduitExtension`, au lieu d'un `find()` qui l'aurait contourné.
        $produit = $this->item->provide($operation, $uriVariables, $context);
        if (!$produit instanceof Produit) {
            throw new NotFoundHttpException('Produit introuvable.');
        }

        $requete = $this->requetes->getCurrentRequest();
        $typeTarif = $this->typeTarif($requete?->query->get('typeTarif'));

        $canal = Canal::tryFrom((string) ($requete?->query->get('canal') ?? Canal::Guichet->value));
        if ($canal === null) {
            throw new UnprocessableEntityHttpException('Canal inconnu.');
        }

        $qf = $requete?->query->get('qf');

        // Les options retenues arrivent en `options[]=uuid&options[]=uuid`, et le TOTAL est rendu par
        // le serveur. Ce n est pas parce que l addition serait difficile — c est parce qu elle ne
        // restera pas une addition : un plafond sur le cumul, une remise « pack », une option qui en
        // rend une autre gratuite, et une somme faite cote navigateur devient fausse **en continuant
        // de rendre un nombre plausible**. L argument est de `claude-H`, il est meilleur que le mien.
        $options = $requete?->query->all('options') ?? [];
        $retenues = [];
        foreach ($options as $option) {
            if (\is_string($option) && $option !== '') {
                $nu = str_contains($option, '/') ? (string) substr($option, (int) strrpos($option, '/') + 1) : $option;
                if (Uuid::isValid($nu)) {
                    $retenues[] = $nu;
                }
            }
        }

        $quantite = (int) ($requete?->query->get('quantite') ?? 1);

        return $this->tarif->quote(
            $produit,
            $typeTarif,
            $this->date($requete?->query->get('date')),
            $canal,
            $qf !== null && $qf !== '' ? (float) $qf : null,
            $this->contexte->etablissementActif(),
            $retenues,
            $quantite,
        );
    }

    private function typeTarif(mixed $reference): TypeTarif
    {
        $brut = \is_string($reference) ? trim($reference) : '';
        // Accepte l'IRI autant que l'identifiant nu : la caisse envoie l'un ou l'autre selon l'écran,
        // et refuser l'IRI aurait obligé `claude-H` à le découper — donc à connaître notre routage.
        $brut = str_contains($brut, '/') ? (string) substr($brut, (int) strrpos($brut, '/') + 1) : $brut;

        if ($brut === '' || !Uuid::isValid($brut)) {
            throw new UnprocessableEntityHttpException('Paramètre « typeTarif » obligatoire (identifiant ou IRI).');
        }

        $typeTarif = $this->em->getRepository(TypeTarif::class)->find(Uuid::fromString($brut));
        if (!$typeTarif instanceof TypeTarif) {
            throw new NotFoundHttpException('Type de tarif introuvable.');
        }

        return $typeTarif;
    }

    private function date(mixed $brut): \DateTimeImmutable
    {
        if (!\is_string($brut) || trim($brut) === '') {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($brut);
        } catch (\Exception) {
            // Refuser plutôt qu'interpréter : une date illisible tombée sur « maintenant » rendrait un
            // prix juste pour aujourd'hui à qui demandait celui d'une autre saison.
            throw new UnprocessableEntityHttpException(sprintf('Date illisible : « %s ».', $brut));
        }
    }
}
