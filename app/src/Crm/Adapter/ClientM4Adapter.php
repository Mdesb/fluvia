<?php

declare(strict_types=1);

namespace App\Crm\Adapter;

use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Port\ClientM4Interface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation réelle du port `ClientM4Interface` (US-L2-05, remplace `ClientM4Stub`) : recherche
 * un client existant (nom, e-mail, téléphone) ou en crée un rapidement pour un rattachement vente M2.
 */
final class ClientM4Adapter implements ClientM4Interface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function rechercher(string $critere): ?Uuid
    {
        $critere = trim($critere);
        if ($critere === '') {
            return null;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('c')->from(Client::class, 'c')
            ->where('c.statut != :fusionne')
            ->andWhere($qb->expr()->orX(
                'LOWER(c.email) = :critere',
                'LOWER(c.telephone) = :critere',
                'LOWER(c.nom) = :critere',
            ))
            ->setParameter('fusionne', StatutClient::Fusionne->value)
            ->setParameter('critere', mb_strtolower($critere))
            ->setMaxResults(1);

        /** @var Client|null $client */
        $client = $qb->getQuery()->getOneOrNullResult();

        return $client?->getId();
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public function creerRapide(array $donnees): Uuid
    {
        $etablissement = $this->contexte->etablissementActif() ?? $this->em->getRepository(Etablissement::class)->findOneBy([]);

        $client = new Client();
        $client->setType(TypeClient::Physique);
        $client->setNom(\is_string($donnees['nom'] ?? null) ? $donnees['nom'] : 'Client');
        $client->setPrenom(\is_string($donnees['prenom'] ?? null) ? $donnees['prenom'] : null);
        $client->setEmail(\is_string($donnees['email'] ?? null) ? $donnees['email'] : null);
        $client->setTelephone(\is_string($donnees['telephone'] ?? null) ? $donnees['telephone'] : null);
        $client->setStatut(StatutClient::Actif);
        if ($etablissement instanceof Etablissement) {
            $client->setEtablissementCreation($etablissement);
            $client->setGroupe($etablissement->getRegion()?->getGroupe());
        }

        $this->em->persist($client);
        $this->em->flush();

        return $client->getId();
    }
}
