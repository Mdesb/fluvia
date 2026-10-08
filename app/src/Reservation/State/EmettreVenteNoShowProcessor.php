<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caisse\Entity\SessionCaisse;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Facturation\ResolveurStrategieFacturation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Émission différée d'une vente/avoir M2 pour une `FacturationNoShow` (CA-11, `reservation.facturer`) :
 * délègue à la stratégie résolue depuis `RegleAnnulation.modeFacturation` (décision structurante n°4
 * du plan). Pour `vente_differee_agent`, une `session` de caisse ouverte est requise dans le corps.
 *
 * @implements ProcessorInterface<mixed, FacturationNoShow>
 */
final class EmettreVenteNoShowProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly ResolveurStrategieFacturation $strategies,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FacturationNoShow
    {
        \assert($data instanceof FacturationNoShow);

        if ($data->getStatut() !== StatutFacturationNoShow::AFacturer) {
            throw new ConflictHttpException('Cette facturation no-show n\'est plus « à facturer » (statut : ' . $data->getStatut()->value . ').');
        }

        $regle = $data->getRegleAppliquee();
        if ($regle === null) {
            throw new UnprocessableEntityHttpException('Aucune règle d\'annulation rattachée à cette facturation.');
        }

        $strategie = $this->strategies->pour($regle->getModeFacturation()->value);
        if ($strategie === null) {
            throw new UnprocessableEntityHttpException(sprintf('Aucune stratégie de facturation enregistrée pour le mode « %s ».', $regle->getModeFacturation()->value));
        }

        $corps = $this->lecteur->corps();
        $contexte = [];
        if (isset($corps['session']) && \is_string($corps['session'])) {
            $segment = str_contains($corps['session'], '/') ? basename($corps['session']) : $corps['session'];
            if (Uuid::isValid($segment)) {
                $session = $this->em->getRepository(SessionCaisse::class)->find(Uuid::fromString($segment));

                // D8 — « session » est un identifiant fourni par le client : il n'est jamais
                // l'autorisation. `read: true` protege bien la `FacturationNoShow`, mais la session de
                // caisse n'en depend pas et echappait donc a tout controle. La suite du traitement
                // verifie que la session existe et qu'elle est ouverte (RG-M2-01), jamais a qui elle
                // appartient : un agent portant `reservation.facturer` sur A, connaissant l'UUID d'une
                // session ouverte de B, encaissait dans la caisse de B.
                //
                // Cinquieme IDOR de la meme famille — une entite resolue depuis le corps de la requete
                // et jamais confrontee au perimetre. Trouve par claude-C en auditant la ligne de base
                // du garde-fou.
                //
                // Echec ferme en 404 et non 403 : un 403 confirmerait l'existence de la session
                // ailleurs et ferait de la route un oracle d'enumeration. Une session sans
                // etablissement echoue aussi — fermeture par defaut.
                $actif = $this->contexte->etablissementActif();
                if ($session instanceof SessionCaisse
                    && (string) $session->getEtablissement()?->getId() !== (string) $actif?->getId()) {
                    throw new NotFoundHttpException('Session de caisse introuvable.');
                }

                $contexte['session'] = $session;
            }
        }

        $utilisateur = $this->security->getUser();
        $agent = $utilisateur instanceof Utilisateur ? $utilisateur : null;

        $resultat = $strategie->appliquer($data, $agent, $contexte);
        if ($resultat->conflict) {
            throw new ConflictHttpException($resultat->motif ?? 'Cette facturation no-show n\'est plus « à facturer ».');
        }
        if (!$resultat->succes) {
            throw new UnprocessableEntityHttpException($resultat->motif ?? 'Émission de la vente no-show impossible.');
        }

        return $data;
    }
}
