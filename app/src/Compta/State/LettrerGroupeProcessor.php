<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\LettrageHandler;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/lettrages/groupe (US-L4-14, RG-M6-14, §2 du plan). Corps : `{ lines: [iri, ...] }`.
 *
 * Cloisonnement (même patron que `SaisirEcritureManuelleProcessor`, §0.5) : chaque ligne référencée
 * est résolue puis son `profilExploitant` (via `LigneEcriture->getEcriture()`) est **revérifié**
 * couvert par l'établissement actif -> 404 sinon (échec fermé, ne distingue jamais « n'existe pas » de
 * « hors périmètre »). Toutes les lignes doivent en outre appartenir au **même** profil exploitant
 * (422 sinon, IDOR inter-profils).
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class LettrerGroupeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly Security $security,
        private readonly LettrageHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $references = $corps['lines'] ?? $corps['lignes'] ?? null;
        if (!\is_array($references) || \count($references) < 2) {
            throw new UnprocessableEntityHttpException('Un lettrage groupé exige au moins 2 lignes (§4.4 spec).');
        }

        $etablissementActif = $this->contexte->etablissementActif();
        if ($etablissementActif === null) {
            throw new NotFoundHttpException('Établissement actif requis.');
        }

        $lignes = [];
        $profilAttendu = null;
        foreach ($references as $reference) {
            $id = $this->idDepuisReference($reference);
            $ligne = $id !== null ? $this->em->getRepository(LigneEcriture::class)->find(Uuid::fromString($id)) : null;
            $profil = $ligne?->getEcriture()?->getProfilExploitant();

            if ($ligne === null || $profil === null || !$profil->couvre($etablissementActif)) {
                throw new NotFoundHttpException('Ligne d\'écriture introuvable.');
            }

            if (!$this->memeProfil($profilAttendu, $profil)) {
                if ($profilAttendu !== null) {
                    throw new UnprocessableEntityHttpException('Toutes les lignes d\'un lettrage groupé doivent appartenir au même profil exploitant.');
                }
                $profilAttendu = $profil;
            }

            $lignes[] = $ligne;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur authentifié requis pour lettrer.');
        }

        $lettrages = $this->handler->lettrerGroupe($lignes, $utilisateur);

        $donnees = array_map(static fn ($lettrage) => [
            'id' => (string) $lettrage->getId(),
            'ligne' => '/api/ligne_ecritures/' . $lettrage->getLigne()?->getId(),
            'dateLettrage' => $lettrage->getDateLettrage()->format('Y-m-d'),
            'reconciliationCode' => $lettrage->getReconciliationCode(),
        ], $lettrages);

        return new JsonResponse($donnees, 201);
    }

    private function memeProfil(?ProfilExploitant $attendu, ProfilExploitant $candidat): bool
    {
        return $attendu !== null && $attendu->getId()->equals($candidat->getId());
    }

    private function idDepuisReference(mixed $reference): ?string
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $id = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($id) ? $id : null;
    }
}
