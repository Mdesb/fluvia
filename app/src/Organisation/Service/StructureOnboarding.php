<?php

declare(strict_types=1);

namespace App\Organisation\Service;

use App\Caisse\Entity\PointDeVente;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Legal\Entity\LegalIdentity;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * OUVRIR UNE STRUCTURE — un seul geste, et le site est vendable.
 *
 * ── CE QUE CET ÉCRAN REMPLACE ───────────────────────────────────────────────────────────────────
 *
 * Avant le 28/08, accueillir un client demandait : créer une région (aucun écran ne le permettait),
 * créer un établissement (il n'apparaissait pas, faute d'affectation), retaper la dénomination, la
 * forme juridique, le SIRET et l'adresse depuis un extrait Kbis, puis créer un point de vente. Cinq
 * gestes dont deux impossibles.
 *
 * ── GROUPE ET RÉGION SONT CRÉÉS EN SILENCE, ET C'EST VOULU ──────────────────────────────────────
 *
 * Le modèle exige la chaîne `Groupe → Région → Établissement` : c'est par elle que le fichier
 * client est cloisonné (`Client.groupe` est dérivé de `etablissement.region.groupe`). On ne la
 * touche pas — l'étanchéité de tout le produit en dépend.
 *
 * Mais un club de sport indépendant n'a que faire de « régions ». On les crée donc du nom de la
 * structure, sans les montrer. Elles n'apparaîtront que le jour où il y aura plusieurs sites.
 *
 * > **Ce qui est structurel pour le modèle n'a pas à être une question pour l'exploitant.**
 *
 * ── LE POINT DE VENTE EST CRÉÉ AVEC ─────────────────────────────────────────────────────────────
 *
 * `PretAVendre` vérifie trois choses : un type de tarif, un taux de TVA, un point de vente. Les deux
 * premiers sont des référentiels de socle, partagés. Le troisième est propre au site — sans lui,
 * rien ne peut être encaissé. Le créer ici, c'est la différence entre « votre structure existe » et
 * « vous pouvez vendre ».
 */
final readonly class StructureOnboarding
{
    /** Ce qu'on encaisse partout, et qui ne demande aucun matériel. */
    private const MOYENS_PAR_DEFAUT = ['especes', 'cb'];

    /**
     * Le métier déduit du code NAF publié par l'annuaire.
     *
     * Poser la question quand la réponse est déjà connue est une question de trop. Le NAF n'est pas
     * un devin — un même code couvre parfois deux métiers — d'où la règle : on PROPOSE, l'exploitant
     * corrige d'un clic. Ce qui n'est pas reconnu ne propose rien plutôt que de proposer au hasard.
     */
    private const NAF_VERS_METIER = [
        '93.13Z' => 'sport',    // centres de culture physique — salles de sport
        '93.11Z' => 'sport',    // gestion d'installations sportives
        '93.12Z' => 'sport',    // clubs de sport
        '91.02Z' => 'musee',    // gestion des musées
        '91.03Z' => 'musee',    // monuments historiques et sites
        '93.29Z' => 'sport',    // autres activités récréatives
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Fonctionnalites $presets,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public function ouvrir(array $donnees, Utilisateur $auteur, Role $role): Etablissement
    {
        // RAISON SOCIALE ET NOM COMMERCIAL SONT DEUX CHOSES.
        //
        // La première nomme l'entreprise sur les documents légaux — factures, mandats SEPA,
        // mentions légales. Le second est ce que le club affiche sur sa devanture, et ce que les
        // équipes reconnaissent en changeant de site. « GI-ONE FITNESS » et « Gione Fitness » ne
        // sont pas la même chaîne, et les confondre met une enseigne sur une facture.
        //
        // L'établissement porte le NOM COMMERCIAL ; l'identité légale garde la RAISON SOCIALE.
        $raisonSociale = trim((string) ($donnees['denomination'] ?? ''));
        $nom = trim((string) ($donnees['nomCommercial'] ?? '')) ?: $raisonSociale;
        if ($nom === '') {
            throw new UnprocessableEntityHttpException('Le nom de la structure est obligatoire.');
        }

        $existant = $this->entityManager->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        if ($existant instanceof Etablissement) {
            // 409 et non 422 : ce n'est pas une saisie invalide, c'est un geste déjà fait. Le
            // distinguer évite le doublon qu'un « réessayez » produirait.
            throw new ConflictHttpException(sprintf(
                'Une structure nommée « %s » existe déjà. Ouvrez-la plutôt que d’en créer une seconde.',
                $nom,
            ));
        }

        $groupe = (new Groupe())->setNom($nom);
        $this->entityManager->persist($groupe);

        // Le nom de la région reprend celui de la structure : invisible à l'écran, lisible en base
        // le jour où quelqu'un s'y penche. « Région 1 » n'aurait rien appris à personne.
        $region = (new Region())->setNom($nom)->setGroupe($groupe);
        $this->entityManager->persist($region);

        $etablissement = (new Etablissement())->setNom($nom)->setRegion($region);
        $this->entityManager->persist($etablissement);

        // Sans affectation, l'auteur ne verrait pas ce qu'il vient de créer : la liste des
        // établissements est filtrée sur les sites où le lecteur en possède une.
        $this->entityManager->persist(
            (new Affectation())
                ->setUtilisateur($auteur)
                ->setRole($role)
                ->setEtablissement($etablissement)
        );

        $this->entityManager->persist(
            (new PointDeVente())
                ->setLibelle('Accueil')
                ->setEtablissement($etablissement)
                ->setMoyensAutorises(self::MOYENS_PAR_DEFAUT)
        );

        $this->entityManager->persist($this->identiteLegale($donnees, $raisonSociale, $etablissement));

        $this->entityManager->flush();

        // LE PRESET DE SECTEUR, APRES LE FLUSH : il a besoin d'un etablissement qui existe.
        //
        // Il active les capacites du metier -- controle d'acces, reservation, SEPA, casiers selon
        // les cas. Sans lui, un club de sport ouvre avec la configuration d'un musee : tout
        // desactive, et l'exploitant cherche pourquoi son abonnement mensuel ne trouve pas le SEPA.
        $metier = $this->metier($donnees);
        if ($metier instanceof Metier) {
            $this->presets->appliquerPreset($etablissement, $metier);
            $this->entityManager->flush();
        }

        return $etablissement;
    }

    /**
     * Le métier : celui que l'exploitant a choisi, sinon celui que le code NAF suggère.
     *
     * L'ordre compte. Un choix explicite l'emporte toujours sur une déduction — le NAF décrit
     * l'activité déclarée au greffe, pas nécessairement ce que le logiciel doit faire.
     *
     * @param array<string, mixed> $donnees
     */
    private function metier(array $donnees): ?Metier
    {
        $choisi = trim((string) ($donnees['metier'] ?? ''));
        if ($choisi !== '') {
            return Metier::tryFrom($choisi);
        }

        $naf = trim((string) ($donnees['codeNaf'] ?? ''));

        return $naf === '' ? null : Metier::tryFrom(self::NAF_VERS_METIER[$naf] ?? '');
    }

    /**
     * L'identité légale, reprise de l'annuaire — jamais retapée.
     *
     * Les champs que l'annuaire ne publie pas (capital social, directeur de publication, hébergeur)
     * restent vides : les inventer donnerait des mentions légales fausses, ce qui est pire que des
     * mentions légales incomplètes. L'écran des mentions légales les réclamera le moment venu.
     *
     * @param array<string, mixed> $donnees
     */
    private function identiteLegale(array $donnees, string $raisonSociale, Etablissement $etablissement): LegalIdentity
    {
        $texte = static fn (string $cle): ?string => match (true) {
            !isset($donnees[$cle]) => null,
            trim((string) $donnees[$cle]) === '' => null,
            default => trim((string) $donnees[$cle]),
        };

        return (new LegalIdentity())
            ->setEstablishment($etablissement)
            ->setLegalName($texte('raisonSociale') ?? $raisonSociale)
            ->setLegalForm($texte('formeJuridique'))
            ->setSiret($texte('siret'))
            ->setVatNumber($texte('numeroTva'))
            ->setRegisteredAddress($texte('adresse'));
    }
}
