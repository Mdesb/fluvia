<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\RejetSepa;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sepa/rejets (plan §2/§4/§6) : saisie/simulation manuelle d'un retour SEPA en attendant le
 * retour bancaire réel (`RetourSepaInterface`, aucun parser pain.002 réel — §9 du plan). Corps :
 *   { "ligne": iri|uuid, "codeMotif": string, "libelleMotif"?: string, "dateRejet"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<mixed, RejetSepa>
 */
final class DeclarerRejetSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RejetSepa
    {
        $corps = $this->lecteur->corps();

        $ligne = $this->resoudreLigne($corps['ligne'] ?? null);
        if (!$ligne instanceof LigneRemiseSepa) {
            throw new UnprocessableEntityHttpException('« ligne » est requise et doit référencer une ligne de remise SEPA existante.');
        }

        $this->assertLigneDansLePerimetre($ligne);

        $codeMotif = \is_string($corps['codeMotif'] ?? null) ? trim($corps['codeMotif']) : '';
        if ($codeMotif === '') {
            throw new UnprocessableEntityHttpException('« codeMotif » est requis (code retour SEPA, ex. AM04).');
        }
        $libelle = isset($corps['libelleMotif']) && \is_string($corps['libelleMotif']) ? $corps['libelleMotif'] : null;
        $dateRejet = isset($corps['dateRejet']) && \is_string($corps['dateRejet'])
            ? new \DateTimeImmutable($corps['dateRejet'])
            : new \DateTimeImmutable('today');

        $mandat = $ligne->getMandat();

        $rejet = new RejetSepa();
        $rejet->setLigne($ligne)
            ->setEndToEndId($ligne->getEndToEndId())
            ->setMndtId($mandat?->getRum() ?? '')
            ->setCodeMotif($codeMotif)
            ->setLibelleMotif($libelle)
            ->setDateRejet($dateRejet);

        $this->em->persist($rejet);
        $this->em->flush();

        return $rejet;
    }


    /**
     * Cloisonnement du retour SEPA (D3, D8).
     *
     * La ligne de remise est résolue depuis un UUID fourni dans le corps de la requête : elle échappe
     * donc par construction aux extensions Doctrine, qui ne s'exécutent que sur les opérations de
     * **lecture** d'API Platform. L'autorité se recalcule ici contre l'établissement de la **remise
     * visée**, jamais contre l'en-tête `X-Etablissement` fourni par le client.
     *
     * Sans ce contrôle, un utilisateur habilité sur un établissement peut déclarer un rejet sur le
     * prélèvement d'un autre — un chemin argent, avec un effet comptable direct.
     */
    private function assertLigneDansLePerimetre(LigneRemiseSepa $ligne): void
    {
        $utilisateur = $this->security->getUser();
        $etablissement = $ligne->getRemise()?->getEtablissement();

        $codes = $utilisateur instanceof Utilisateur && $etablissement !== null
            ? $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId())
            : [];

        // Échec fermé, et 404 : distinguer « hors périmètre » de « inexistant » renseignerait déjà
        // l'appelant sur les remises d'un autre établissement.
        if (!$this->calculateur->autorise($codes, 'sepa', 'declarer_rejet')) {
            throw new NotFoundHttpException('Ligne de remise introuvable.');
        }
    }

    private function resoudreLigne(mixed $valeur): ?LigneRemiseSepa
    {
        if (!\is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        $id = str_contains($valeur, '/') ? substr($valeur, (int) strrpos($valeur, '/') + 1) : $valeur;
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(LigneRemiseSepa::class)->find($id);
    }
}
