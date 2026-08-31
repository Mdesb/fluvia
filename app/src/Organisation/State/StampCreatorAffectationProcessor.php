<?php

declare(strict_types=1);

namespace App\Organisation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /etablissements` — et son auteur y est rattaché.
 *
 * ── LE DÉFAUT QUE CELA CORRIGE ──────────────────────────────────────────────────────────────────
 *
 * Vu dans le navigateur le 28/08, à la première étape de l'accueil d'un nouveau client : on crée un
 * établissement, le serveur répond **201**, la ligne existe en base — et **elle n'apparaît nulle
 * part**. La liste des établissements est filtrée sur les sites où le lecteur possède une
 * affectation ; en créer un n'en crée pas.
 *
 * L'exploitant voit donc une création qui semble échouer alors qu'elle a réussi. Il reclique, et
 * fabrique des doublons — c'est la seule issue que l'écran lui laisse.
 *
 * > **Une création qu'on ne voit pas se refait.**
 *
 * ── POURQUOI RATTACHER PLUTÔT QU'ÉLARGIR LA LISTE ───────────────────────────────────────────────
 *
 * On aurait pu montrer à un administrateur tous les établissements, affectation ou non. Ce serait
 * plus simple, et faux : le périmètre d'affectation est ce qui cloisonne tout le produit, et
 * l'ouvrir « juste pour cette liste » ouvrirait aussi les caisses, les clients et les ventes qui en
 * dépendent. On ne relâche pas la règle, on donne à l'auteur le droit qui rend son geste cohérent :
 * qui crée un site l'administre.
 *
 * ── AVEC QUEL RÔLE ──────────────────────────────────────────────────────────────────────────────
 *
 * Le sien — celui qui lui a permis d'arriver ici. La sécurité de l'opération exige déjà
 * `organisation.gerer` : on reprend l'affectation qui le porte. Inventer un rôle « administrateur du
 * nouveau site » créerait un rôle de plus à chaque création, et personne ne saurait lequel purger.
 */
final readonly class StampCreatorAffectationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Etablissement, Etablissement> $persist
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persist,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private CalculateurDroits $calculateur,
    ) {
    }

    /**
     * @param Etablissement        $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Etablissement
    {
        $etablissement = $this->persist->process($data, $operation, $uriVariables, $context);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return $etablissement;
        }

        $role = $this->roleQuiAdministre($utilisateur);
        if ($role === null) {
            // Ne devrait pas arriver : la sécurité de l'opération exige `organisation.gerer`. Si
            // cela arrivait quand même, on le dit plutôt que de rendre un 201 pour un site que
            // personne ne pourra jamais ouvrir.
            throw new UnprocessableEntityHttpException(
                'Aucun rôle ne vous permet d’administrer ce nouvel établissement : il serait créé et '
                . 'invisible. Faites-vous affecter avec un rôle portant « organisation.gerer ».',
            );
        }

        $this->entityManager->persist(
            (new Affectation())
                ->setUtilisateur($utilisateur)
                ->setRole($role)
                ->setEtablissement($etablissement)
        );
        $this->entityManager->flush();

        return $etablissement;
    }

    /**
     * Le rôle, parmi ceux de l'auteur, qui porte le droit d'administrer une organisation.
     *
     * On le cherche dans ses affectations existantes plutôt que de le deviner : c'est celui qui lui
     * a ouvert cette opération, et le reprendre garantit qu'il pourra faire sur le nouveau site
     * exactement ce qu'il fait sur les autres.
     */
    private function roleQuiAdministre(Utilisateur $utilisateur): ?\App\Securite\Entity\Role
    {
        /** @var list<Affectation> $affectations */
        $affectations = $this->entityManager->getRepository(Affectation::class)
            ->findBy(['utilisateur' => $utilisateur]);

        foreach ($affectations as $affectation) {
            $role = $affectation->getRole();
            $etablissement = $affectation->getEtablissement();
            if ($role === null || $etablissement === null) {
                continue;
            }

            $codes = $this->calculateur->codesEffectifs($utilisateur, $etablissement->getId());
            if ($this->calculateur->autorise($codes, 'organisation', 'gerer')) {
                return $role;
            }
        }

        return null;
    }
}
