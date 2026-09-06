<?php

declare(strict_types=1);

namespace App\Crm\State;

use App\Crm\Security\CustomerReachability;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Entity\JournalFusion;
use App\Crm\Service\FusionHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /crm/fusions (US-L5-08, RG-M4-06, CA-13/CA-15). Corps :
 *   { "portee": "client", "sources": [iri...], "maitre": iri, "champsArbitres"?: {...}, "motif"?: "…" }
 *   { "portee": "famille", "familleSource": iri, "familleMaitre": iri, "payeurPrincipal": iri, "motif"?: "…" }
 *
 * @implements ProcessorInterface<mixed, JournalFusion>
 */
final class FusionnerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly FusionHandler $handler,
        private readonly Security $security,
        private readonly CustomerReachability $customers,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JournalFusion
    {
        $corps = $this->lecteur->corps();
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);
        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : null;
        $portee = \is_string($corps['portee'] ?? null) ? $corps['portee'] : 'client';

        if ($portee === 'famille') {
            $maitre = $this->resoudre(Famille::class, $corps['familleMaitre'] ?? null);
            $source = $this->resoudre(Famille::class, $corps['familleSource'] ?? null);
            $payeur = $this->resoudre(Client::class, $corps['payeurPrincipal'] ?? null);
            if (!$maitre instanceof Famille || !$source instanceof Famille || !$payeur instanceof Client) {
                throw new UnprocessableEntityHttpException('« familleMaitre », « familleSource » et « payeurPrincipal » sont requis.');
            }
            // ⚠ AUDIT DU 06/09, CONSTAT 5 : une fusion est destructive, et ses sujets venaient du corps
            //   par `find()`. On absorbait les familles d'un autre groupe.
            $this->customers->assertReachable($maitre);
            $this->customers->assertReachable($source);
            $this->customers->assertReachable($payeur);
            $journal = $this->handler->fusionnerFamilles($maitre, $source, $payeur, $motif, $auteur);
        } else {
            $maitre = $this->resoudre(Client::class, $corps['maitre'] ?? null);
            if (!$maitre instanceof Client) {
                throw new UnprocessableEntityHttpException('« maitre » requis et valide.');
            }
            $this->customers->assertReachable($maitre);
            $sources = [];
            foreach ((array) ($corps['sources'] ?? []) as $iri) {
                $client = $this->resoudre(Client::class, $iri);
                if ($client instanceof Client) {
                    $this->customers->assertReachable($client);
                    $sources[] = $client;
                }
            }
            $champsArbitres = \is_array($corps['champsArbitres'] ?? null) ? $corps['champsArbitres'] : [];
            $journal = $this->handler->fusionnerClients($sources, $maitre, $champsArbitres, $motif, $auteur);
        }

        $this->em->flush();

        return $journal;
    }

    private function resoudre(string $classe, mixed $iri): ?object
    {
        if (!\is_string($iri)) {
            return null;
        }
        $id = preg_replace('#^.*/#', '', $iri);
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository($classe)->find(Uuid::fromString($id));
    }
}
