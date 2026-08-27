<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Crm\Entity\Client;
use App\Marketing\Entity\Campaign;
use App\Marketing\Entity\CampaignRecipient;
use App\Marketing\Entity\MessageVariables;
use App\Marketing\Enum\CampaignStatus;
use App\Marketing\Enum\ExclusionReason;
use App\Marketing\Enum\RecipientOutcome;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationOutcome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'ENVOI — le seul endroit où une campagne rencontre des personnes.
 *
 * Tout ce que ce module promet se joue ici, dans cet ordre, et l'ordre n'est pas cosmétique :
 *
 *   1. **résoudre** l'audience — au moment de l'envoi, pas au moment de l'écriture ;
 *   2. **tirer le groupe témoin** — avant toute exclusion, sinon il ne représente plus rien ;
 *   3. **écarter** les trop sollicités, puis les messages qu'on ne sait pas remplir ;
 *   4. **notifier** — et c'est le port qui refuse les sans-consentement, pas nous ;
 *   5. **figer** la liste, exclus compris.
 *
 * ── POURQUOI LE CONSENTEMENT N'APPARAÎT PAS DANS CETTE CLASSE ───────────────────────────────────
 *
 * Il n'y est pas, et c'est **volontaire**. `ConsentGatedNotifier` décore l'unique chemin vers un
 * client : un envoi qui l'oublierait ne peut pas exister, puisqu'il n'y a pas d'autre porte. Le
 * vérifier ici en plus donnerait l'illusion que c'est ce contrôle-ci qui protège — et le jour où
 * quelqu'un écrirait un second service d'envoi, il recopierait le contrôle plutôt que le port.
 *
 * On lit donc simplement le verdict : `Refusee` veut dire « sans consentement ».
 *
 * ── POURQUOI L'AUDIENCE SE RÉSOUT MAINTENANT ET PAS À L'ÉCRITURE ────────────────────────────────
 *
 * Une campagne préparée lundi et envoyée vendredi doit viser qui remplit les critères **vendredi**
 * (RG-CMP-03). Sinon on écrit à des gens revenus entre-temps pour leur dire qu'ils nous manquent.
 *
 * ── LE TIRAGE DU TÉMOIN EST REPRODUCTIBLE ───────────────────────────────────────────────────────
 *
 * Il est semé par l'identifiant de la campagne : rejouer le calcul donne le même partage. Un tirage
 * aléatoire pur rendrait le résultat indéfendable — « pourquoi ce client-là n'a-t-il rien reçu ? »
 * mérite une réponse, et « le hasard » n'en est pas une quand il s'agit d'un fichier client.
 */
final readonly class CampaignSender
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SegmentResolver $resolver,
        private ClientNotifierInterface $notifier,
        private SolicitationCap $plafond,
    ) {
    }

    /**
     * @return array<string, int> le compte par issue, prêt à afficher
     */
    public function envoyer(Campaign $campaign): array
    {
        $segment = $campaign->getSegment();
        if ($segment === null) {
            return [];
        }

        $clients = $this->resolver->resoudre($segment);
        $temoins = $this->tirerLeTemoin($clients, $campaign);

        $comptes = [];
        foreach ($clients as $client) {
            $issue = isset($temoins[(string) $client->getId()])
                ? $this->enregistrer($campaign, $client, RecipientOutcome::Temoin)
                : $this->contacter($campaign, $client);

            $cle = $issue->getExclusionReason()?->value ?? $issue->getOutcome()->value;
            $comptes[$cle] = ($comptes[$cle] ?? 0) + 1;
        }

        $campaign->setStatus(CampaignStatus::Envoyee)->setSentAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $comptes;
    }

    private function contacter(Campaign $campaign, Client $client): CampaignRecipient
    {
        if ($this->plafond->estAtteint($client, $campaign->getChannel()->value)) {
            return $this->enregistrer($campaign, $client, RecipientOutcome::Exclu, ExclusionReason::TropSollicite);
        }

        // Une variable qu'on ne sait pas remplir écarte CETTE personne, pas la campagne. Bloquer
        // tout parce qu'un client sur mille n'a pas de prénom serait une punition collective.
        $corps = MessageVariables::remplir($campaign->getBody(), $client);
        $objet = MessageVariables::remplir($campaign->getSubject(), $client);
        if ($corps === null || $objet === null) {
            return $this->enregistrer($campaign, $client, RecipientOutcome::Exclu, ExclusionReason::VariableNonResolue);
        }

        if (!$this->aUneCoordonnee($client, $campaign->getChannel()->value)) {
            return $this->enregistrer($campaign, $client, RecipientOutcome::Exclu, ExclusionReason::CoordonneeManquante);
        }

        // BASE LÉGALE : CONSENTEMENT, jamais « contractuelle ».
        //
        // La base contractuelle traverse le décorateur sans contrôle — c'est fait pour les factures
        // et les confirmations, qu'on ne peut pas refuser à quelqu'un qui a payé. La déclarer ici
        // ferait passer une campagne commerciale pour un message dû : ce serait le seul moyen de
        // contourner le consentement dans tout le dépôt, et ce serait par cette ligne.
        $verdict = $this->notifier->notify(new ClientNotification(
            clientId: $client->getId(),
            channel: $campaign->getChannel(),
            templateKey: 'marketing.campaign',
            // Les variables sont deja resolues : on passe ce qui a servi a les remplir, pas le
            // texte compose. Le transport n'a pas a connaitre la redaction.
            variables: ['objet' => $objet],
            // L'instant METIER : celui ou l'exploitant decide d'ecrire, pas l'heure d'execution du
            // traitement (D37). Une campagne planifiee de nuit ne s'est pas produite a 3 h.
            occurredAt: new \DateTimeImmutable(),
            source: 'marketing.campaign:' . $campaign->getId(),
            basis: NotificationBasis::Consentement,
        ));

        if ($verdict === NotificationOutcome::Refusee) {
            return $this->enregistrer($campaign, $client, RecipientOutcome::Exclu, ExclusionReason::SansConsentement);
        }

        return $this->enregistrer($campaign, $client, RecipientOutcome::Journalise);
    }

    private function enregistrer(
        Campaign $campaign,
        Client $client,
        RecipientOutcome $issue,
        ?ExclusionReason $motif = null,
    ): CampaignRecipient {
        $destinataire = (new CampaignRecipient($campaign, $client, $issue))->setExclusionReason($motif);
        $this->entityManager->persist($destinataire);

        return $destinataire;
    }

    /**
     * Le groupe témoin, tiré AVANT toute exclusion.
     *
     * Le tirer après écarterait mécaniquement les non-consentants du témoin — le témoin ne
     * ressemblerait alors plus à l'audience, et la comparaison entre les deux groupes ne voudrait
     * plus rien dire. On compare des gens comparables ou on ne compare pas.
     *
     * @param list<Client> $clients
     *
     * @return array<string, true> les identifiants tirés, en table pour une recherche directe
     */
    private function tirerLeTemoin(array $clients, Campaign $campaign): array
    {
        $part = $campaign->getControlGroupPercent();
        if ($part <= 0 || $clients === []) {
            return [];
        }

        $identifiants = array_map(static fn (Client $c): string => (string) $c->getId(), $clients);

        // Semé par la campagne : le même partage se rejoue à l'identique. « Pourquoi ce client-là
        // n'a-t-il rien reçu ? » mérite une réponse reproductible.
        usort($identifiants, static fn (string $a, string $b): int => strcmp(
            md5((string) $campaign->getId() . $a),
            md5((string) $campaign->getId() . $b),
        ));

        $combien = (int) floor(\count($identifiants) * $part / 100);

        return array_fill_keys(\array_slice($identifiants, 0, $combien), true);
    }

    private function aUneCoordonnee(Client $client, string $canal): bool
    {
        return match ($canal) {
            'email' => trim((string) $client->getEmail()) !== '',
            'sms' => trim((string) $client->getTelephone()) !== '',
            default => false,
        };
    }
}
