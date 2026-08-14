<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Controleur;
use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Entity\ListeRevocation;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutSupport;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Support perdu/volé (US-L3-09, RG-ACC-07, CA-10) : blocage serveur immédiat (refus online), ajout à
 * la liste de révocation (nouvelle version par contrôleur, propagée à la prochaine synchro — refus
 * hors-ligne aussi), déclaration tracée et réversible par un rôle habilité.
 */
final class BlocageSupportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function bloquer(Support $support, string $motif, Utilisateur $agent): DeclarationPerteVol
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Motif requis pour une déclaration de perte/vol.');
        }

        $support->setStatut(StatutSupport::Bloque);

        $declaration = new DeclarationPerteVol();
        $declaration->setSupport($support)
            ->setMotif($motif)
            ->setAgent($agent)
            ->setEtablissement($support->getEtablissement());

        $this->em->persist($declaration);

        $this->propagerRevocation($support);

        $this->em->flush();

        return $declaration;
    }

    public function annuler(DeclarationPerteVol $declaration, Utilisateur $agent): DeclarationPerteVol
    {
        $declaration->setAnnulee(true)->setAnnuleePar($agent)->setAnnuleeLe(new \DateTimeImmutable());

        $support = $declaration->getSupport();
        if ($support instanceof Support) {
            $support->setStatut(StatutSupport::Actif);
        }

        $this->em->flush();

        return $declaration;
    }

    /** Incrémente la version de la liste de révocation de chaque contrôleur (propagation §4.7). */
    private function propagerRevocation(Support $support): void
    {
        $controleurs = $this->em->getRepository(Controleur::class)->findBy(['etablissement' => $support->getEtablissement()]);
        $identifiantsBloques = array_map(
            static fn (Support $s) => $s->getIdentifiant(),
            $this->em->getRepository(Support::class)->findBy(['statut' => StatutSupport::Bloque, 'etablissement' => $support->getEtablissement()]),
        );

        foreach ($controleurs as $controleur) {
            $version = $controleur->getVersionRevocation() + 1;
            $controleur->setVersionRevocation($version);

            $liste = new ListeRevocation();
            $liste->setControleur($controleur)->setVersion($version)->setSupportsBloques($identifiantsBloques);
            $this->em->persist($liste);
        }
    }
}
