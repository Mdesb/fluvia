<?php

declare(strict_types=1);

namespace App\Calendar\Service;

/**
 * ÉCRIT UN FICHIER iCalendar (RFC 5545) — et les trois règles qui font qu'il est lu.
 *
 * ── 1. LES SÉPARATEURS SONT CRLF, PAS LF ────────────────────────────────────────────────────────
 *
 * La RFC l'impose. Apple Calendar et Outlook refusent purement et simplement un fichier en LF, et
 * le message d'erreur qu'ils affichent ne parle jamais de fin de ligne — « impossible d'ajouter cet
 * agenda », et c'est tout. Le défaut est invisible en test si l'on compare des chaînes PHP.
 *
 * ── 2. LE TEXTE EST ÉCHAPPÉ ─────────────────────────────────────────────────────────────────────
 *
 * Une virgule, un point-virgule ou une barre oblique inverse non échappés dans un `SUMMARY` coupent
 * la propriété en deux : « Réunion, salle B » devient un événement nommé « Réunion » et un
 * paramètre inconnu. Un saut de ligne dans une note casse le fichier entier — il devient `\n`.
 *
 * ── 3. UNE LIGNE NE DÉPASSE PAS 75 OCTETS ───────────────────────────────────────────────────────
 *
 * RFC 5545 §3.1. Au-delà, la ligne se PLIE : on coupe, et la suite commence par une espace, qu'un
 * lecteur retire en recollant. Google Agenda accepte les lignes longues, Apple aussi la plupart du
 * temps ; ce sont les agrégateurs et les Outlook anciens qui TRONQUENT — silencieusement, à 75
 * octets. Le défaut n'est donc pas un abonnement en erreur, c'est un titre coupé au milieu d'un mot
 * chez certains utilisateurs seulement, sans que personne ne fasse le lien avec sa longueur.
 *
 * On compte des OCTETS — un accent en occupe deux, et compter les caractères laisserait passer des
 * lignes trop longues dans exactement les textes français où le défaut se produit. Mais on plie
 * ENTRE des caractères : un pli au milieu d'un « é » produirait un fichier qui n'est plus de
 * l'UTF-8 valide, et un lecteur qui refuse l'agenda entier plutôt qu'un événement.
 *
 * ── 4. LE `UID` EST STABLE ──────────────────────────────────────────────────────────────────────
 *
 * C'est lui qui fait la différence entre « l'événement a été déplacé » et « un nouvel événement est
 * apparu et l'ancien a disparu ». Un UID tiré au hasard à chaque publication ferait sonner tous les
 * rappels à chaque relève horaire. Il est donc dérivé de l'identifiant de la source.
 */
final readonly class IcsWriter
{
    /**
     * @param list<array<string, mixed>> $evenements
     */
    public function rediger(array $evenements, string $nomEtablissement): string
    {
        $lignes = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // LE NOM DU PRODUIT, PAS CELUI DU DÉPÔT. Cette ligne et l'`UID` ci-dessous sont les deux
            // seules chaînes de ce module qui SORTENT chez le client : elles s'affichent dans
            // Google Agenda, Apple Calendar et Outlook. « Billetterie » y aurait nommé un
            // dépôt que personne d'autre que nous ne connaît.
            'PRODID:-//Fluvia//Agenda//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->echapper($nomEtablissement),
        ];

        $vus = [];
        foreach ($evenements as $evenement) {
            $id = (string) ($evenement['id'] ?? '');
            // Les deux portées sont fusionnées en amont ; un événement du site présent dans les
            // deux listes ne doit pas être écrit deux fois — un doublon dans un ICS crée deux
            // entrées visuelles que l'utilisateur devra supprimer une à une.
            if ($id === '' || isset($vus[$id])) {
                continue;
            }
            $vus[$id] = true;

            $debut = $this->instant((string) ($evenement['start'] ?? ''));
            $fin = $this->instant((string) ($evenement['end'] ?? ''));
            if ($debut === null || $fin === null) {
                continue;
            }

            $lignes[] = 'BEGIN:VEVENT';
            $lignes[] = 'UID:' . $id . '@fluvia';
            $lignes[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lignes[] = 'DTSTART:' . $debut;
            $lignes[] = 'DTEND:' . $fin;
            $lignes[] = 'SUMMARY:' . $this->echapper((string) ($evenement['title'] ?? 'Événement'));
            $detail = $evenement['detail'] ?? null;
            if (\is_string($detail) && trim($detail) !== '') {
                $lignes[] = 'DESCRIPTION:' . $this->echapper($detail);
            }
            $lignes[] = 'CATEGORIES:' . $this->echapper(strtoupper((string) ($evenement['type'] ?? 'AUTRE')));
            $lignes[] = 'END:VEVENT';
        }

        $lignes[] = 'END:VCALENDAR';

        // CRLF, et une fin de fichier qui en porte un : voir la règle n°1. Chaque ligne est pliée
        // avant d'être jointe — voir la règle n°3.
        return implode("\r\n", array_map([$this, 'plier'], $lignes)) . "\r\n";
    }

    /** Un instant ATOM devient un horodatage UTC `YmdTHisZ`, la seule forme qu'aucun client ne discute. */
    private function instant(string $atom): ?string
    {
        if ($atom === '') {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($atom);
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * PLIE UNE LIGNE À 75 OCTETS (RFC 5545 §3.1) — la suite commence par une espace.
     *
     * ⚠ L'ESPACE DE CONTINUATION COMPTE DANS LES 75. C'est l'erreur classique : plier à 75 puis
     * ajouter l'espace donne 76, et l'on croit avoir corrigé le défaut.
     *
     * ⚠ ON N'UTILISE PAS `str_split`, QUI COUPE PAR OCTETS. Il trancherait un « é » en deux et
     * produirait un fichier qui n'est plus de l'UTF-8 valide — un lecteur refuse alors l'agenda
     * ENTIER, pas seulement l'événement fautif. On avance donc caractère par caractère en mesurant
     * leur poids en octets : la limite est en octets, la coupure est entre caractères.
     */
    private function plier(string $ligne): string
    {
        if (\strlen($ligne) <= 75) {
            return $ligne;
        }

        $caracteres = preg_split('//u', $ligne, -1, \PREG_SPLIT_NO_EMPTY);
        if (!\is_array($caracteres)) {
            return $ligne; // Chaîne non UTF-8 : on ne la découpe pas à l'aveugle.
        }

        $morceaux = [];
        $courant = '';
        foreach ($caracteres as $caractere) {
            if (\strlen($courant) + \strlen($caractere) > 75) {
                $morceaux[] = $courant;
                $courant = ' ' . $caractere; // L'espace est déjà dans le budget de la ligne suivante.

                continue;
            }
            $courant .= $caractere;
        }
        $morceaux[] = $courant;

        return implode("\r\n", $morceaux);
    }

    /**
     * ORDRE IMPOSÉ : la barre oblique inverse EN PREMIER. L'échapper après les virgules
     * ré-échapperait les barres qu'on vient d'écrire, et « a,b » sortirait « a\\,b ».
     */
    private function echapper(string $texte): string
    {
        $texte = str_replace('\\', '\\\\', $texte);
        $texte = str_replace([',', ';'], ['\\,', '\\;'], $texte);

        return str_replace(["\r\n", "\n", "\r"], '\\n', $texte);
    }
}
