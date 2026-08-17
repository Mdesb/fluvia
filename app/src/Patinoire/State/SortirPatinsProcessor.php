<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\Caution;
use App\Caution\Service\GestionCaution;
use App\Crm\Entity\Beneficiaire;
use App\Patinoire\Entity\CautionLocationPatins;
use App\Patinoire\Entity\LocationPatins;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Enum\StatutCaution as StatutCautionLocation;
use App\Patinoire\Service\ProposeurPointureVoisineHandler;
use App\Vente\Entity\LigneVente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Sortie de patins au comptoir (POST /patinoire/locations, US-PATIN-02, RG-PAT-01, CA-2). Bloque un
 * article de la pointure demandée (compteur caché, plan §0 point 2), encaisse la caution (montant
 * paramétrable, ⚠ défaut « 15.00 » non chiffré par les sources — spec §4.2/§7). Si la pointure
 * demandée est en rupture (disponibilité nulle), refuse la sortie (409) en indiquant la pointure
 * voisine disponible si trouvée (bascule CA-5, `ProposeurPointureVoisineHandler`). La consignation
 * de la caution est déléguée à `App\Caution\Service\GestionCaution` (refactor caution générique) ;
 * `CautionLocationPatins` reste l'entité locale exposée par `/api/patinoire_caution_location_patins`
 * (contrat inchangé), miroir de la caution générique (cible `patinoire.patins`).
 *
 * @implements ProcessorInterface<mixed, LocationPatins>
 */
final class SortirPatinsProcessor implements ProcessorInterface
{
    public const TYPE_CIBLE = 'patinoire.patins';
    private const MONTANT_CAUTION_DEFAUT = '15.00';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ProposeurPointureVoisineHandler $proposeur,
        private readonly GestionCaution $gestionCaution,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LocationPatins
    {
        $corps = $this->lecteur->corps();

        $parcPatins = $this->resoudre(ParcPatins::class, $corps['parcPatins'] ?? null, 'parcPatins');
        \assert($parcPatins instanceof ParcPatins);

        $beneficiaire = $this->resoudre(Beneficiaire::class, $corps['beneficiaire'] ?? null, 'beneficiaire');
        \assert($beneficiaire instanceof Beneficiaire);

        if ($parcPatins->getQuantiteDisponible() <= 0) {
            $voisine = $this->proposeur->proposer($parcPatins);
            $message = $voisine !== null
                ? sprintf(
                    'RG-PAT-01/CA-5 : pointure %d indisponible. Pointure voisine proposée : %d. À défaut d\'acceptation, inscrire le client en liste d\'attente (POST /patinoire/liste-attente).',
                    $parcPatins->getPointure(),
                    $voisine,
                )
                : sprintf(
                    'RG-PAT-01/CA-5 : pointure %d indisponible, aucune pointure voisine disponible. Inscrire le client en liste d\'attente (POST /patinoire/liste-attente).',
                    $parcPatins->getPointure(),
                );
            throw new ConflictHttpException($message);
        }

        $ligneVente = null;
        if (isset($corps['ligneVente'])) {
            $ligneVente = $this->resoudre(LigneVente::class, $corps['ligneVente'], 'ligneVente');
            \assert($ligneVente instanceof LigneVente);
        }

        $location = new LocationPatins();
        $location->setParcPatins($parcPatins)
            ->setBeneficiaire($beneficiaire)
            ->setLigneVente($ligneVente);
        $parcPatins->incrementerSortie(1);
        $this->em->persist($location);

        $montant = isset($corps['caution']) ? (string) $corps['caution'] : self::MONTANT_CAUTION_DEFAUT;
        $moyenEncaissement = isset($corps['moyenEncaissement']) ? (string) $corps['moyenEncaissement'] : null;

        $etablissement = $location->getEtablissement();
        if ($etablissement !== null) {
            $this->gestionCaution->consigner($etablissement, self::TYPE_CIBLE, $location->getId(), Caution::decimalVersCentimes($montant), $moyenEncaissement);
        }

        $caution = new CautionLocationPatins();
        $caution->setLocation($location)
            ->setMontant($montant)
            ->setStatut(StatutCautionLocation::Encaissee)
            ->setMoyenEncaissement($moyenEncaissement)
            ->setDateEncaissement(new \DateTimeImmutable());
        $this->em->persist($caution);

        $this->em->flush();

        return $location;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
