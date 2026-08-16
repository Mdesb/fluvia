<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Padel\Entity\HistoriqueNiveauJoueur;
use App\Padel\Entity\NiveauJoueur;
use App\Padel\Enum\StatutNiveauJoueur;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Valide (ou ajuste) un niveau de jeu (POST /padel/niveaux/{id}/valider, US-PADEL-04, CA-5). Trace
 * l'historique append-only (auteur, date, ancienne/nouvelle valeur — RG-SOCLE-07).
 *
 * Corps : { "niveau"?: int } (si absent, valide la valeur `proposée` telle quelle).
 *
 * @implements ProcessorInterface<mixed, NiveauJoueur>
 */
final class ValiderNiveauProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NiveauJoueur
    {
        \assert($data instanceof NiveauJoueur);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur authentifié requis pour valider un niveau.');
        }

        $corps = $this->lecteur->corps();
        $ancienneValeur = $data->getNiveau();
        $nouvelleValeur = isset($corps['niveau']) ? (int) $corps['niveau'] : $ancienneValeur;

        if ($nouvelleValeur !== $ancienneValeur) {
            $historique = new HistoriqueNiveauJoueur();
            $historique->setAncienneValeur($ancienneValeur)
                ->setNouvelleValeur($nouvelleValeur)
                ->setAuteur($utilisateur);
            $data->addHistorique($historique);
            $this->em->persist($historique);
        }

        $data->setNiveau($nouvelleValeur)
            ->setStatut(StatutNiveauJoueur::Valide)
            ->setValideParUtilisateur($utilisateur)
            ->setDateValidation(new \DateTimeImmutable());

        $this->em->flush();

        return $data;
    }
}
