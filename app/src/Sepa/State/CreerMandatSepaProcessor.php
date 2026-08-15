<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /sepa/mandats (plan §2/§6). Corps :
 *   { "client": iri|uuid, "etablissement"?: iri|uuid, "iban": string, "bicDebiteur": string,
 *     "debiteurNom": string, "dateSignature"?: "AAAA-MM-JJ" }
 * L'IBAN en clair transite uniquement ici (jamais mappé Doctrine, tokenisé avant persistance, §4 spec).
 * `etablissement` par défaut = établissement actif (en-tête `X-Etablissement`).
 *
 * @implements ProcessorInterface<mixed, MandatSepa>
 */
final class CreerMandatSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MandatSepa
    {
        $corps = $this->lecteur->corps();

        $client = $this->resoudre(Client::class, $corps['client'] ?? null);
        if (!$client instanceof Client) {
            throw new UnprocessableEntityHttpException('« client » est requis et doit référencer un client existant.');
        }

        $etablissement = isset($corps['etablissement'])
            ? $this->resoudre(Etablissement::class, $corps['etablissement'])
            : $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('« etablissement » requis (fourni ou en-tête X-Etablissement).');
        }

        $iban = \is_string($corps['iban'] ?? null) ? $corps['iban'] : '';
        $bic = \is_string($corps['bicDebiteur'] ?? null) ? $corps['bicDebiteur'] : '';
        $nom = \is_string($corps['debiteurNom'] ?? null) ? $corps['debiteurNom'] : '';
        if (trim($iban) === '' || trim($nom) === '') {
            throw new UnprocessableEntityHttpException('« iban » et « debiteurNom » sont requis pour signer le mandat SEPA.');
        }

        $dateSignature = isset($corps['dateSignature']) && \is_string($corps['dateSignature'])
            ? new \DateTimeImmutable($corps['dateSignature'])
            : new \DateTimeImmutable('today');

        $token = $this->tokenisation->tokeniser($iban);

        $mandat = new MandatSepa();
        $mandat->setRum($this->genererRum())
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setBicDebiteur($bic)
            ->setDebiteurNom($nom)
            ->setDateSignature($dateSignature)
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($client)
            ->setEtablissement($etablissement);

        $this->em->persist($mandat);
        $this->em->flush();

        return $mandat;
    }

    private function genererRum(): string
    {
        return 'RUM-' . strtoupper(substr(hash('sha256', Uuid::v4()->toRfc4122() . microtime()), 0, 20));
    }

    private function resoudre(string $classe, mixed $valeur): ?object
    {
        if (!\is_string($valeur) || trim($valeur) === '') {
            return null;
        }
        $id = str_contains($valeur, '/') ? substr($valeur, (int) strrpos($valeur, '/') + 1) : $valeur;
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository($classe)->find($id);
    }
}
