<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Enum\CourtSport;
use App\Padel\Enum\CourtSurface;
use App\Padel\Enum\TypeTerrain;
use App\Reservation\Entity\Ressource;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Crée un terrain padel : cascade la `Ressource` socle (`codeType='terrain_padel'`) puis l'overlay
 * `TerrainPadel` (décision structurante n°1 du plan). Corps :
 *   { "libelle": string, "type": "indoor"|"outdoor", "dureesAutoriseesMinutes"?: [60,90] }
 *
 * @implements ProcessorInterface<mixed, TerrainPadel>
 */
final class CreerTerrainProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly LecteurCorps $lecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TerrainPadel
    {
        $corps = $this->lecteur->corps();

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $libelle = \is_string($corps['libelle'] ?? null) && $corps['libelle'] !== '' ? $corps['libelle'] : 'Terrain padel';
        $type = TypeTerrain::tryFrom(\is_string($corps['type'] ?? null) ? $corps['type'] : '') ?? TypeTerrain::Indoor;
        // ⚠ LE SPORT ET LA SURFACE SE LISENT ICI, ET PAS SEULEMENT DANS `terrain:write`. Ce
        // processeur reconstruit le terrain a la main : un champ absent de ces lignes est accepte
        // par l'API et n'arrive jamais en base. Le `Patch`, lui, est standard et les ecrit — d'ou
        // un defaut qui ne se voit qu'a la creation.
        //
        // ⚠ CE `??` COUVRE LE SPORT ABSENT, PAS LE SPORT INCONNU — et la nuance a ete mesuree.
        // `DeserializeProvider` tourne AVANT ce processeur : `sport` etant dans `terrain:write`
        // avec un `enumType`, une valeur inconnue est refusee en 400 par le serialiseur et on
        // n'arrive jamais ici. Un 400 qui nomme les valeurs admises vaut mieux qu'un terrain de
        // tennis silencieusement transforme en terrain de padel.
        //
        // Ce qui arrive ici, c'est le corps qui NE DIT RIEN du sport : alors padel, parce qu'un
        // terrain cree depuis le module padel en est un.
        $sport = CourtSport::tryFrom(\is_string($corps['sport'] ?? null) ? $corps['sport'] : '') ?? CourtSport::Padel;
        // ⚠ ET LA SURFACE RESTE `null` QUAND ELLE N'EST PAS DITE : elle n'a pas de valeur par
        // defaut legitime. « On ne sait pas » est une reponse, « resine » serait une invention.
        $surface = CourtSurface::tryFrom(\is_string($corps['surface'] ?? null) ? $corps['surface'] : '');
        $durees = isset($corps['dureesAutoriseesMinutes']) && \is_array($corps['dureesAutoriseesMinutes'])
            ? array_map('intval', $corps['dureesAutoriseesMinutes'])
            : [60, 90];

        $ressource = new Ressource();
        $ressource->setEtablissement($etablissement)
            ->setCodeType('terrain_padel')
            ->setLibelle($libelle)
            ->setCapacitePropre(4)
            ->setOuvreAcces(true);
        $this->em->persist($ressource);

        $terrain = new TerrainPadel();
        $terrain->setRessource($ressource)
            ->setType($type)
            ->setSport($sport)
            ->setSurface($surface)
            ->setDureesAutoriseesMinutes($durees);
        $this->em->persist($terrain);
        $this->em->flush();

        return $terrain;
    }
}
