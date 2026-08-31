import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, membres } from '../api/client.js'

// LE RÉFÉRENTIEL LÉGAL DES TAUX DE TVA — « on les propose tous automatiquement ».
//
// Demandé par Maxime : « les taux de TVA sont définis par les lois, un utilisateur n'a pas besoin de
// le créer ». Cet écran ne remplace pas la liste des taux de l'exploitant qui vit juste au-dessus :
// il l'alimente. On consulte la loi, on reprend ce dont on a l'usage, on masque le reste.
//
// ── POURQUOI L'ŒIL QUI MASQUE N'EST PAS UN CONFORT ─────────────────────────────────────────────
//
// Le référentiel européen complet fait des centaines d'entrées ; une piscine municipale française
// en emploie trois. Sans masquage, on aurait remplacé « il saisit ses trois taux » par « il cherche
// ses trois taux dans deux cents » — et le second est PIRE, parce qu'il a l'air complet. La mesure
// qui l'a montré : 31 taux saisis à la main existaient déjà en base pour trois établissements de
// démonstration. Masquer est ce qui empêche 31 de devenir 300.
//
// ⚠ MASQUER N'INVALIDE RIEN, ET L'ÉCRAN DOIT LE DIRE. Un taux masqué qui sert déjà à une facture
// continue de s'appliquer : le masque porte sur ce qu'on PROPOSE, jamais sur ce qui a servi. Si
// l'écran laissait croire l'inverse, quelqu'un masquerait un taux en croyant le retirer de ses
// ventes, et ne s'apercevrait de rien — les factures continueraient, silencieusement, à le porter.
//
// ── CE QUE L'ÉCRAN NE FAIT PAS, ET C'EST VOULU ─────────────────────────────────────────────────
//
// Aucune modification du référentiel : ni création, ni édition, ni suppression. Le serveur ne les
// expose pas. Un taux qui change par décret n'est pas une correction de saisie — c'est une nouvelle
// entrée datée, et l'ancienne reste pour expliquer les factures d'avant.

// Les pays proposés. Volontairement courte au départ : une liste de vingt-sept lignes dont
// vingt-six sont vides ferait croire à un référentiel incomplet plutôt qu'à un semis partiel.
// Elle s'étendra avec les données, pas avant.
const PAYS = [
  ['FR', 'France'],
  ['BE', 'Belgique'],
  ['LU', 'Luxembourg'],
  ['DE', 'Allemagne'],
  ['ES', 'Espagne'],
  ['IT', 'Italie'],
]

const CATEGORIES = {
  standard: 'Taux normal',
  reduced: 'Taux réduit',
  second_reduced: 'Second taux réduit',
  super_reduced: 'Taux super-réduit',
  parking: 'Taux parking',
  zero: 'Taux zéro',
  exempt: 'Exonération',
  out_of_scope: 'Hors champ',
}

// ⚠ TROIS CATÉGORIES RENDENT ZÉRO EURO ET NE SONT PAS LA MÊME CHOSE. Un taux zéro ouvre droit à
// déduction pour le vendeur, une exonération ne l'ouvre pas, et « hors champ » n'est pas un taux du
// tout. La différence ne se voit jamais sur la facture du client — elle se voit sur ce que
// l'exploitant peut récupérer. D'où cette phrase à l'écran plutôt qu'un simple « 0 % ».
const ZERO_EXPLIQUE = {
  zero: 'Zéro pour cent, avec droit à déduction.',
  exempt: 'Exonéré : pas de TVA facturée, et pas de droit à déduction.',
  out_of_scope: 'Hors du champ de la TVA — ce n’est pas un taux.',
}

function idDe(v) {
  if (!v) return ''
  if (typeof v === 'string') return v.split('/').pop()
  return v.id ? String(v.id) : String(v['@id'] || '').split('/').pop()
}

export default function TauxTvaLegaux({ peutModifier, version = 0, onRepris }) {
  // ⚠ LE COMPOSANT CHARGE LUI-MÊME CE QUE L'EXPLOITANT POSSÈDE, plutôt que de le recevoir.
  //
  // Le parent ne l'avait pas en état, et le lui faire porter aurait ajouté une lecture à un écran
  // qui en fait déjà six — pour une donnée dont ce bloc est le seul consommateur. `version` suffit
  // à le faire relire quand le parent sait que la liste a bougé.
  const [tauxExistants, setTauxExistants] = useState([])
  const [pays, setPays] = useState('FR')
  const [legaux, setLegaux] = useState([])
  const [masques, setMasques] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [nonBranche, setNonBranche] = useState(false)
  const [voirMasques, setVoirMasques] = useState(false)
  const [enCours, setEnCours] = useState('')
  const [info, setInfo] = useState('')

  const charger = useCallback(async () => {
    setChargement(true)
    try {
      // Une seule lecture : le serveur rend les taux applicables ET ce que cet exploitant a masqué.
      // Le croisement s'y fait, là où le profil comptable est connu — pas ici, où il faudrait le
      // deviner et le refaire dans chaque écran qui afficherait un jour la même liste.
      const vue = await api.catalogueTauxTva(pays)
      setLegaux(vue?.rates || [])
      setMasques(vue?.hidden || [])
      setNonBranche(false)
      setErreur(null)
    } catch (e) {
      // 404 : la route n'existe pas encore sur ce serveur. C'est un état distinct d'une erreur —
      // il n'appelle aucun geste de l'exploitant, seulement une phrase qui le rassure.
      if (e?.status === 404) setNonBranche(true)
      else setErreur(e?.message || 'Le référentiel légal n’a pas pu être lu.')
    } finally {
      setChargement(false)
    }
  }, [pays])

  useEffect(() => {
    charger()
  }, [charger])

  // Ce que l'exploitant a déjà repris. Lu à part du référentiel : si cette lecture échoue, on
  // affiche quand même la loi — on perd seulement le « Déjà repris », et une pastille manquante
  // vaut mieux qu'un écran vide.
  useEffect(() => {
    let vivant = true
    api
      .tauxTvas()
      .then((r) => { if (vivant) setTauxExistants(membres(r)) })
      .catch(() => { if (vivant) setTauxExistants([]) })
    return () => { vivant = false }
  }, [version])

  // Les identifiants des taux légaux que cet exploitant a masqués, et la ligne de masquage qui
  // permettra de le démasquer.
  // `hidden` est une simple liste d'identifiants de taux : demasquer se fait par le meme
  // identifiant que masquer, donc il n'y a aucune ligne de preference a retenir.
  const masquePar = useMemo(() => new Set(masques.map(String)), [masques])

  // Ce que l'exploitant possède déjà, pour ne pas lui proposer de reprendre deux fois le même taux.
  // On compare sur l'origine légale quand elle existe : deux taux à 20 % peuvent être deux choses
  // différentes (un normal français, un normal autrichien).
  const dejaRepris = useMemo(
    () => new Set(tauxExistants.map((t) => idDe(t.origineLegale)).filter(Boolean)),
    [tauxExistants],
  )

  const visibles = legaux.filter((t) => voirMasques || !masquePar.has(String(t.id)))
  const nbMasques = legaux.filter((t) => masquePar.has(String(t.id))).length

  async function basculerMasque(taux) {
    const id = String(taux.id)
    setEnCours(id)
    setInfo('')
    try {
      const vue = masquePar.has(id)
        ? await api.demasquerTauxLegal(id)
        : await api.masquerTauxLegal(id)
      setLegaux(vue?.rates || [])
      setMasques(vue?.hidden || [])
    } catch (e) {
      setErreur(e.message || 'Le masquage n’a pas abouti.')
    } finally {
      setEnCours('')
    }
  }

  async function reprendre(taux) {
    setEnCours(String(taux.id))
    setInfo('')
    try {
      // Un identifiant, rien d'autre. Le serveur lit le libellé, la valeur et le profil comptable à
      // la source — c'est ce qui garantit que le taux repris est exactement celui de la loi, et ce
      // qui a supprimé le refus « plusieurs profils comptables » que cet écran opposait avant.
      const vue = await api.reprendreTauxLegal(String(taux.id))
      setLegaux(vue?.rates || [])
      setMasques(vue?.hidden || [])
      setInfo(`« ${taux.label} » est ajouté à vos taux.`)
      if (onRepris) onRepris()
    } catch (e) {
      setErreur(e.message || 'La reprise de ce taux n’a pas abouti.')
    } finally {
      setEnCours('')
    }
  }


  if (nonBranche) {
    return (
      <div className="hint" style={{ margin: 0 }}>
        Le référentiel des taux légaux n’est pas encore ouvert par le serveur. Vos taux se saisissent
        pour l’instant à la main, ci-dessus.
      </div>
    )
  }

  return (
    <div className="card" style={{ marginTop: 'var(--esp-bloc)' }}>
      <div className="card-h">
        <h3>Taux proposés par la loi</h3>
        <span className="sub">
          Ce que la réglementation fixe. Reprenez ceux dont vous avez l’usage, masquez les autres.
        </span>
      </div>

      <div className="card-b">
        {erreur && <div className="banner banner-error">{erreur}</div>}
        {info && <div className="banner banner-ok">{info}</div>}

        <div className="row" style={{ gap: 'var(--esp-large)', alignItems: 'flex-end', marginBottom: 'var(--esp-large)' }}>
          <label>
            <span className="lbl">Pays</span>
            <select value={pays} onChange={(e) => setPays(e.target.value)}>
              {PAYS.map(([code, nom]) => (
                <option key={code} value={code}>{nom}</option>
              ))}
            </select>
          </label>

          {nbMasques > 0 && (
            <button className="btn ghost sm" type="button" onClick={() => setVoirMasques((v) => !v)}>
              {voirMasques ? 'Cacher les masqués' : `Voir les ${nbMasques} masqué(s)`}
            </button>
          )}
        </div>

        {chargement ? (
          <div className="center" style={{ minHeight: 60 }}><div className="spinner" /></div>
        ) : visibles.length === 0 ? (
          <div className="empty" style={{ padding: 'var(--esp-large)' }}>
            {legaux.length === 0 ? (
              <>
                <strong>Aucun taux légal n’est encore enregistré pour ce pays.</strong>
                <div style={{ marginTop: 'var(--esp-normal)' }}>
                  Le référentiel est alimenté pays par pays. Tant qu’il est vide ici, saisissez vos
                  taux à la main ci-dessus — rien n’est bloqué.
                </div>
              </>
            ) : (
              <>
                <strong>Vous avez masqué tous les taux de ce pays.</strong>
                <div style={{ marginTop: 'var(--esp-normal)' }}>
                  Ils restent applicables à ce qui les utilise déjà. Le bouton ci-dessus les remontre.
                </div>
              </>
            )}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead>
                <tr>
                  <th>Taux</th><th>Catégorie</th><th>S’applique à</th>
                  <th>En vigueur</th><th>Source</th><th />
                </tr>
              </thead>
              <tbody>
                {visibles.map((t) => {
                  const id = String(t.id)
                  const masque = masquePar.has(id)
                  const repris = dejaRepris.has(id)
                  const occupe = enCours === id
                  return (
                    <tr key={id} style={masque ? { opacity: 0.55 } : undefined}>
                      <td className="nm">{Number(t.rate).toString().replace('.', ',')} %</td>
                      <td>{CATEGORIES[t.category] || t.category}</td>
                      <td>
                        {t.label}
                        {ZERO_EXPLIQUE[t.category] && (
                          <div className="mut">{ZERO_EXPLIQUE[t.category]}</div>
                        )}
                      </td>
                      <td>
                        {t.validFrom ? `depuis le ${new Date(t.validFrom).toLocaleDateString('fr-FR')}` : ''}
                        {t.validUntil && (
                          <div className="mut">
                            clos le {new Date(t.validUntil).toLocaleDateString('fr-FR')}
                          </div>
                        )}
                      </td>
                      <td className="mut">{t.source}</td>
                      <td className="r" style={{ gap: 'var(--esp-normal)' }}>
                        {peutModifier && !repris && !masque && (
                          <button
                            className="btn sm"
                            type="button"
                            disabled={occupe}
                            onClick={() => reprendre(t)}
                          >
                            Reprendre
                          </button>
                        )}
                        {repris && <span className="badge good">Déjà repris</span>}
                        {peutModifier && (
                          <button
                            className="btn ghost sm"
                            type="button"
                            disabled={occupe}
                            onClick={() => basculerMasque(t)}
                            title={
                              masque
                                ? 'Remontrer ce taux dans la liste'
                                : 'Masquer ce taux : il ne vous sera plus proposé. Ce qui l’utilise déjà n’est pas affecté.'
                            }
                          >
                            {masque ? '◉' : '◎'}
                          </button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        <div className="hint" style={{ marginTop: 'var(--esp-large)' }}>
          Masquer un taux ne l’annule pas : s’il est déjà employé par une facture ou un produit, il
          continue de s’appliquer. Le masque porte sur ce qui vous est proposé, pas sur ce qui a
          déjà servi.
        </div>
      </div>
    </div>
  )
}
