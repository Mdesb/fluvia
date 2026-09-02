<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Service\MoteurRecouvrementHandler;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Recouvrement\AbonnementFitnessRedevablePort;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/echeances/{id}/simuler-rejet — enregistre un retour banque de rejet (US-SPORT-05, CA-5,
 * CA-7). En production, ce fait générateur proviendrait de `CollecteurSepaInterface::relerverRetours()`
 * (aucune remise bancaire réelle dans ce lot) ; exposé ici comme point d'entrée opérationnel/testable
 * équivalent (acteur « Système »). Délègue au moteur de recouvrement partagé
 * (`App\Recouvrement\Service\MoteurRecouvrementHandler`, refactor extraction) — Sport ne fait plus que
 * router son échéance propre vers le moteur générique. Corps :
 *   { "codeRetour": string, "libelleRetour"?: string, "dateRejet"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<EcheanceSepa, IncidentImpaye>
 */
final class SimulerRejetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly MoteurRecouvrementHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IncidentImpaye
    {
        // ── ⚠ `assert()` ICI RENDAIT UN 500, ET LE CAS N'EST PAS THEORIQUE ────────────────────
        //
        // Quand l'echeance visee est hors du perimetre de l'etablissement actif, le fournisseur
        // d'API Platform rend `null` — le cloisonnement fait son travail — et ce `null` arrive
        // jusqu'ici. Un `assert()` le transforme en `AssertionError`, donc en **500 « Internal
        // Server Error »** : le logiciel s'accuse lui-meme d'etre casse alors qu'il vient
        // precisement de proteger la donnee d'un autre exploitant.
        //
        // Mesure du 02/09 : decouvert en ecrivant le test de cloisonnement de l'annulation, qui
        // attendait 403 ou 404 et a lu 500.
        //
        // 404 est aussi la bonne reponse du point de vue de la confidentialite : « introuvable »
        // ne dit pas si la ressource existe ailleurs, la ou « interdit » l'avouerait.
        if (!$data instanceof EcheanceSepa) {
            throw new NotFoundHttpException('Echeance introuvable dans le perimetre de l etablissement actif.');
        }

        $corps = $this->lecteur->corps();
        $code = \is_string($corps['codeRetour'] ?? null) ? $corps['codeRetour'] : '';
        if (trim($code) === '') {
            throw new UnprocessableEntityHttpException('« codeRetour » est requis (code retour SEPA, ex. AM04).');
        }
        $libelle = isset($corps['libelleRetour']) && \is_string($corps['libelleRetour']) ? $corps['libelleRetour'] : null;
        $dateRejet = isset($corps['dateRejet']) && \is_string($corps['dateRejet'])
            ? new \DateTimeImmutable($corps['dateRejet'])
            : new \DateTimeImmutable('today');

        $abonnement = $data->getAbonnement();
        $etablissement = $abonnement?->getEtablissement();
        if ($abonnement === null || $etablissement === null) {
            throw new UnprocessableEntityHttpException('Échéance sans abonnement/établissement rattaché.');
        }

        $data->setStatut(StatutEcheanceSepa::Rejetee);
        $this->em->flush();

        return $this->handler->detecterRejet(
            $etablissement,
            AbonnementFitnessRedevablePort::TYPE,
            (string) $abonnement->getId(),
            $data->getMontantCentimes(),
            $dateRejet,
            $code,
            $libelle,
            (string) $data->getId(),
        );
    }
}
