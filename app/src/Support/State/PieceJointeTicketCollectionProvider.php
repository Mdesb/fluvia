<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\PieceJointeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/piece_jointe_tickets` : les pieces jointes des tickets qu'on a le droit de lire.
 *
 * ⚠ UNE PORTE FERMEE, SA VOISINE OUVERTE.
 *
 * `MessageTicket` porte un `provider: MessageTicketProvider::class` qui verifie, avant de rendre
 * quoi que ce soit, qu'on est le demandeur du ticket ou un agent habilite. `PieceJointeTicket`
 * n'avait AUCUN fournisseur : sa collection rendait toutes les pieces jointes de tous les tickets
 * de tous les clients, avec `nomFichier`, `typeMime`, `taille` et `url`.
 *
 * La garde declarative demande cinq permissions « ou » — dont `support.ouvrir_ticket`, la plus
 * basique du cote client. Tout exploitant capable d'ouvrir un ticket lisait donc les pieces jointes
 * deposees par les autres clients dans les leurs.
 *
 * Mesure du 31/08 sur base jetable : `CloisonnementPiecesJointesTest`. Un exploitant du site A
 * recevait le nom et l'URL d'un fichier depose sur un ticket du site B.
 *
 * ── LA REGLE EST CELLE DU VOISIN, DELIBEREMENT ──────────────────────────────────────────────────
 *
 * Deux portes sur la meme donnee doivent dire la meme chose. On reprend donc mot pour mot les
 * conditions de `MessageTicketProvider` :
 *
 *   — agent (`traiter_ticket_n1`, `traiter_ticket_n2`, `administrer`, `lire_ticket_etablissement`)
 *     ou demandeur du ticket ;
 *   — ⚠ ET LES NOTES INTERNES RESTENT INVISIBLES AU DEMANDEUR. C'est la condition que j'ai failli
 *     omettre : une piece jointe portee par une note interne suit sa note. La rendre au demandeur
 *     parce qu'elle est « sur son ticket » divulguerait ce que le filtre des messages protege --
 *     et le fichier est souvent plus parlant que le texte qui l'accompagne.
 *
 * ⚠ UNE DIFFERENCE ASSUMEE AVEC LE VOISIN, ET ELLE EST PLUS STRICTE. `MessageTicketProvider` recoit
 * un ticket designe et verifie l'habilitation sans comparer l'etablissement de ce ticket a
 * l'etablissement ACTIF : un agent y est agent partout ou ses codes le disent. Ici la collection
 * n'est bornee par aucun ticket, donc on borne par l'etablissement actif -- sans quoi « agent »
 * vaudrait « toutes les pieces jointes de tous les sites ou j'ai ce droit », ce qui reintroduirait
 * la fuite sous une autre forme.
 *
 * @implements ProviderInterface<list<PieceJointeTicket>>
 */
final class PieceJointeTicketCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /** @return list<PieceJointeTicket> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return [];
        }

        $actif = $this->contexte->idActif();
        if (null === $actif) {
            // Fermeture par defaut : sans site actif, on ne sait pas au nom de quoi lire.
            return [];
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $actif);
        $estAgent = $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n1')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n2')
            || $this->calculateur->autorise($codes, 'support', 'administrer')
            || $this->calculateur->autorise($codes, 'support', 'lire_ticket_etablissement');

        $qb = $this->em->getRepository(PieceJointeTicket::class)->createQueryBuilder('pj')
            ->innerJoin('pj.message', 'msg')
            ->innerJoin('msg.ticket', 'tkt')
            ->orderBy('pj.dateCreation', 'ASC');

        if ($estAgent) {
            $qb->andWhere('IDENTITY(tkt.etablissement) = :actif')
                ->setParameter('actif', $actif, 'uuid');
        } else {
            // Le demandeur ne voit que ses propres tickets, et jamais les notes internes.
            $qb->andWhere('IDENTITY(tkt.demandeur) = :moi')
                ->andWhere('msg.noteInterne = false')
                ->setParameter('moi', $utilisateur->getId(), 'uuid');
        }

        // ⚠ LE FILTRE DECLARE PAR LA RESSOURCE, QUE CE FOURNISSEUR DOIT APPLIQUER LUI-MEME.
        //
        // `PieceJointeTicket` declare un `SearchFilter` sur `message`. Ce filtre est pose par les
        // extensions Doctrine d'API Platform, qu'un fournisseur sur mesure court-circuite
        // entierement. Sans ces lignes, `?message=...` etait accepte, ignore, et rendait TOUTES les
        // pieces jointes du perimetre — pas d'erreur, pas de refus, de mauvaises donnees.
        //
        // On accepte l'IRI comme l'UUID : `MessageTicketProvider` a deja paye de ne pas le faire.
        $filtre = $context['filters']['message'] ?? null;
        if (\is_string($filtre) && '' !== $filtre) {
            $segment = str_contains($filtre, '/') ? basename($filtre) : $filtre;
            if (Uuid::isValid($segment)) {
                $qb->andWhere('IDENTITY(pj.message) = :message_filtre')
                    ->setParameter('message_filtre', $segment, 'uuid');
            } else {
                // Un filtre malforme ne doit pas ELARGIR le resultat : on rend vide plutot que tout.
                return [];
            }
        }

        /** @var list<PieceJointeTicket> */
        return $qb->getQuery()->getResult();
    }
}
