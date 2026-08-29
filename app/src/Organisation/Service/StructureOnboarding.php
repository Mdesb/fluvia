<?php

declare(strict_types=1);

namespace App\Organisation\Service;

use App\Caisse\Entity\PointDeVente;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Legal\Entity\LegalIdentity;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\AccountingChartSeeder;
use App\Offre\Service\AccountingCategorySeeder;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
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
     * LES TAUX DE TVA FRANÇAIS, POSÉS D'OFFICE.
     *
     * C'est de la base légale : un exploitant n'a pas à la saisir, et le lui demander autorise 17,3 %
     * ou un « taux normal » à 5,5 %. Il masque ce qu'il n'utilise pas — `TauxTva::$actif` existe déjà
     * pour cela — au lieu de créer ce qu'il connaît mal.
     *
     * ⚠ France seulement. `Etablissement` ne porte aucun pays : le jour où un client belge arrivera,
     * ce tableau ne saura pas quoi proposer. C'est un préalable de modèle, pas un oubli d'ici.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const TAUX_TVA_FRANCE = [
        ['20.00', 'Taux normal 20 %'],
        ['10.00', 'Taux intermédiaire 10 %'],
        ['5.50', 'Taux réduit 5,5 %'],
        ['2.10', 'Taux particulier 2,1 %'],
    ];

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
        private readonly AccountingChartSeeder $chartSeeder,
        private readonly AccountingCategorySeeder $categorySeeder,
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

        // LE PROFIL EXPLOITANT ET LES TAUX, SANS QUOI ON NE PEUT PAS VENDRE.
        //
        // « Avant de pouvoir vendre » exige un taux de TVA ; un taux exige un profil exploitant ; et
        // aucun écran ne permettait d'en créer un. La mise en service s'arrêtait là, pour tout le
        // monde, sans que rien n'indique par où sortir.
        $this->creerProfilComptable($donnees, $etablissement);

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
    /**
     * Le profil comptable de la structure, et ses taux de TVA.
     *
     * LE TYPE SE DÉDUIT DE LA NATURE JURIDIQUE, il ne se demande pas. Les codes INSEE commençant par
     * `4` désignent les personnes morales de droit public ; les autres sont privées. C'est le même
     * geste que le métier déduit du code NAF : poser une question dont on a la réponse est une
     * question de trop.
     *
     * ⚠ ET CE N'EST PAS QU'UNE ÉTIQUETTE. `SelecteurPaiementEnLigne` choisit le prestataire de
     * paiement SUR CE TYPE : laisser le défaut `RegieDirecte` aurait envoyé une salle de sport
     * encaisser par PayFiP, le portail de l'État. Le référentiel comptable suit la même logique —
     * M57 pour une collectivité, le plan comptable général pour une société.
     *
     * @param array<string, mixed> $donnees
     */
    private function creerProfilComptable(array $donnees, Etablissement $etablissement): void
    {
        // Le SIREN est la racine du SIRET : neuf chiffres, la contrainte de l'entité l'exige.
        $siret = preg_replace('/\D/', '', (string) ($donnees['siret'] ?? '')) ?? '';
        $siren = substr($siret, 0, 9);
        if (\strlen($siren) !== 9) {
            // Sans SIREN valide, le profil ne passerait pas la validation. On n'invente pas un
            // numéro d'entreprise : l'exploitant le complétera, et le reste de l'ouverture tient.
            return;
        }

        $publique = str_starts_with((string) ($donnees['formeJuridique'] ?? ''), '4');

        $profil = (new ProfilExploitant())
            ->setSiren($siren)
            ->setEtablissementPrincipal($etablissement)
            ->setType($publique ? TypeExploitant::RegieDirecte : TypeExploitant::GroupePrive)
            ->setReferentielComptable($publique ? ReferentielComptable::M57 : ReferentielComptable::Pcg);

        $this->entityManager->persist($profil);

        foreach (self::TAUX_TVA_FRANCE as [$taux, $libelle]) {
            $this->entityManager->persist(
                (new TauxTva())
                    ->setProfilExploitant($profil)
                    ->setTaux($taux)
                    ->setLibelle($libelle)
                    ->setActif(true)
            );
        }

        // Le hors-champ n'est pas un taux à zéro parmi d'autres : il dit « cette opération n'entre
        // pas dans le champ de la TVA ». Le confondre avec une exonération fausse la déclaration.
        $this->entityManager->persist(
            (new TauxTva())
                ->setProfilExploitant($profil)
                ->setTaux('0.00')
                ->setLibelle(TauxTva::LIBELLE_HORS_CHAMP)
                ->setActif(true)
        );

        // ⚠ LE PROFIL ET LES TAUX NE SUFFISENT PAS : SANS PLAN DE COMPTES NI JOURNAUX, LA
        // GÉNÉRATION D'ÉCRITURES NE DÉMARRE PAS.
        //
        // Les régimes résolvent leurs comptes par préfixe (511, 411, 4457, 487, 512, 706) et leurs
        // journaux par code (VTE, ENC, REG, PCA, EXT), et lèvent une 422 quand ils ne trouvent rien.
        // Sur un établissement réellement créé, aucun de ces objets n'existait — ils n'étaient posés
        // que par les jeux d'essai, et rien dans l'application ne permettait d'en saisir.
        //
        // C'est de la nomenclature, pas un choix de gestion : même raison que pour les taux légaux
        // ci-dessus, et même arbitrage.
        $this->chartSeeder->poser($profil);

        // ── ET LES CATEGORIES COMPTABLES, POUR LA MEME RAISON ────────────────────────────────
        //
        // Une ligne libre de facture porte une categorie, et cette categorie decide du compte par
        // `MappingComptable`. Sans categorie, l'ecran de correspondance serait vide et le resolveur
        // se replierait EN SILENCE sur le compte par defaut -- une comptabilite qui ne distingue
        // rien, et qui ne le dit pas.
        //
        // Portee socle : c'est de la nomenclature, partagee, et chacun peut toujours ajouter la
        // sienne localement (D51). La CORRESPONDANCE, elle, reste un choix d'exploitant : elle
        // depend de son plan de comptes et des habitudes de son comptable.
        $this->categorySeeder->poser();
    }

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
