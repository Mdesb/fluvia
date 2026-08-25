<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /compta/ventes/{id}/marquer-impayee-regie (RG-M6-09, §7.4 du plan). Corps : { "motif": string }
 *
 * @implements ProcessorInterface<mixed, VenteImpayeeRegie>
 */
final class MarquerImpayeeRegieProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VenteImpayeeRegie
    {
        // Le paramètre d'URI {id} référence une Vente (M2), pas la ressource VenteImpayeeRegie ;
        // API Platform peut le livrer déjà typé (Uuid) ou en chaîne selon le contexte de résolution.
        $venteId = $uriVariables['id'] ?? null;
        $uuid = match (true) {
            $venteId instanceof Uuid => $venteId,
            \is_string($venteId) && Uuid::isValid($venteId) => Uuid::fromString($venteId),
            default => null,
        };
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException('Identifiant de vente invalide.');
        }

        // Cloisonnement (D3/D8) — l'{id} d'URI est fourni par le client. `compta.gerer` porté sur
        // l'établissement actif (en-tête X-Etablissement) ne prouve rien sur la vente visée : on charge
        // la Vente et on recalcule l'autorité contre SON établissement, jamais contre l'en-tête. Sans
        // ce contrôle, un agent autorisé sur A marquait « impayée régie » une vente de B (intégrité
        // comptable cross-établissement). Ce POST résout sa cible depuis l'URI : les extensions Doctrine
        // (lecture) ne s'y appliquent pas, le contrôle doit être explicite ici.
        $this->assertVenteDansLePerimetre($this->em->getRepository(Vente::class)->find($uuid));

        $existant = $this->em->getRepository(VenteImpayeeRegie::class)->findOneBy(['venteOrigine' => $uuid]);
        if ($existant !== null) {
            throw new ConflictHttpException('Cette vente est déjà marquée « ImpayeRegie ».');
        }

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : 'Recette de régie';

        $marquage = new VenteImpayeeRegie();
        $marquage->setVenteOrigine($uuid);
        $marquage->setMotif($motif);

        $this->em->persist($marquage);
        $this->em->flush();

        return $marquage;
    }

    /**
     * Échec fermé en 404 (jamais 403 : confirmer l'existence d'une vente hors périmètre renseignerait
     * l'appelant sur l'activité d'un autre établissement). Une vente absente échoue de la même façon
     * qu'une vente hors périmètre — indistinguables pour l'appelant.
     */
    private function assertVenteDansLePerimetre(?Vente $vente): void
    {
        $utilisateur = $this->security->getUser();
        $etablissement = $vente?->getEtablissement();

        $codes = $vente instanceof Vente && $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];

        if (!$this->calculateur->autorise($codes, 'compta', 'gerer')) {
            throw new NotFoundHttpException('Vente introuvable.');
        }
    }
}
