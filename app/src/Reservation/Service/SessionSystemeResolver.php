<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fournit une session de caisse technique **permanente** par établissement, condition nécessaire au
 * mode `debit_pmv` (bascule automatique no-show sans agent présent, `Vente.session` non nullable —
 * Risque n°1 du plan). ⚠ Ce choix a des implications NF525/comptables (vente sans opérateur humain
 * identifié) qui **doivent être validées par un expert compta/NF525 avant mise en production**
 * (constitution §4 point 5).
 */
final class SessionSystemeResolver
{
    private const EMAIL_SYSTEME = 'systeme.reservation@itcotation.internal';
    private const LIBELLE_PDV = 'Système — Facturation no-show (réservation)';

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
        $caisse->setLibelle('Caisse système')->setPointDeVente($pdv)->setEtat(EtatCaisse::Ouverte);
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
            ->setNom('Système (facturation automatique no-show)')
            ->setStatut(StatutUtilisateur::Actif)
            ->setMotDePasse(bin2hex(random_bytes(32)))
            ->setRolesSecurite(['ROLE_SYSTEME']);
        $this->em->persist($utilisateur);

        return $utilisateur;
    }

    private function numero(Etablissement $etablissement): string
    {
        return 'SYS-RES-' . substr(strtoupper($etablissement->getId()->toRfc4122()), 0, 8);
    }
}
