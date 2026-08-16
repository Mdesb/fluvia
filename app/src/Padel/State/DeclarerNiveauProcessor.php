<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Padel\Entity\NiveauJoueur;
use App\Padel\Entity\ParametragePadel;
use App\Padel\Enum\StatutNiveauJoueur;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Déclare (ou met à jour) le niveau de jeu **proposé** d'un joueur (POST /padel/niveaux/declarer,
 * US-PADEL-04). Réservé à `padel.niveau_declarer_soi` : le joueur ne peut déclarer que pour lui-même.
 * Toute modification repasse le statut à `propose` (une re-déclaration invalide la validation
 * précédente, §4.4).
 *
 * Corps : { "joueur": iri|uuid, "niveau": int }
 *
 * @implements ProcessorInterface<mixed, NiveauJoueur>
 */
final class DeclarerNiveauProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): NiveauJoueur
    {
        $corps = $this->lecteur->corps();

        $joueur = $this->resoudre(Beneficiaire::class, $corps['joueur'] ?? null, 'joueur');
        \assert($joueur instanceof Beneficiaire);

        if (!$this->security->isGranted('PERM', 'padel.niveau_valider')) {
            $utilisateur = $this->security->getUser();
            $clientLie = $utilisateur instanceof Utilisateur ? $utilisateur->getClientLie() : null;
            $client = $joueur->getClient();
            if ($clientLie === null || $client === null || (string) $clientLie !== (string) $client->getId()) {
                throw new UnprocessableEntityHttpException('Un joueur ne peut déclarer un niveau que pour lui-même (padel.niveau_declarer_soi).');
            }
        }

        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $niveauValeur = isset($corps['niveau']) ? (int) $corps['niveau'] : null;
        if ($niveauValeur === null) {
            throw new UnprocessableEntityHttpException('Champ « niveau » obligatoire (entier).');
        }

        $parametrage = $this->em->getRepository(ParametragePadel::class)->findOneBy(['etablissement' => $etablissement]);
        $min = $parametrage?->getEchelleNiveauMin() ?? 1;
        $max = $parametrage?->getEchelleNiveauMax() ?? 10;
        if ($niveauValeur < $min || $niveauValeur > $max) {
            throw new UnprocessableEntityHttpException(sprintf('Niveau hors échelle établissement [%d, %d].', $min, $max));
        }

        $niveauJoueur = $this->em->getRepository(NiveauJoueur::class)->findOneBy(['joueur' => $joueur, 'etablissement' => $etablissement]);
        if ($niveauJoueur === null) {
            $niveauJoueur = new NiveauJoueur();
            $niveauJoueur->setJoueur($joueur)->setEtablissement($etablissement);
            $this->em->persist($niveauJoueur);
        }

        $niveauJoueur->setNiveau($niveauValeur)
            ->setStatut(StatutNiveauJoueur::Propose)
            ->setValideParUtilisateur(null)
            ->setDateValidation(null);

        $this->em->flush();

        return $niveauJoueur;
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
