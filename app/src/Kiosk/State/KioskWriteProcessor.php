<?php

declare(strict_types=1);

namespace App\Kiosk\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\Caisse;
use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Service\KioskSecrets;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\CounterSellability;
use App\Vente\Service\DirectSalePoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Création et modification d'une borne (back-office, `kiosk.manage`).
 *
 * - D41 : l'établissement vient de la session serveur, jamais du corps.
 * - Le POINT DE VENTE doit appartenir à cet établissement — l'IRI est résolue avant ce processeur,
 *   et un point de vente d'un autre établissement est refusé ici comme s'il n'existait pas. Il ne doit
 *   être ni celui de la vente directe (sa chaîne est celle des ventes sans comptoir, D44-bis), ni celui
 *   d'une autre borne (index unique, rendu ici en 409 lisible).
 * - Les PRODUITS proposés doivent exister et être au catalogue de ce site (`CounterSellability`, D92) ;
 *   un identifiant hors site est refusé avec le même message qu'un identifiant inconnu (D3).
 * - Le CODE DE SORTIE arrive en clair et repart haché ; il n'est jamais relu.
 *
 * @implements ProcessorInterface<Kiosk, Kiosk>
 */
final class KioskWriteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
        private readonly EntityManagerInterface $em,
        private readonly CounterSellability $sellability,
        private readonly KioskSecrets $secrets,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Kiosk);

        if ($data->getEtablissement() === null) {
            $etablissement = $this->contexte->etablissementActif()
                ?? throw new UnprocessableEntityHttpException('Aucun établissement actif : impossible de rattacher cette borne (D41).');
            $data->setEtablissement($etablissement);
        }
        $etablissement = $data->getEtablissement();

        $pdv = $data->getPointOfSale();
        if ($pdv === null) {
            throw new UnprocessableEntityHttpException('Choisissez le point de vente de la borne : ses ventes, son terminal de paiement et son imprimante sont les siens.');
        }
        if ((string) $pdv->getEtablissement()?->getId() !== (string) $etablissement->getId()) {
            throw new UnprocessableEntityHttpException('Point de vente introuvable.');
        }
        if ($pdv->getLibelle() === DirectSalePoint::LABEL) {
            throw new UnprocessableEntityHttpException('Le point de vente « Vente directe » ne peut pas porter une borne : créez un point de vente dédié à la borne.');
        }
        // Le point de vente d'un GUICHET (il porte une caisse) : ses ventes de borne entreraient dans la
        // chaîne NF525 du guichet, et ses clôtures mêleraient deux comptoirs. Une borne a le sien.
        if ($this->em->getRepository(Caisse::class)->findOneBy(['pointDeVente' => $pdv]) instanceof Caisse) {
            throw new UnprocessableEntityHttpException('Ce point de vente est celui d\'un guichet (il porte une caisse) : créez un point de vente dédié à la borne.');
        }
        $other = $this->em->getRepository(Kiosk::class)->findOneBy(['pointOfSale' => $pdv]);
        if ($other instanceof Kiosk && (string) $other->getId() !== (string) $data->getId()) {
            throw new ConflictHttpException(sprintf('Ce point de vente porte déjà la borne « %s » : une borne a son propre point de vente.', $other->getLabel()));
        }

        foreach ($data->getProductIds() as $productId) {
            $product = Uuid::isValid($productId) ? $this->em->getRepository(Produit::class)->find(Uuid::fromString($productId)) : null;
            if (!$product instanceof Produit || !$this->sellability->isSoldAtSite($product, $etablissement)) {
                throw new UnprocessableEntityHttpException(sprintf('Produit introuvable : %s.', $productId));
            }
        }

        $exitCode = $data->getExitCode();
        if ($exitCode !== null && $exitCode !== '') {
            if (preg_match('/^\d{4,8}$/', $exitCode) !== 1) {
                throw new UnprocessableEntityHttpException('Le code de sortie compte de 4 à 8 chiffres.');
            }
            $data->setExitCodeHash($this->secrets->hashExitCode($exitCode));
            $data->setExitCode(null);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
