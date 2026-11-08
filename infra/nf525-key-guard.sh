# shellcheck shell=bash
#
# #361 — UNE CLÉ NF525 NE SE GÉNÈRE JAMAIS PAR-DESSUS UNE CHAÎNE EXISTANTE.
#
# Sourcé par deploy-preprod.sh et deploy-prod.sh, AVANT la boucle qui génère les clés absentes.
#
# ── LE DÉFAUT QUE CE FICHIER FERME ──────────────────────────────────────────────────────────────
#
# Les deux scripts génèrent toute clé « absente ou vide ». Pour une clé de chiffrement d'une
# installation neuve, c'est juste. Pour une clé de SCELLEMENT NF525, c'est une rotation silencieuse :
# un `.env.prod` perdu, recréé ou tronqué, une sauvegarde de base restaurée sans lui (la sauvegarde ne
# l'emporte pas), et la clé neuve signe par-dessus une chaîne que plus rien ne vérifie. C'est arrivé
# deux fois en préprod (#361) : 14 maillons invérifiables, et aucun message.
#
# ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
#
# Une clé NF525 absente n'est générée QUE si l'on a MESURÉ qu'aucun maillon de sa chaîne n'existe :
#   - base démarrée : on compte les maillons, en lecture seule. 0 → on génère ; sinon on s'arrête ;
#   - base arrêtée mais son volume existe : on ne peut pas mesurer, donc on s'arrête (échec fermé) ;
#   - ni base ni volume : installation neuve, on génère.
# Une mesure qui échoue vaut un refus, jamais un zéro.
#
# Aucun secret n'est lu ici : la requête tourne DANS le conteneur de base, avec son propre mot de
# passe (variable d'environnement du conteneur), et ne rend qu'un nombre.
#
# Paramètre attendu : COMPOSE_PROJECT (le `name:` du fichier compose).

# Tables (et filtre) qui portent les maillons de la chaîne signée par chaque clé.
nf525_tables_for() {
    case "$1" in
        NF525_SEAL_KEY)             echo "nf525_operation_scellee nf525_fiscal_archive" ;;
        NF525_COMPTA_SEAL_KEY)      echo "compta_ecriture_comptable" ;;
        NF525_FACTURATION_SEAL_KEY) echo "facturation_facture" ;;
        *)                          echo "" ;;
    esac
}

# Exécute une requête dans le conteneur de base, rend la sortie brute (lecture seule).
nf525_db_query() { # $1 = id du conteneur, $2 = SQL
    docker exec -e "NF525_GUARD_SQL=$2" "$1" sh -c \
        'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -N -B "$MARIADB_DATABASE" -e "$NF525_GUARD_SQL"'
}

# Nombre de maillons de la chaîne d'une clé ; code de sortie non nul si la mesure est impossible.
nf525_count_links() { # $1 = id du conteneur, $2 = nom de la clé
    local cid="$1" total=0 table exists n where
    for table in $(nf525_tables_for "$2"); do
        exists="$(nf525_db_query "$cid" "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table'")" || return 1
        [ "$exists" = "1" ] || continue
        where=""
        case "$table" in
            compta_ecriture_comptable|facturation_facture) where=" WHERE numero_sequence > 0" ;;
        esac
        n="$(nf525_db_query "$cid" "SELECT COUNT(*) FROM $table$where")" || return 1
        case "$n" in ''|*[!0-9]*) return 1 ;; esac
        total=$((total + n))
    done
    echo "$total"
}

nf525_refuse() { # $1 = nom de la clé, $2 = raison
    echo
    echo "✗ Déploiement arrêté : la clé $1 est absente ou vide, et elle ne sera PAS générée."
    echo "    $2"
    echo
    echo "  Une clé neuve signerait par-dessus une chaîne NF525 qu'elle ne peut pas vérifier : les"
    echo "  maillons existants deviendraient invérifiables (#361). Le script ne choisit pas à votre place."
    echo
    echo "  → restaurez $1 depuis la sauvegarde hors serveur du trousseau,"
    echo "    dans le fichier d'environnement de cette installation, puis relancez ;"
    echo "  → procédure : infra/README.md, section « Trousseau NF525 »."
    exit 1
}

# Autorise (code 0) la génération de la clé $1, ou arrête le déploiement avec un message.
nf525_guard_key_generation() { # $1 = nom de la clé
    local key="$1" cid volume count
    [ -n "$(nf525_tables_for "$key")" ] || return 0   # pas une clé NF525 : rien à garder

    cid="$(docker ps -q \
        --filter "label=com.docker.compose.project=$COMPOSE_PROJECT" \
        --filter "label=com.docker.compose.service=db" | head -n 1)"

    if [ -n "$cid" ]; then
        if ! count="$(nf525_count_links "$cid" "$key")"; then
            nf525_refuse "$key" "La base est démarrée mais le nombre de maillons n'a pas pu être mesuré."
        fi
        if [ "$count" != "0" ]; then
            nf525_refuse "$key" "La base contient déjà $count maillon(s) de la chaîne signée par cette clé."
        fi
        echo "  · $key : 0 maillon en base, génération autorisée."
        return 0
    fi

    volume="$(docker volume ls -q \
        --filter "label=com.docker.compose.project=$COMPOSE_PROJECT" \
        --filter "label=com.docker.compose.volume=db_data" | head -n 1)"
    if [ -n "$volume" ]; then
        nf525_refuse "$key" "La base est arrêtée mais son volume ($volume) existe : impossible de compter les maillons. Démarrez le conteneur de base existant (docker start <son nom>) puis relancez."
    fi

    echo "  · $key : ni base ni volume, installation neuve, génération autorisée."
    return 0
}
