<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Unit;

use App\Crm\Entity\Client;
use App\Crm\Recouvrement\ClientDebtorName;
use App\Recouvrement\Port\DebtorNamePort;
use App\Recouvrement\Service\DebtorNameRegistry;
use PHPUnit\Framework\TestCase;

/**
 * LE NOM DE CELUI QUI DOIT — et surtout, ce que le registre REFUSE d'inventer.
 *
 * L'écran Recouvrement affichait `sport.abonnement_fitness` suivi de douze caractères d'UUID sous un
 * en-tête qui nomme une personne. Ces témoins portent sur les deux décisions qui rendent le nom
 * utile : lequel des deux noms d'un client on retient, et ce qui arrive quand personne ne sait.
 */
final class DebtorNameTest extends TestCase
{
    /**
     * ⚠ LA PERSONNE MORALE D'ABORD. Un client peut porter une raison sociale ET un nom de contact ;
     * la facture et le mandat sont au nom de la société. Rendre « Jean Dupont » là où le prélèvement
     * dit « SARL Dupont » enverrait chercher la mauvaise ligne au relevé bancaire.
     */
    public function testLaRaisonSocialePrimeSurLeNomDeContact(): void
    {
        $client = (new Client())->setRaisonSociale('SARL Dupont')->setNom('Dupont')->setPrenom('Jean');

        self::assertSame('SARL Dupont', ClientDebtorName::nameOf($client));
    }

    public function testUnParticulierEstNommePrenomPuisNom(): void
    {
        $client = (new Client())->setNom('Dupont')->setPrenom('Jean');

        self::assertSame('Jean Dupont', ClientDebtorName::nameOf($client));
    }

    /**
     * ⚠ `null`, PAS UNE CHAÎNE VIDE NI UN LIBELLÉ INVENTÉ. Une fiche sans nom est une fiche à
     * compléter ; l'écran doit pouvoir le dire, et ne le pourrait pas si on rendait ''.
     */
    public function testUnClientSansAucunNomRendNull(): void
    {
        self::assertNull(ClientDebtorName::nameOf(new Client()));
    }

    /** Les espaces seuls ne font pas un nom. */
    public function testUnNomFaitDEspacesNeComptePas(): void
    {
        $client = (new Client())->setRaisonSociale('   ')->setNom('  ')->setPrenom(' ');

        self::assertNull(ClientDebtorName::nameOf($client));
    }

    public function testLeRegistreInterrogeLePortDuBonType(): void
    {
        $registre = new DebtorNameRegistry([
            $this->port('autre.type', 'Mauvaise réponse'),
            $this->port('crm.client', 'Jean Dupont'),
        ]);

        self::assertSame('Jean Dupont', $registre->nameFor('crm.client', 'peu-importe'));
    }

    /**
     * ⚠ CE QUE LE REGISTRE ÉPARGNE, ET C'EST LE TÉMOIN QUI COMPTE. Un type sans port rend `null` —
     * un état normal, pas une panne : ce sera vrai chaque fois qu'une verticale s'ajoute avant son
     * port. Rendre un libellé de secours ferait croire à une fiche incomplète là où c'est le pont
     * qui manque, et personne n'irait chercher le pont.
     */
    public function testUnTypeSansPortRendNullPlutotQueDInventer(): void
    {
        $registre = new DebtorNameRegistry([$this->port('crm.client', 'Jean Dupont')]);

        self::assertNull($registre->nameFor('verticale.pas.encore.branchee', 'peu-importe'));
    }

    public function testUnRegistreVideRendNull(): void
    {
        self::assertNull((new DebtorNameRegistry([]))->nameFor('crm.client', 'peu-importe'));
    }

    private function port(string $type, ?string $nom): DebtorNamePort
    {
        return new class($type, $nom) implements DebtorNamePort {
            public function __construct(private string $type, private ?string $nom)
            {
            }

            public function debtorType(): string
            {
                return $this->type;
            }

            public function debtorName(string $debtorReference): ?string
            {
                return $this->nom;
            }
        };
    }
}
