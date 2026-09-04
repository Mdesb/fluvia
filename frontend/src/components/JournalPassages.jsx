import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { jourLocal } from './Liste.jsx'
// Les mots du controle d'acces, partages avec la supervision et la topologie : le journal les
// citait depuis la portee de son ancien fichier.
import { MOTIF_REFUS, RESULTAT_CLS, RESULTAT_PASSAGE, SENS_PASSAGE } from '../api/acces.js'
import { idDe } from '../api/iri'

// ⚠ ONZIEME COPIE DE `idDe` DANS LE FRONTAL, ET C'EST DELIBERE DE NE PAS LA PARTAGER ICI.
//
// Dix fonctions du meme nom (ou presque : `idDeClient`, `idDepuisIri`, `idDe`) vivent deja dans
// dix fichiers, avec des corps qui ne sont pas identiques. Les fondre demanderait de prouver
// l'egalite de la SORTIE des dix, une par une — c'est un chantier a soi seul, pas un detour dans
// une extraction d'ecran. La duplication est SIGNALEE plutot que reproduite en silence.

// L'identifiant d'une relation, qu'elle arrive en objet (`{ '@id', id, … }`) ou en IRI nue
// (`/api/espace_acces/…`). Les deux formes cohabitent DANS LA MÊME RÉPONSE selon les groupes de
// sérialisation : `Controleur.espace` est un objet dans `controleur:read`, mais la même relation vue
// depuis un équipement n'est qu'une IRI, parce que `EspaceAcces` ne déclare rien dans
// `equipement:read`. Comparer des `id` plutôt que des formes, c'est ce qui rend ce croisement sûr.

// La date et l'heure d'un passage, dans le fuseau de celui qui regarde. Copiee de
// `TopologieAcces.jsx`, qui s'en sert encore pour ses propres colonnes.
function horodate(v) {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString('fr-FR')
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// JOURNAL DES PASSAGES (A-05) — ce que le contrôle d'accès a fait, et pourquoi.
//
// La supervision montre les vingt derniers passages, sans filtre et sans mémoire. Le serveur, lui,
// porte des filtres (espace, contrôleur, équipement, résultat, période) et une opération d'export
// que personne n'atteignait. Un refus se comprend en regardant les vingt qui l'entourent, pas
// l'instant.
export default function JournalPassages({ etabActif, cible }) {
  // ⚠ LE JOURNAL CHARGE SES PROPRES REFERENTIELS DEPUIS QU'IL A QUITTE LA TOPOLOGIE.
  //
  // Les deux listes ne servent qu'a remplir les menus deroulants des filtres et a nommer les
  // colonnes de l'export. Elles arrivaient en props de `TopologieAcces`, qui les chargeait pour
  // son plan du site ; l'ecran n'existe plus autour, donc il les demande lui-meme. Deux appels,
  // une seule fois, et un echec n'empeche pas de lire les passages : les filtres se contentent
  // alors de rester vides.
  const [espaces, setEspaces] = useState([])
  const [equipements, setEquipements] = useState([])

  useEffect(() => {
    let vivant = true
    Promise.all([api.espacesAcces(), api.equipementsAcces()])
      .then(([e, q]) => {
        if (!vivant) return
        setEspaces(membres(e))
        setEquipements(membres(q))
      })
      .catch(() => {})
    return () => { vivant = false }
  }, [etabActif])

  const [filtres, setFiltres] = useState({ depuis: '', jusqua: '', espace: '', equipement: '', resultat: '', billet: '' })
  const [lignes, setLignes] = useState([])
  const [total, setTotal] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState(null)
  const [exportEnCours, setExportEnCours] = useState(false)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const query = { itemsPerPage: 100, 'order[horodatage]': 'desc' }
      if (filtres.depuis) query['horodatage[after]'] = filtres.depuis
      if (filtres.jusqua) query['horodatage[before]'] = `${filtres.jusqua}T23:59:59`
      if (filtres.espace) query.espace = filtres.espace
      if (filtres.equipement) query.equipement = filtres.equipement
      if (filtres.resultat) query.resultat = filtres.resultat
      // ⚠ RECHERCHE PAR NUMÉRO DE BILLET : LE FILTRE EST `exact`, PAS UNE RECHERCHE PARTIELLE.
      //
      // `#[ApiFilter(SearchFilter::class, properties: ['support.identifiant' => 'exact'])]` — un
      // numéro tronqué ne rend donc RIEN, et rien se lit « ce billet n'est jamais passé », qui est
      // la pire réponse possible à un client qui affirme le contraire. D'où le libellé du champ et
      // la phrase du résultat vide.
      if (filtres.billet.trim()) query['support.identifiant'] = filtres.billet.trim()
      // ⚠ LE TRI EST DEMANDÉ AU SERVEUR, ET IL SAIT LE FAIRE DEPUIS LE 29/08.
      //
      // Ce fichier a longtemps trié ce qu'il recevait, faute de pouvoir choisir ce qu'il recevait :
      // `Passage` ne déclarait aucun `OrderFilter`, donc `order[horodatage]=desc` était ignoré en
      // silence et la pagination rendait les plus ANCIENS. Le filtre a été ajouté le 29/08 —
      // et pendant cinq jours cet écran a continué de trier localement en l'annonçant à
      // l'exploitant.
      //
      // La requête demandait DÉJÀ ce tri (voir `query` plus haut) : seul le tri local était de
      // trop. Vérifié par HTTP avant de le retirer — `desc` rend le plus récent d'abord, `asc` le
      // plus ancien. Le serveur trie vraiment ; ce n'est pas déduit de la présence de l'attribut.
      const reponse = await api.journalPassages(query)
      setLignes(membres(reponse))
      setTotal(reponse?.totalItems ?? reponse?.['hydra:totalItems'] ?? null)
    } catch (e) {
      setErreur(e.message || 'Journal indisponible.')
      setLignes([])
      setTotal(null)
    } finally {
      setChargement(false)
    }
  }, [filtres])

  // Une cible venue du plan ou de la liste des lecteurs pose le filtre correspondant. Elle ne
  // remplace pas les filtres de période déjà saisis : on vient voir CE lecteur-là sur la fenêtre
  // qu'on regardait, pas repartir de zéro.
  useEffect(() => {
    if (!cible) return
    setFiltres((f) => ({ ...f, espace: cible.espace || '', equipement: cible.equipement || '' }))
  }, [cible])

  useEffect(() => {
    charger()
  }, [etabActif, charger])

  async function exporter() {
    setExportEnCours(true)
    setErreur(null)
    setInfo(null)
    try {
      // L'export a SES PROPRES NOMS DE PARAMÈTRES (`depuis`/`jusqua`), différents de ceux de la
      // collection filtrée (`horodatage[after]`/`[before]`) : c'est un provider écrit à la main, pas
      // le filtre standard. Réutiliser les noms de l'écran de liste rendrait un export non filtré
      // qui a l'air filtré.
      const query = {}
      if (filtres.depuis) query.depuis = filtres.depuis
      if (filtres.jusqua) query.jusqua = `${filtres.jusqua} 23:59:59`
      if (filtres.espace) query.espace = filtres.espace
      if (filtres.equipement) query.equipement = filtres.equipement
      if (filtres.resultat) query.resultat = filtres.resultat
      const reponse = await api.exportPassages(query)
      const tout = membres(reponse)

      // ⚠ CE FILTRE N'EST PLUS UN PANSEMENT, C'EST UN TÉMOIN DE RÉGRESSION — ET LA DIFFÉRENCE A
      // UNE DATE.
      //
      // Il a été écrit le 28/08 parce que `PassageExportProvider` construisait son propre
      // QueryBuilder : le cloisonnement (`PerimetreAccesExtension`) ne s'applique qu'aux collections
      // servies par le provider standard, donc l'export rendait les passages de TOUS les sites. La
      // borne serveur est arrivée le 29/08 (`b2b5acc`) ; ce provider porte désormais son
      // `IDENTITY(p.etablissement) = :export_etablissement`.
      //
      // Le filtre écarte donc zéro ligne à chaque appel, et un contournement mort se fait retirer
      // par le prochain lecteur qui croit nettoyer — ou garder sans que personne ne sache pourquoi.
      // On le garde pour une raison qui, elle, vaut au présent : **il mesure que la borne tient.**
      // Si `ecartes` repasse au-dessus de zéro, c'est que quelqu'un a défait `b2b5acc`, et cet écran
      // est le seul endroit du produit qui le verrait.
      //
      // ⚠ ET LE ZÉRO DOIT ÊTRE UN ZÉRO MESURÉ, PAS UN ZÉRO PAR CONSTRUCTION. La version précédente
      // écrivait `etabActif ? filtrer : tout` : sans établissement actif, elle ne comparait rien et
      // rendait « 0 écartée », c'est-à-dire un satisfecit obtenu en ne regardant pas. Même chose si
      // le serveur cesse d'exposer `etablissement` sur les lignes. On exige donc de pouvoir
      // comparer, et on distingue « rien à signaler » de « je n'ai pas pu vérifier ».
      const peutComparer =
        Boolean(etabActif) && tout.length > 0 && tout.every((p) => idDe(p.etablissement))
      const aNous = peutComparer ? tout.filter((p) => idDe(p.etablissement) === etabActif) : tout
      const ecartes = peutComparer ? tout.length - aNous.length : null

      // Le provider d'export ne connaît PAS le numéro de billet : il lit `depuis`, `jusqua`,
      // `espace`, `equipement`, `resultat`, et rien d'autre. Envoyer le filtre du journal produirait
      // un fichier de TOUS les passages sous un nom qui promet un billet précis. On coupe donc ici,
      // sur la donnée déjà reçue.
      const filtrees = filtres.billet.trim()
        ? aNous.filter((p) => p.support?.identifiant === filtres.billet.trim())
        : aNous

      if (filtrees.length === 0) {
        setInfo('Aucun passage à exporter pour ces filtres.')
        return
      }
      telechargerCsv(filtrees, espaces, equipements)
      setInfo(
        ecartes === null
          ? `${filtrees.length} passage(s) exportés. Le cloisonnement de l’export n’a PAS pu être vérifié ici (établissement actif ou champ « etablissement » absent des lignes) : ce n’est pas un satisfecit.`
          : ecartes > 0
          ? `⚠ ${filtrees.length} passage(s) exportés, mais ${ecartes} ligne(s) rendues par le serveur appartiennent à un AUTRE établissement et ont été écartées ici. La borne de cloisonnement de l’export a régressé côté serveur — signalez-le, le fichier remis serait autrement celui du site voisin.`
          : `${filtrees.length} passage(s) exportés.`,
      )
    } catch (e) {
      setErreur(e.message || "L'export n'a pas abouti.")
    } finally {
      setExportEnCours(false)
    }
  }

  const majFiltre = (nom) => (e) => setFiltres((s) => ({ ...s, [nom]: e.target.value }))

  return (
    <section className="card">
      <div className="card-h">
        <h3>Journal des passages</h3>
        {total !== null && lignes.length < total && (
          <span
            className="badge warn"
            title="Cette liste est tronquée par la pagination : ce sont les plus récentes des passages qui répondent à ces filtres."
          >
            {lignes.length} sur {total}
          </span>
        )}
        <div className="r" style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
          <button title="Actualiser" className="btn ghost sm" onClick={charger} disabled={chargement}>↻</button>
          <button className="btn sm" onClick={exporter} disabled={exportEnCours}>
            {exportEnCours ? 'Export…' : '⤓ Exporter (CSV)'}
          </button>
        </div>
      </div>
      <div className="card-b">
        <p className="hint" style={{ marginTop: 0 }}>
          Le journal est en lecture seule : un passage ne se corrige pas, il se relit. L’export porte
          sur <strong>tous</strong> les passages qui répondent aux filtres, pas seulement sur les
          lignes affichées.
        </p>

        <div className="grid g4" style={{ gap: 10, marginBottom: 12 }}>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-depuis">Du</label>
            <input id="j-depuis" className="input" type="date" value={filtres.depuis} onChange={majFiltre('depuis')} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-jusqua">Au</label>
            <input id="j-jusqua" className="input" type="date" value={filtres.jusqua} onChange={majFiltre('jusqua')} />
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-espace">Espace</label>
            <select id="j-espace" className="select" value={filtres.espace} onChange={majFiltre('espace')}>
              <option value="">Tous</option>
              {espaces.map((e) => (
                <option key={e.id} value={e.id}>{e.libelle}</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-resultat">Résultat</label>
            <select id="j-resultat" className="select" value={filtres.resultat} onChange={majFiltre('resultat')}>
              <option value="">Tous</option>
              {Object.entries(RESULTAT_PASSAGE).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label htmlFor="j-billet">N° de billet ou de badge (exact)</label>
            <input
              id="j-billet"
              className="input"
              value={filtres.billet}
              onChange={majFiltre('billet')}
              placeholder="Le numéro complet"
            />
          </div>
        </div>

        {/* UN FILTRE QU'ON NE VOIT PAS EST UN PIÈGE : sans cette étiquette, on lit « aucun passage »
            en croyant regarder tout le site alors qu'on ne regarde qu'un tourniquet. Le sélecteur
            d'espace est visible juste au-dessus ; celui d'équipement n'existe pas, d'où l'étiquette. */}
        {filtres.equipement && (
          <div className="row" style={{ gap: 8, marginBottom: 10, flexWrap: 'wrap' }}>
            <span className="badge mut">
              Lecteur : {equipements.find((q) => q.id === filtres.equipement)?.libelle || 'sélectionné'}
            </span>
            <button
              className="btn ghost sm"
              type="button"
              onClick={() => setFiltres((f) => ({ ...f, equipement: '' }))}
            >
              Retirer ce filtre
            </button>
          </div>
        )}

        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}
        {total !== null && lignes.length < total && (
          <div className="banner" style={{ background: 'var(--warn-bg)', color: 'var(--warn)' }}>
            Cette liste est tronquée par la pagination : ce sont les{' '}
            <strong>{lignes.length} plus récentes</strong> des {total} qui répondent à ces filtres.
            Restreignez la période pour voir le reste — l’export, lui, porte bien sur la totalité.
          </div>
        )}

        {chargement ? (
          <div className="center" style={{ minHeight: 140 }}><div className="spinner" /></div>
        ) : lignes.length === 0 ? (
          <div className="empty" style={{ padding: 18 }}>
            {filtres.billet.trim() ? (
              <>
                Aucun passage pour le numéro « {filtres.billet.trim()} ». La recherche porte sur le
                numéro <strong>complet</strong> : un numéro tronqué ou approché ne rend rien, ce qui
                ne veut pas dire que ce billet n’est jamais passé. Vérifiez le numéro avant de
                répondre au client.
              </>
            ) : filtres.depuis || filtres.jusqua || filtres.espace || filtres.resultat || filtres.equipement ? (
              'Aucun passage ne répond à ces filtres.'
            ) : (
              'Aucun passage enregistré sur ce site. Le journal se remplit tout seul dès qu’un équipement lit un support.'
            )}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Horodatage</th>
                  <th>Espace</th>
                  <th>Équipement</th>
                  <th>Sens</th>
                  <th>Résultat</th>
                  <th>Support</th>
                  <th>Pourquoi</th>
                </tr>
              </thead>
              <tbody>
                {lignes.map((p) => (
                  <tr key={p.id}>
                    <td>{horodate(p.horodatage)}</td>
                    <td>{p.espace?.libelle || 'Contrôlé à la main'}</td>
                    <td>
                      {p.equipement?.libelle || <span className="mut">sans équipement</span>}
                      {p.controleur?.libelle ? <span className="mut"> · {p.controleur.libelle}</span> : null}
                    </td>
                    <td>{SENS_PASSAGE[p.sens] || p.sens}</td>
                    <td>
                      <span className={`badge ${RESULTAT_CLS[p.resultat] || 'mut'}`}>
                        {RESULTAT_PASSAGE[p.resultat] || p.resultat}
                      </span>
                      {p.origineHorsLigne && <span className="badge mut" title="Enregistré hors ligne puis synchronisé">hors ligne</span>}
                      {p.enConflit && <span className="badge crit" title="Conflit détecté à la réconciliation">conflit</span>}
                    </td>
                    <td>{p.support?.identifiant || <span className="mut">non nominatif</span>}</td>
                    {/* `codeMotif` est le code machine, `motif` la phrase saisie ou calculée par le
                        moteur. On affiche LA PHRASE DE L'EXPLOITANT quand le code est connu, la
                        phrase du serveur en dessous, et le code brut en dernier recours : un code
                        qu'on n'a pas traduit ici vaut mieux affiché tel quel qu'escamoté. */}
                    <td>{cellulePourquoi(p)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  )
}

// Ce qu'on met dans la colonne « Pourquoi » d'un passage.
//
// Trois sources, dans cet ordre : la traduction de `codeMotif` (une phrase pour l'exploitant, plus
// le geste à faire), puis `motif` (la phrase du moteur, souvent plus précise sur le cas), puis le
// code brut si on ne le connaît pas encore. Un code non traduit s'affiche tel quel : le masquer
// ferait disparaître le seul indice d'un refus qu'on n'a pas prévu.
function cellulePourquoi(p) {
  const connu = p.codeMotif ? MOTIF_REFUS[p.codeMotif] : null
  if (!connu && !p.motif && !p.codeMotif) return '—'
  return (
    <>
      {connu && (
        <div title={connu.geste ? `${connu.quoi}\n\nQue faire : ${connu.geste}` : connu.quoi}>
          <strong>{connu.libelle}</strong>
        </div>
      )}
      {connu ? <div className="mut">{connu.quoi}</div> : null}
      {p.motif && p.motif !== connu?.quoi ? <div className="mut">{p.motif}</div> : null}
      {!connu && p.codeMotif ? <div className="mut">code : {p.codeMotif}</div> : null}
      {connu?.geste ? <div className="hint" style={{ margin: 0 }}>{connu.geste}</div> : null}
    </>
  )
}

// Le point-virgule et le BOM ne sont pas des détails : sans eux, le fichier s'ouvre en une seule
// colonne dans un tableur français et les accents sortent en mojibake — l'export a l'air cassé alors
// que la donnée est juste.
function telechargerCsv(passages, espaces, equipements) {
  const enTetes = ['Horodatage', 'Espace', 'Controleur', 'Equipement', 'Sens', 'Resultat', 'Support', 'Motif', 'Hors ligne', 'En conflit']
  const echappe = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const nomEspace = (p) => p.espace?.libelle || espaces.find((e) => e.id === idDe(p.espace))?.libelle || ''
  const nomEquipement = (p) => p.equipement?.libelle || equipements.find((e) => e.id === idDe(p.equipement))?.libelle || ''
  const lignes = passages.map((p) =>
    [
      horodate(p.horodatage),
      nomEspace(p),
      p.controleur?.libelle || '',
      nomEquipement(p),
      SENS_PASSAGE[p.sens] || p.sens || '',
      RESULTAT_PASSAGE[p.resultat] || p.resultat || '',
      p.support?.identifiant || '',
      // Le fichier porte la phrase de l'exploitant, pas le code machine : un CSV se relit loin de
      // l'application, par quelqu'un qui n'a pas la table sous les yeux.
      [MOTIF_REFUS[p.codeMotif]?.libelle, p.motif || (MOTIF_REFUS[p.codeMotif] ? '' : p.codeMotif)]
        .filter(Boolean)
        .join(' — '),
      p.origineHorsLigne ? 'oui' : 'non',
      p.enConflit ? 'oui' : 'non',
    ]
      .map(echappe)
      .join(';'),
  )
  const contenu = `﻿${enTetes.map(echappe).join(';')}\n${lignes.join('\n')}\n`
  const url = URL.createObjectURL(new Blob([contenu], { type: 'text/csv;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  // ⚠ `toISOString().slice(0, 10)` REND DE L'UTC, DONC LA VEILLE ENTRE MINUIT ET 2 H À PARIS L'ÉTÉ.
  // Sur un nom de fichier, ce n'est pas anodin : l'exploitant qui exporte à 00 h 30 obtient un
  // fichier daté de la veille, à côté de celui qu'il a peut-être déjà exporté ce jour-là. Deux
  // fichiers homonymes, ou un nom qui ne correspond pas aux lignes qu'il contient.
  // `jourLocal()` rend le jour de CELUI QUI REGARDE — garde-fou n°31.
  a.download = `passages-${jourLocal()}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
