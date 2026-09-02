import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client.js'

// L'IDENTITÉ LÉGALE DU VENDEUR — RÉCLAMÉE PAR UN RAPPORT, SAISISSABLE NULLE PART.
//
// ⚠ CE N'EST PAS UN CHAMP OUBLIÉ, C'EST TOUTE UNE MOITIÉ DE LA CHAÎNE.
//
// `facturation:einvoicing:etat` mesure la distance à la facture électronique européenne et répète,
// en bas de chaque rapport : « aucun raccordement à Chorus, à une PDP, à VeriFactu ou à SdI ne
// remplacera une identité de vendeur non renseignée ». Mesure du 02/09 : l'API acceptait `Post` et
// `Patch` depuis le début, les cinq champs étaient dans le groupe `profil:write` — et **aucun écran
// n'appelait ces routes**. Le frontal ne faisait que lire.
//
// Un rapport qui demande de remplir un formulaire qui n'existe pas envoie chercher là où il n'y a
// rien. C'est la même forme que le message d'erreur à deux sorties dont une était fermée, corrigé
// dans le composant voisin.
//
// ── CE QUE CET ÉCRAN NE FAIT PAS ────────────────────────────────────────────────────────────────
//
// Il ne vérifie pas que le SIREN existe, ni que le numéro de TVA est actif : ça demande un appel à
// un annuaire, et une saisie refusée à tort empêcherait de facturer. Il vérifie la **forme** — neuf
// chiffres, quatorze chiffres, `FR` + onze caractères — et le dit.
//
// Il ne rend pas non plus les factures émettables à lui seul : l'adresse de l'acheteur reste à
// saisir sur chaque destinataire, et le sérialiseur du fichier européen n'existe pas encore.

const PAYS_DEFAUT = 'FR'

// Ce que chaque champ débloque, en français d'exploitant. Le code `BT-xx` ne lui dit rien ; ce qui
// lui parle, c'est qu'une facture parte ou non.
const CHAMPS = [
  {
    nom: 'raisonSociale',
    libelle: 'Raison sociale',
    aide: 'Le nom sous lequel votre structure facture. Il apparaît en tête de chaque facture.',
    exemple: 'Régie des Sports de Sète',
    requis: true,
  },
  {
    nom: 'siren',
    libelle: 'SIREN',
    aide: 'Neuf chiffres. C’est l’identifiant légal repris par Chorus Pro et par les plateformes de dématérialisation.',
    exemple: '130025265',
    requis: true,
    motif: /^\d{9}$/,
    erreurMotif: 'Le SIREN compte exactement neuf chiffres.',
  },
  {
    nom: 'siret',
    libelle: 'SIRET',
    aide: 'Quatorze chiffres : le SIREN suivi du numéro d’établissement. Facultatif pour émettre, exigé par certains donneurs d’ordre publics.',
    exemple: '13002526500013',
    requis: false,
    motif: /^\d{14}$/,
    erreurMotif: 'Le SIRET compte exactement quatorze chiffres.',
  },
  {
    nom: 'tvaIntracommunautaire',
    libelle: 'N° de TVA intracommunautaire',
    aide: 'Obligatoire dès que vous facturez la TVA. Laissez vide si vous n’y êtes pas assujetti.',
    exemple: 'FR12130025265',
    requis: false,
    motif: /^[A-Z]{2}[0-9A-Z]{2,13}$/,
    erreurMotif: 'Deux lettres de pays suivies du numéro national, sans espaces.',
  },
]

const CHAMPS_ADRESSE = [
  { cle: 'rue', libelle: 'Rue', exemple: '2 rue du Port' },
  { cle: 'cp', libelle: 'Code postal', exemple: '34200' },
  { cle: 'ville', libelle: 'Ville', exemple: 'Sète' },
  { cle: 'pays', libelle: 'Pays (code ISO)', exemple: 'FR' },
]

export default function IdentiteVendeur({ peutModifier }) {
  const [profil, setProfil] = useState(null)
  const [valeurs, setValeurs] = useState(null)
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [info, setInfo] = useState('')
  const [enCours, setEnCours] = useState(false)

  const charger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const r = await api.profilsExploitant()
      const premier = (r?.member ?? r?.['hydra:member'] ?? [])[0] ?? null
      setProfil(premier)
      setValeurs(depuis(premier))
    } catch (e) {
      // ⚠ ON DISTINGUE « RIEN À AFFICHER » DE « JE N’AI PAS PU DEMANDER ». Un écran vide sur un
      // refus laisserait croire qu’aucune identité n’est enregistrée, et ferait ressaisir par-dessus.
      setErreur(e?.message || 'Impossible de lire le profil de facturation.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    charger()
  }, [charger])

  const manques = valeurs ? manquesDe(valeurs) : []
  const fautes = valeurs ? fautesDe(valeurs) : {}
  const bloquant = manques.length > 0 || Object.keys(fautes).length > 0

  async function enregistrer(e) {
    e.preventDefault()
    if (!valeurs || Object.keys(fautes).length > 0) return

    setEnCours(true)
    setErreur(null)
    setInfo('')
    try {
      const corps = {
        raisonSociale: valeurs.raisonSociale.trim(),
        siren: valeurs.siren.trim(),
        siret: valeurs.siret.trim() || null,
        tvaIntracommunautaire: valeurs.tvaIntracommunautaire.trim() || null,
        adresse: {
          rue: valeurs.rue.trim(),
          cp: valeurs.cp.trim(),
          ville: valeurs.ville.trim(),
          pays: (valeurs.pays.trim() || PAYS_DEFAUT).toUpperCase(),
        },
      }

      const enregistre = profil?.id
        ? await api.majProfilExploitant(profil.id, corps)
        : await api.creerProfilExploitant(corps)

      setProfil(enregistre)
      setValeurs(depuis(enregistre))
      setInfo('Identité enregistrée.')
    } catch (err) {
      setErreur(err?.message || 'L’enregistrement a échoué.')
    } finally {
      setEnCours(false)
    }
  }

  if (chargement) return <div className="card">Chargement de l’identité de facturation…</div>

  if (erreur && !valeurs) {
    return (
      <div className="card">
        <h3>Identité de facturation</h3>
        <p className="crit">{erreur}</p>
        <button type="button" className="btn" onClick={charger}>Réessayer</button>
      </div>
    )
  }

  return (
    <div className="card">
      <h3>Identité de facturation</h3>

      <p className="hint">
        Ces informations identifient votre structure sur chaque facture. Sans elles, aucune facture
        ne peut partir au format électronique — et aucun raccordement à Chorus Pro ou à une
        plateforme de dématérialisation n’y changera quoi que ce soit.
      </p>

      {bloquant ? (
        <p className="warn" role="status">
          {manques.length > 0
            ? `${manques.length} information(s) manquante(s) : ${manques.join(', ')}.`
            : 'Une information saisie n’a pas la forme attendue.'}
        </p>
      ) : (
        <p className="good" role="status">
          L’identité est complète. Il reste l’adresse de chaque client destinataire de facture.
        </p>
      )}

      <form onSubmit={enregistrer}>
        {CHAMPS.map((c) => (
          <div key={c.nom} className="field">
            <label htmlFor={`iv-${c.nom}`}>
              {c.libelle}{c.requis ? ' *' : ''}
            </label>
            <input
              id={`iv-${c.nom}`}
              type="text"
              className="input"
              value={valeurs?.[c.nom] ?? ''}
              placeholder={c.exemple}
              disabled={!peutModifier || enCours}
              onChange={(ev) => setValeurs((v) => ({ ...v, [c.nom]: ev.target.value }))}
            />
            <small className="hint">{c.aide}</small>
            {fautes[c.nom] ? <small className="hint crit">{fautes[c.nom]}</small> : null}
          </div>
        ))}

        <h4>Adresse</h4>
        {CHAMPS_ADRESSE.map((c) => (
          <div key={c.cle} className="field">
            <label htmlFor={`iv-${c.cle}`}>{c.libelle} *</label>
            <input
              id={`iv-${c.cle}`}
              type="text"
              className="input"
              value={valeurs?.[c.cle] ?? ''}
              placeholder={c.exemple}
              disabled={!peutModifier || enCours}
              onChange={(ev) => setValeurs((v) => ({ ...v, [c.cle]: ev.target.value }))}
            />
          </div>
        ))}

        {erreur ? <p className="crit" role="alert">{erreur}</p> : null}
        {info ? <p className="good" role="status">{info}</p> : null}

        <button type="submit" className="btn primary" disabled={!peutModifier || enCours}>
          {enCours ? 'Enregistrement…' : 'Enregistrer'}
        </button>

        {!peutModifier ? (
          <small className="hint"> Vous pouvez consulter ces informations, pas les modifier.</small>
        ) : null}
      </form>
    </div>
  )
}

function depuis(p) {
  const a = p?.adresse ?? {}

  return {
    raisonSociale: p?.raisonSociale ?? '',
    siren: p?.siren ?? '',
    siret: p?.siret ?? '',
    tvaIntracommunautaire: p?.tvaIntracommunautaire ?? '',
    rue: a.rue ?? '',
    cp: a.cp ?? '',
    ville: a.ville ?? '',
    pays: a.pays ?? PAYS_DEFAUT,
  }
}

/** Les libellés des informations obligatoires encore vides. */
function manquesDe(v) {
  const absents = []

  for (const c of CHAMPS) {
    if (c.requis && !String(v[c.nom] ?? '').trim()) absents.push(c.libelle)
  }
  for (const c of CHAMPS_ADRESSE) {
    if (!String(v[c.cle] ?? '').trim()) absents.push(c.libelle.toLowerCase())
  }

  return absents
}

/**
 * Les erreurs de FORME, champ par champ.
 *
 * ⚠ Un champ vide et facultatif n'est pas une faute : le motif ne s'applique qu'à ce qui est saisi.
 * Sans cette distinction, un numéro de TVA laissé vide par un non-assujetti bloquerait le
 * formulaire — et l'écran empêcherait de renseigner le reste.
 */
function fautesDe(v) {
  const f = {}

  for (const c of CHAMPS) {
    const saisi = String(v[c.nom] ?? '').trim()
    if (saisi && c.motif && !c.motif.test(saisi)) f[c.nom] = c.erreurMotif
  }

  return f
}
