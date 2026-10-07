<?php

declare(strict_types=1);

namespace App\Organisation\Service;

use App\Caisse\Entity\PointDeVente;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Legal\Entity\LegalIdentity;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\AccountingChartSeeder;
use App\Compta\Service\VatRateSeeder;
use App\Facturation\Service\BillingSettingsSeeder;
use App\Offre\Service\AccountingCategorySeeder;
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
        private readonly VatRateSeeder $vatRateSeeder,
        private readonly BillingSettingsSeeder $billingSettingsSeeder,
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
        $this->paysEtTerritoire($donnees, $etablissement);
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

        // ── L'IDENTITÉ D'ÉMETTEUR VIENT DE L'INSCRIPTION, PAS D'UNE SECONDE SAISIE ──────────────────
        // La raison sociale et le SIRET sont ceux que la structure a déjà déclarés au greffe à son
        // inscription (la `denomination`, la même qui alimente `LegalIdentity`). Les laisser vides
        // ici obligeait à les ressaisir dans le menu « qui facture » — un doublon qui finit par
        // diverger, et une facture partie sous un SIREN juste mais sans raison sociale. Le SIREN
        // suffit à identifier l'émetteur français (BT-30) : on ne réclame pas de n° de TVA ici.
        // L'ADRESSE VENDEUR (BT-35/37/38/40) vient elle aussi de l'inscription : l'annuaire l'a
        // déjà découpée (rue / CP / ville), et le pays est celui de l'établissement (code ISO2). Sans
        // ces quatre champs, le Factur-X d'une structure neuve serait refusé à l'émission (422).
        $raisonSocialeInscription = trim((string) ($donnees['raisonSociale'] ?? $donnees['denomination'] ?? ''));
        if ($raisonSocialeInscription !== '') {
            $profil->setRaisonSociale($raisonSocialeInscription);
        }
        if (\strlen($siret) === 14) {
            $profil->setSiret($siret);
        }

        // Les quatre champs vont ensemble : une adresse a moitie remplie ne rend pas la facture
        // emettable, elle la rend fausse. On ne pose donc l'adresse que lorsque l'annuaire a fourni
        // la rue, la ville ET le code postal ; sinon on la laisse vide et le rapport de conformite
        // nomme ce qui manque, plutot qu'une adresse tronquee qui aurait l'air valide.
        $rue = trim((string) ($donnees['rue'] ?? ''));
        $ville = trim((string) ($donnees['ville'] ?? ''));
        $cp = trim((string) ($donnees['codePostal'] ?? ''));
        if ($rue !== '' && $ville !== '' && $cp !== '') {
            $profil->setAdresse([
                'rue' => $rue,
                'complement' => trim((string) ($donnees['complement'] ?? '')),
                'cp' => $cp,
                'ville' => $ville,
                'pays' => $etablissement->getPays(),
            ]);
        }

        $this->entityManager->persist($profil);

        // ── LES TAUX VIENNENT DU PAYS DE LA STRUCTURE, PLUS D'UNE CONSTANTE ─────────────────────
        //
        // Ici se trouvait `TAUX_TVA_FRANCE` : 20 / 10 / 5,5 / 2,1, libellés français, posés à tout
        // le monde. Son propre commentaire portait l'aveu — « France seulement. `Etablissement` ne
        // porte aucun pays […] c'est un préalable de modèle, pas un oubli d'ici ». Ce préalable est
        // levé : `Etablissement::pays` et `::fiscalTerritory` sont des colonnes réelles, et le
        // référentiel légal porte 62 taux sur 29 pays. Voir {@see VatRateSeeder} pour ce qui se
        // passe quand un pays n'y figure pas — on ne retombe PAS sur la France.
        $tauxNormal = $this->vatRateSeeder->seed($profil);

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

        // ── ET LE PARAMÉTRAGE DE FACTURATION, QUI VIENT EN DERNIER PARCE QU'IL LIT CE QUI PRÉCÈDE ─
        //
        // Sans lui, une structure ouverte n'avait AUCUN paramétrage de facturation : le lookup rend
        // `null`, chaque lecture retombe sur `?->`, et rien ne le signale jusqu'à la première
        // facture — c'est ce qui a bloqué le rattrapage de cinq échéances le 14/09.
        //
        // ⚠ CE FLUSH N'EST PAS DÉCORATIF, ET LE RETIRER NE CASSERAIT AUCUN TEST DE L'OUVERTURE.
        //
        // `BillingSettingsSeeder` résout le compte de produit par une REQUÊTE, et une requête ne
        // voit pas les comptes que `chartSeeder` vient de `persist()`. Sans ce flush, le défaut
        // resterait `null` en silence, et la première facture serait refusée pour « compte de
        // produit indéterminable » — le même genre de trou, un cran plus loin.
        //
        // Il n'ouvre aucune fenêtre : il écrit exactement ce que le `flush()` de `ouvrir()` allait
        // écrire trois lignes plus bas, au même endroit de la même transaction.
        $this->entityManager->flush();
        $this->billingSettingsSeeder->seed($profil, $tauxNormal);
    }

    /**
     * LE PAYS ET LE TERRITOIRE FISCAL, DEMANDÉS À L'OUVERTURE.
     *
     * ⚠ SANS EUX, LE RESTE DE CE FICHIER EST INATTEIGNABLE. `VatRateSeeder` interroge le référentiel
     * légal sur le pays de l'établissement — mais l'ouverture ne posait aucun pays, donc la colonne
     * gardait son défaut `FR` et le semeur ne voyait jamais qu'un seul pays. Un code correct que
     * rien ne peut atteindre a exactement la même valeur qu'un code faux, et il est plus difficile
     * à repérer : les tests passent, la revue passe, et le premier client belge découvre le trou.
     *
     * ⚠ ILS RESTENT FACULTATIFS, ET `FR` RESTE LE DÉFAUT (D66-ter). Le produit n'est commercialisé
     * qu'en France à ce jour : rendre le pays obligatoire ajouterait une question à tout le monde
     * pour servir un cas qui n'existe pas encore. C'est `ONB-1`, le tunnel de première connexion,
     * qui devra le DEMANDER — et sa fiche le dit déjà : « le tunnel doit demander le PAYS avant les
     * taux ».
     *
     * ⚠ UN PAYS MAL FORMÉ EST REFUSÉ, PAS CORRIGÉ. L'entité porte bien un `Assert\Regex`, mais elle
     * est persistée à la main : aucun validateur ne tourne sur ce chemin. Sans ce refus, `« France »`
     * serait stocké tel quel, `inForce()` ne trouverait rien, et la structure s'ouvrirait sans aucun
     * taux — silencieusement.
     *
     * @param array<string, mixed> $donnees
     */
    private function paysEtTerritoire(array $donnees, Etablissement $etablissement): void
    {
        $pays = strtoupper(trim((string) ($donnees['pays'] ?? '')));
        if ($pays !== '') {
            if (1 !== preg_match('/^[A-Z]{2}$/', $pays)) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Le pays s’écrit en code ISO à deux lettres (FR, BE, ES) : « %s » n’en est pas un.',
                    $pays,
                ));
            }
            $etablissement->setPays($pays);
        }

        $territoire = strtoupper(trim((string) ($donnees['territoireFiscal'] ?? '')));
        if ($territoire !== '') {
            if (1 !== preg_match('/^[A-Z0-9-]{1,20}$/', $territoire)) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Le territoire fiscal s’écrit en majuscules, chiffres et tirets : « %s » n’en est pas un.',
                    $territoire,
                ));
            }
            $etablissement->setFiscalTerritory($territoire);
        }
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
