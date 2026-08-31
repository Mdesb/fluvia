<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ProfilExploitant;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pose le profil comptable sur un parametrage de facturation, d'apres l'etablissement actif (D41).
 *
 * ⚠ NOM ANGLAIS PARCE QUE LE FICHIER EST NEUF (D5). L'entite qu'il estampille porte un nom
 * francais — elle est anterieure au retrofit — mais un fichier ajoute n'a aucune raison
 * d'introduire du vocabulaire francais de plus. La regle ne porte que sur ce qu'on ecrit
 * aujourd'hui, et c'est ce qui la rend tenable.
 *
 * ⚠ SANS CE PROCESSEUR, LA RESSOURCE ETAIT INUTILISABLE — ET C'EST POURQUOI AUCUN ECRAN NE
 * L'APPELAIT.
 *
 * `ParametreFacturationEtablissement::$profilExploitant` porte `nullable: false` ET le groupe
 * `parametre_facturation:write` : c'etait donc au client de le fournir, et rien ne le posait cote
 * serveur. Un POST sans lui rendait :
 *
 *     SQLSTATE[23000] : Column 'profil_exploitant_id' cannot be null
 *
 * Un 500, pas un refus. Mesure faite le 31/08 sur une base jetable — surtout pas en preprod, ou
 * cette ressource n'expose aucun `Delete` et ou chaque essai aurait laisse une ligne definitive.
 *
 * ⚠ ET LE CLIENT NE DOIT PAS POUVOIR LE FOURNIR NON PLUS. Le champ sort du groupe d'ecriture : un
 * profil accepte depuis le corps aurait permis d'ecrire le parametrage de facturation du voisin —
 * ses conditions de reglement, son taux de penalites, son compte de produit par defaut. Le symptome
 * aurait ete un reglage qui change tout seul chez quelqu'un d'autre, ce qu'on n'impute jamais a une
 * requete etrangere.
 *
 * @implements ProcessorInterface<ParametreFacturationEtablissement, ParametreFacturationEtablissement>
 */
final class BillingSettingsStampProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<ParametreFacturationEtablissement, ParametreFacturationEtablissement> $persist */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof ParametreFacturationEtablissement);

        if (null === $data->getProfilExploitant()) {
            $etablissement = $this->contexte->etablissementActif();
            if (null === $etablissement) {
                throw new UnprocessableEntityHttpException('Etablissement actif requis (en-tete X-Etablissement).');
            }

            // Le meme chemin que `AccountingScopeExtension` emprunte pour filtrer les lectures :
            // `profilExploitant.etablissementPrincipal`. Resoudre autrement ecrirait un parametrage
            // que la lecture ne rendrait pas — une ligne qu'on pose et qu'on ne revoit jamais.
            $profil = $this->em->getRepository(ProfilExploitant::class)
                ->findOneBy(['etablissementPrincipal' => $etablissement]);

            if (!$profil instanceof ProfilExploitant) {
                throw new UnprocessableEntityHttpException(
                    'Aucun profil comptable n\'est rattache a cet etablissement : un parametrage de '
                    . 'facturation s\'y rattache.'
                );
            }

            $data->setProfilExploitant($profil);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
