<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Padel\Entity\InscriptionTournoi;
use App\Padel\Entity\Tournoi;
use App\Padel\Enum\StatutPaiementInscriptionTournoi;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Inscrit une paire à un tournoi (POST /padel/inscriptions-tournoi, US-PADEL-05). Les frais
 * d'inscription réutilisent M2 (référencé, non redéfini) — ⚠ **simplification documentée** de ce lot :
 * le paiement effectif de la vente M2 des frais est hors périmètre technique de cette implémentation
 * (le flag `paye` matérialise directement le statut, à raccorder à une `Vente` réelle lors de
 * l'intégration caisse complète, cf. rapport final).
 *
 * Corps : { "tournoi": iri|uuid, "joueur1": iri|uuid, "joueur2": iri|uuid, "paye"?: bool }
 *
 * @implements ProcessorInterface<mixed, InscriptionTournoi>
 */
final class InscrireTournoiProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InscriptionTournoi
    {
        $corps = $this->lecteur->corps();

        $tournoi = $this->resoudre(Tournoi::class, $corps['tournoi'] ?? null, 'tournoi');
        \assert($tournoi instanceof Tournoi);
        $joueur1 = $this->resoudre(Beneficiaire::class, $corps['joueur1'] ?? null, 'joueur1');
        \assert($joueur1 instanceof Beneficiaire);
        $joueur2 = $this->resoudre(Beneficiaire::class, $corps['joueur2'] ?? null, 'joueur2');
        \assert($joueur2 instanceof Beneficiaire);

        if (!$this->security->isGranted('PERM', 'padel.tournoi_gerer')) {
            $utilisateur = $this->security->getUser();
            $clientLie = $utilisateur instanceof Utilisateur ? $utilisateur->getClientLie() : null;
            $clients = array_filter([$joueur1->getClient(), $joueur2->getClient()]);
            $estLie = false;
            foreach ($clients as $client) {
                if ($clientLie !== null && (string) $clientLie === (string) $client->getId()) {
                    $estLie = true;
                }
            }
            if (!$estLie) {
                throw new UnprocessableEntityHttpException('Un joueur ne peut inscrire que sa propre paire (padel.tournoi_inscrire_soi).');
            }
        }

        $inscription = new InscriptionTournoi();
        $inscription->setTournoi($tournoi)
            ->setJoueur1($joueur1)
            ->setJoueur2($joueur2)
            ->setStatutPaiement(($corps['paye'] ?? false) === true ? StatutPaiementInscriptionTournoi::Paye : StatutPaiementInscriptionTournoi::EnAttente);

        $this->em->persist($inscription);
        $this->em->flush();

        return $inscription;
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
