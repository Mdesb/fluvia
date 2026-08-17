<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résout/crée paresseusement l'utilisateur technique auteur des `ArticleAide`/`VersionArticle`
 * d'origine `import` (§5.2 plan-support.md), patron `App\Boutique\Service\SessionSystemeBoutiqueResolver`.
 * Compte non connectable (mot de passe aléatoire jamais communiqué).
 */
final class UtilisateurSystemeSupportResolver
{
    public const EMAIL_SYSTEME = 'systeme.support@itcotation.internal';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function utilisateurSysteme(): Utilisateur
    {
        $existant = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => self::EMAIL_SYSTEME]);
        if ($existant instanceof Utilisateur) {
            return $existant;
        }

        $utilisateur = new Utilisateur();
        $utilisateur->setEmail(self::EMAIL_SYSTEME)
            ->setNom('Système (import doc vivante Support)')
            ->setActif(true)
            ->setMotDePasse(bin2hex(random_bytes(32)))
            ->setRolesSecurite(['ROLE_SYSTEME']);
        $this->em->persist($utilisateur);
        $this->em->flush();

        return $utilisateur;
    }
}
