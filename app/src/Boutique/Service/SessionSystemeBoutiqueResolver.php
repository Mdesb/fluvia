<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fournit une session de caisse technique **permanente** par établissement pour la vente en ligne
 * 24/7 (§0 décision n°9 du plan) — condition nécessaire à `Vente.session` (non nullable en base).
 * Reproduit le patron déjà éprouvé par `App\Reservation\Service\SessionSystemeResolver` (code réel,
 * lu non modifié) avec son propre espace de nommage. ⚠ Le même avertissement NF525/comptable
 * s'applique ici à l'identique (vente sans opérateur humain identifié, Risque n°1 du plan).
 */
final class SessionSystemeBoutiqueResolver
{
    private const EMAIL_SYSTEME = 'systeme.boutique@itcotation.internal';
    private const LIBELLE_PDV = 'Système — Vente en ligne (boutique)';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function sessionSysteme(Etablissement $etablissement): SessionCaisse
    {
        $existante = $this->em->getRepository(SessionCaisse::class)->createQueryBuilder('s')
            ->andWhere('s.etablissement = :etab')
            ->andWhere('s.numero = :numero')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('numero', $this->numero($etablissement))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existante instanceof SessionCaisse) {
            return $existante;
        }

        $utilisateur = $this->utilisateurSysteme();

        $pdv = new PointDeVente();
        $pdv->setLibelle(self::LIBELLE_PDV)->setEtablissement($etablissement)->setMoyensAutorises([]);
        $this->em->persist($pdv);

        $caisse = new Caisse();
        $caisse->setLibelle('Caisse système boutique')->setPointDeVente($pdv)->setEtat(EtatCaisse::Ouverte);
        $this->em->persist($caisse);

        $session = new SessionCaisse();
        $session->setNumero($this->numero($etablissement))
            ->setPointDeVente($pdv)
            ->setCaisse($caisse)
            ->setRegisseur($utilisateur)
            ->setOperateur($utilisateur)
            ->setFondDeCaisse('0.00')
            ->setEtablissement($etablissement);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    private function utilisateurSysteme(): Utilisateur
    {
        $existant = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_SYSTEME]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setEmail(self::EMAIL_SYSTEME)
            ->setNom('Système (vente en ligne boutique)')
            ->setStatut(StatutUtilisateur::Actif)
            ->setMotDePasse(bin2hex(random_bytes(32)))
            ->setRolesSecurite(['ROLE_SYSTEME']);
        $this->em->persist($utilisateur);

        return $utilisateur;
    }

    private function numero(Etablissement $etablissement): string
    {
        return 'SYS-BOU-' . substr(strtoupper($etablissement->getId()->toRfc4122()), 0, 8);
    }
}
