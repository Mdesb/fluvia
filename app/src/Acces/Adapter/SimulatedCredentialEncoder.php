<?php

declare(strict_types=1);

namespace App\Acces\Adapter;

use App\Acces\Dto\ResultatCommande;
use App\Acces\Enum\CredentialEncoding;
use App\Acces\Hardware\CredentialFormat;
use App\Acces\Hardware\CredentialRead;
use App\Acces\Hardware\EncodingPayload;
use App\Acces\Port\CredentialEncoder;

/**
 * Poste d'encodage logiciel (#337) : un support « posé » en mémoire par `place()`, lu par `read()`,
 * et sur lequel `write()` consigne ce qui aurait été écrit. Même rôle que `SimulateurAccesAdapter` :
 * éprouver le parcours du comptoir sans matériel, jusqu'à ce que le modèle d'encodeur soit connu.
 *
 * Il déclare `Writes` pour que le chemin d'écriture soit exercé ; `refuseWrites()` le fait se
 * comporter comme un lecteur seul, pour éprouver l'autre chemin.
 */
final class SimulatedCredentialEncoder implements CredentialEncoder
{
    /** @var array<string, CredentialRead> */
    private array $placed = [];
    /** @var array<string, EncodingPayload> écrit, par identifiant brut */
    private array $written = [];
    private bool $writes = true;

    public function encoding(): CredentialEncoding
    {
        return $this->writes ? CredentialEncoding::Writes : CredentialEncoding::None;
    }

    public function read(string $station): ?CredentialRead
    {
        return $this->placed[$station] ?? null;
    }

    public function write(string $station, EncodingPayload $payload): ResultatCommande
    {
        if (!$this->writes) {
            return ResultatCommande::echec('Ce poste lit les badges mais n\'écrit pas.');
        }
        $read = $this->placed[$station] ?? null;
        if ($read === null) {
            return ResultatCommande::echec('Aucun support posé sur le poste.');
        }
        $this->written[$read->rawIdentifier] = $payload;

        return ResultatCommande::ok('Autorisation écrite (simulée).');
    }

    /** Pose un support sur un poste, comme un agent pose un bracelet sur l'encodeur. */
    public function place(string $station, string $rawIdentifier, CredentialFormat $format = CredentialFormat::HexMsbFirst): void
    {
        $this->placed[$station] = new CredentialRead($rawIdentifier, $format);
    }

    public function clear(string $station): void
    {
        unset($this->placed[$station]);
    }

    public function refuseWrites(): void
    {
        $this->writes = false;
    }

    /** @return array<string, EncodingPayload> */
    public function written(): array
    {
        return $this->written;
    }
}
