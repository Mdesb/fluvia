<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupBookingItem;
use App\Group\Entity\GroupProduct;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Applique un forfait groupe à une réservation (POST /group/bookings/{id}/apply-product).
 * Corps : { "groupProduct": iri|uuid }. Recopie **chaque ligne** du forfait en article du panier de la
 * réservation (`GroupBookingItem`, `source` = le forfait). On copie plutôt qu'on ne référence : le
 * panier reste éditable ensuite, et le devis ne dérive pas si le forfait change après coup.
 *
 * La réservation est déjà cloisonnée (chargée via l'extension de périmètre) ; le forfait est vérifié
 * appartenir au même établissement actif (RG-SOCLE-05).
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class ApplyGroupProductProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        $actif = $this->contexte->etablissementActif();
        if ($actif === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $corps = $this->lecteur->corps();
        $reference = $corps['groupProduct'] ?? null;
        $segment = \is_string($reference) && str_contains($reference, '/') ? basename($reference) : $reference;
        if (!\is_string($segment) || !Uuid::isValid($segment)) {
            throw new UnprocessableEntityHttpException('Référence « groupProduct » obligatoire (UUID ou IRI).');
        }

        $forfait = $this->em->getRepository(GroupProduct::class)->find(Uuid::fromString($segment));
        if (!$forfait instanceof GroupProduct) {
            throw new NotFoundHttpException('Forfait introuvable.');
        }
        $etabForfait = $forfait->getEtablissement();
        if ($etabForfait === null || !$etabForfait->getId()->equals($actif->getId())) {
            throw new NotFoundHttpException('Forfait introuvable dans l\'établissement actif.');
        }

        foreach ($forfait->getLines() as $ligne) {
            $item = (new GroupBookingItem())
                ->setBooking($data)
                ->setProduit($ligne->getProduit())
                ->setQuantite($ligne->getQuantite())
                ->setPrixUnitaireHT($ligne->getPrixUnitaireHT())
                ->setTauxTva($ligne->getTauxTva())
                ->setSource($forfait);
            $this->em->persist($item);
        }

        $this->em->flush();

        return $data;
    }
}
