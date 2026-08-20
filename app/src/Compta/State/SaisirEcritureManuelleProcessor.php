<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\SaisirEcritureManuelleHandler;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/journal-entries/manual (US-L4-11, RG-M6-11, §0.5/§1 du plan). Corps :
 * `{ businessProfile, journal, date, label, lines: [{ account, debit|credit, vatRate, counterparty? }] }`
 *
 * Cloisonnement (§0.5 — **patron de référence** à répliquer par FIN-2/FIN-3) :
 * 1. Résout `businessProfile` depuis le corps (référence IRI/UUID).
 * 2. Vérifie **explicitement** `$profil->couvre($etablissementActif)`, `$etablissementActif` résolu
 *    **côté serveur** via `ContexteEtablissement` (jamais depuis un champ du corps).
 * 3. Échec fermé : profil inexistant OU hors périmètre -> **404** uniforme (pas 403 : ne révèle pas
 *    l'existence d'un profil hors périmètre, cohérent invariant noyau commun #2).
 *
 * @implements ProcessorInterface<mixed, EcritureComptable>
 */
final class SaisirEcritureManuelleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContexteEtablissement $contexte,
        private readonly SaisirEcritureManuelleHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EcritureComptable
    {
        $corps = $this->lecteur->corps();
        $profil = $this->resoudreProfilCouvert($corps['businessProfile'] ?? null);

        return $this->handler->saisir($profil, $corps);
    }

    private function resoudreProfilCouvert(mixed $reference): ProfilExploitant
    {
        $id = $this->idDepuisReference($reference);
        $profil = $id !== null ? $this->em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($id)) : null;

        $etablissementActif = $this->contexte->etablissementActif();

        if ($profil === null || $etablissementActif === null || !$profil->couvre($etablissementActif)) {
            throw new NotFoundHttpException('Profil exploitant introuvable.');
        }

        return $profil;
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
