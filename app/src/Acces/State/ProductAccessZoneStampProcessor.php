<?php

declare(strict_types=1);

namespace App\Acces\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Entity\ProductAccessZone;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Estampille l'établissement d'une déclaration « ce produit ouvre cette zone » (D41).
 *
 * ── DEUX INVARIANTS, ET LE SECOND EST CELUI QU'ON OUBLIE ────────────────────────────────────────
 *
 * **L'établissement ne vient jamais du corps.** Il est absent du groupe d'écriture et posé ici depuis
 * la session serveur. Sans cela, un appelant déclarerait des zones chez le voisin : la lecture serait
 * cloisonnée pendant que l'écriture désignerait sa propre frontière.
 *
 * **La zone doit appartenir au même établissement.** Le champ `space` EST dans le groupe d'écriture,
 * donc son IRI vient du client. `PerimetreAccesExtension` ne s'applique qu'aux collections servies
 * par le fournisseur standard ; la désérialisation d'une relation, elle, passe par un `find()` que
 * rien ne borne. Sans ce contrôle on estampillerait la déclaration à SON établissement tout en
 * pointant la zone d'un AUTRE — une ligne qui traverse la frontière sans qu'aucune requête ne le
 * montre.
 *
 * ── POURQUOI 404 ET NON 403 ─────────────────────────────────────────────────────────────────────
 *
 * Un 403 dirait « cette zone existe, mais pas chez toi ». Répété sur des identifiants tirés au sort,
 * il transforme la route en oracle d'énumération des zones du voisin (D6). Le 404 ne distingue pas
 * « n'existe pas » de « ne t'appartient pas », et c'est précisément ce qu'on veut.
 *
 * @implements ProcessorInterface<ProductAccessZone, ProductAccessZone>
 */
final class ProductAccessZoneStampProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<ProductAccessZone, ProductAccessZone> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof ProductAccessZone);

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        // Comparaison sur la représentation textuelle : `getId()` rend des objets `Uuid`, qu'une
        // comparaison stricte d'objets distinguerait à tort. Une zone sans établissement échoue
        // aussi — fermeture par défaut.
        if ((string) $data->getSpace()->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            throw new NotFoundHttpException('Zone introuvable.');
        }

        $data->setEstablishment($etablissement);

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
