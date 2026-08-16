<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Padel\Entity\EvenementEclairage;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Entity\TerrainPadel;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Enum\StatutEvenementEclairage;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Repli manuel de l'éclairage en cas de défaut du relais (POST /padel/terrains/{id}/eclairage/repli-manuel,
 * RG-PADEL-05, CA-11). Motif requis, tracé (auteur, date — RG-SOCLE-07).
 * Corps : { "action": "allumage"|"extinction", "motif": string }.
 *
 * @implements ProcessorInterface<mixed, EvenementEclairage>
 */
final class ForcerEclairageManuelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvenementEclairage
    {
        \assert($data instanceof TerrainPadel);

        $relais = $this->em->getRepository(RelaisEclairageTerrain::class)->findOneBy(['terrain' => $data]);
        if ($relais === null) {
            throw new UnprocessableEntityHttpException('Ce terrain n\'est pas équipé d\'un relais d\'éclairage.');
        }

        $corps = $this->lecteur->corps();
        $action = ActionEclairage::tryFrom(\is_string($corps['action'] ?? null) ? $corps['action'] : '');
        if ($action === null) {
            throw new UnprocessableEntityHttpException('Champ « action » obligatoire (« allumage » ou « extinction »).');
        }
        $motif = \is_string($corps['motif'] ?? null) ? trim($corps['motif']) : '';
        if ($motif === '') {
            throw new UnprocessableEntityHttpException('Un motif est requis pour un repli manuel (RG-PADEL-05).');
        }

        $utilisateur = $this->security->getUser();

        $evenement = new EvenementEclairage();
        $evenement->setTerrain($data)
            ->setAction($action)
            ->setStatut(StatutEvenementEclairage::EchecRepliManuel)
            ->setOperateur($utilisateur instanceof Utilisateur ? $utilisateur : null)
            ->setMotif($motif);
        $this->em->persist($evenement);
        $this->em->flush();

        return $evenement;
    }
}
