import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../../api/client.js'
import ReferentielEditable from '../../components/ReferentielEditable.jsx'

// L'administration du site vitrine de Fluvia : sa page d'accueil et son blog (ED-10).
//
// TROIS ONGLETS, TROIS NATURES. Les articles et les rubriques sont des référentiels — une liste,
// un formulaire, une suppression : `ReferentielEditable` les porte déjà, avec sa confirmation qui
// dit ce que la suppression casse et son vide qui explique au lieu de constater. Les blocs de la
// page d'accueil ne sont pas une liste : c'est un jeu FIXE de champs, déclaré par le gabarit. Ils
// ont donc leur propre éditeur, juste en dessous.
//
// ⚠ AUCUN FILTRAGE DE DROITS ICI (D39). `/editor/website/**` répond 404 à une session qui n'est pas
// celle de l'éditeur. Rejouer la règle dans l'écran ne pourrait que la rejouer à moitié.

function descripteurArticles(rubriques) {
  return {
    titre: 'Articles',
    aQuoiCaSert:
      "Ce que le blog publie. Un article n'est visible du public que s'il est publié ET que sa date "
      + 'de publication est passée : une date au futur, c’est une parution programmée.',
    siVide:
      "Le blog n'a aucun article. La page /blog existe et le dit franchement au visiteur — elle "
      + "n'affiche pas une grille vide.",
    consequenceSuppression:
      "L'article disparaît du blog, du plan du site et du flux RSS. Les liens déjà partagés vers "
      + 'lui rendront 404. Pour le retirer sans casser ces liens, repassez-le plutôt en brouillon.',
    charger: api.editorArticles,
    creer: api.creerEditorArticle,
    modifier: api.majEditorArticle,
    supprimer: api.supprimerEditorArticle,
    champs: [
      {
        nom: 'title',
        libelle: 'Titre',
        type: 'text',
        requis: true,
        exemple: 'Comment nous gérons les créneaux de piscine',
        aide: "C'est lui qui donne l'adresse de l'article, la première fois seulement.",
      },
      {
        nom: 'slug',
        libelle: 'Adresse (slug)',
        type: 'text',
        exemple: 'comment-nous-gerons-les-creneaux',
        // Le refus est posé par le serveur (409) : cette aide dit pourquoi, elle ne le rejoue pas.
        aide:
          'Laissez vide : elle se déduit du titre. ⚠ Une fois l’article publié, elle ne peut plus '
          + 'changer — les liens déjà partagés pointeraient dans le vide.',
      },
      {
        nom: 'excerpt',
        libelle: 'Chapô',
        type: 'texte-long',
        lignes: 3,
        requis: true,
        aide:
          "Lu dans la liste des articles, et servi aux moteurs de recherche sous le titre si vous ne "
          + 'remplissez pas la description ci-dessous.',
      },
      {
        nom: 'body',
        libelle: 'Corps de l’article',
        type: 'texte-long',
        lignes: 16,
        aide:
          'HTML accepté : titres (h2, h3), paragraphes, listes, gras, liens, images, citations. '
          + 'Tout le reste est retiré à l’enregistrement — un script collé par mégarde ne part jamais '
          + 'en ligne.',
      },
      {
        nom: 'status',
        libelle: 'État',
        type: 'choix',
        options: [
          { valeur: 'draft', libelle: 'Brouillon — invisible du public' },
          { valeur: 'published', libelle: 'Publié' },
        ],
        aide: 'Publier sans date de parution publie maintenant.',
      },
      {
        nom: 'publishedAt',
        libelle: 'Date de parution',
        type: 'date',
        aide:
          'Au futur, l’article attend cette date pour apparaître — rien à déclencher, c’est le temps '
          + 'qui décide.',
      },
      {
        nom: 'categoryId',
        libelle: 'Rubrique',
        type: 'choix',
        options: [{ valeur: '', libelle: 'Sans rubrique' }].concat(
          rubriques.map((r) => ({ valeur: r.id, libelle: r.name })),
        ),
      },
      {
        nom: 'authorName',
        libelle: 'Signature',
        type: 'text',
        exemple: 'L’équipe Fluvia',
        aide: 'Affichée sous le titre. Vide : l’article ne porte aucune signature.',
      },
      {
        nom: 'metaDescription',
        libelle: 'Description pour les moteurs',
        type: 'texte-long',
        lignes: 2,
        aide: 'Ce que Google affiche sous le titre. Vide : c’est le chapô qui sert.',
      },
      {
        nom: 'coverUrl',
        libelle: 'Image d’en-tête (adresse)',
        type: 'text',
        exemple: 'https://…/photo.jpg',
        aide: 'Une adresse d’image. Elle sert aussi d’aperçu quand le lien est partagé.',
      },
      {
        nom: 'coverAlt',
        libelle: 'Description de l’image',
        type: 'text',
        aide: 'Lue à voix haute par les lecteurs d’écran. Sans elle, l’image n’existe pas pour eux.',
      },
    ],
    colonnes: [
      { cle: 'title', titre: 'Article', rendu: (r) => <span className="nm">{r.title || '—'}</span> },
      { cle: 'slug', titre: 'Adresse', rendu: (r) => <span className="mut">/blog/{r.slug}</span> },
      {
        cle: 'categoryName',
        titre: 'Rubrique',
        rendu: (r) => r.categoryName || <span className="mut">—</span>,
      },
      {
        cle: 'visible',
        titre: 'État',
        // ⚠ ON AFFICHE `visible`, CALCULÉ PAR LE SERVEUR, ET PAS `status`. Un article « publié »
        // daté de la semaine prochaine n'est pas en ligne : afficher « Publié » ferait croire le
        // contraire à celui qui vient de le programmer.
        rendu: (r) =>
          r.visible ? (
            <span className="badge good">En ligne</span>
          ) : r.status === 'published' ? (
            <span className="badge warn">Programmé</span>
          ) : (
            <span className="badge">Brouillon</span>
          ),
      },
      {
        cle: 'publishedAt',
        titre: 'Parution',
        rendu: (r) => (r.publishedAt ? r.publishedAt.slice(0, 10) : <span className="mut">—</span>),
      },
    ],
  }
}

function descripteurRubriques() {
  return {
    titre: 'Rubriques',
    aQuoiCaSert:
      'Le rangement du blog. Une rubrique par article — pas des étiquettes multiples, qui '
      + 'produisent autant de pages de listes presque vides.',
    siVide: "Le blog n'a aucune rubrique. Les articles s'affichent alors sans rangement, ce qui va très bien tant qu'ils sont peu nombreux.",
    consequenceSuppression:
      'Les articles de cette rubrique NE SONT PAS supprimés : ils repassent simplement sans '
      + 'rubrique. Leur adresse et leur contenu ne bougent pas.',
    charger: api.editorRubriques,
    creer: api.creerEditorRubrique,
    modifier: api.majEditorRubrique,
    supprimer: api.supprimerEditorRubrique,
    champs: [
      { nom: 'name', libelle: 'Nom', type: 'text', requis: true, exemple: 'Exploitation' },
      {
        nom: 'description',
        libelle: 'Description',
        type: 'texte-long',
        lignes: 3,
        aide: 'Affichée en tête de la liste de cette rubrique, et servie aux moteurs de recherche.',
      },
    ],
    colonnes: [
      { cle: 'name', titre: 'Rubrique', rendu: (r) => <span className="nm">{r.name}</span> },
      { cle: 'slug', titre: 'Adresse', rendu: (r) => <span className="mut">/blog/rubrique/{r.slug}</span> },
      { cle: 'postCount', titre: 'Articles', num: true, rendu: (r) => r.postCount },
    ],
  }
}

// ── Le referentiel des metiers ──────────────────────────────────────────────────────────────────

/**
 * Une page de /metiers, sans deploiement.
 *
 * ⚠ LES MODULES NE SE SAISISSENT PAS : ils se DEDUISENT des activites cochees, et la reponse du
 *   serveur les rend. C'est ce qui rend l'ecran honnete — on coche ce que l'etablissement FAIT, et
 *   on voit ce que le produit en conclut. Un champ « modules » ici permettrait d'annoncer sur une
 *   page de vente un module qu'aucune activite ne justifie.
 *
 * ⚠ LES NEUF ACTIVITES VIENNENT DU SERVEUR. Les reecrire ici ne casserait rien aujourd'hui et
 *   deriverait au premier libelle corrige : l'ecran proposerait « Reservation de ressource » la ou
 *   le site vend « Reservation de creneaux », sans que rien ne le signale.
 */
function descripteurMetiers(activites) {
  return {
    titre: 'Métiers',
    aQuoiCaSert:
      'Chaque ligne est une page de /metiers. Ajouter un métier ici suffit : sa page, son entrée au '
      + 'plan du site et ses modules suggérés en découlent, sans mise en production.',
    siVide:
      'Aucun métier en base : le site sert alors les cinq métiers d’origine, écrits dans le code. '
      + 'Ce n’est pas une panne — mais rien de ce qui est fait ici n’a encore basculé.',
    consequenceSuppression:
      'La page du métier disparaît, et son adresse rendra 404 pour qui l’avait en favori ou l’a vue '
      + 'dans un moteur. Son TEXTE de page n’est pas détruit : il revient si le métier est recréé '
      + 'avec le même code. Pour retirer la page sans casser de lien, repassez le métier en brouillon.',
    charger: api.editorMetiers,
    creer: api.creerEditorMetier,
    modifier: api.majEditorMetier,
    supprimer: api.supprimerEditorMetier,
    champs: [
      {
        nom: 'code',
        libelle: 'Code',
        type: 'text',
        requis: true,
        exemple: 'bowling',
        // Le refus est structurel cote serveur : la mise a jour ne recoit meme pas ce champ.
        aide:
          'Sans accent ni espace. ⚠ Il ne change plus après la création : c’est lui qui relie la page '
          + 'à son texte, et le modifier détacherait la page de ce qui y est écrit.',
      },
      {
        nom: 'name',
        libelle: 'Nom',
        type: 'text',
        requis: true,
        exemple: 'Bowlings',
        aide: 'Ce que le menu et la liste des métiers affichent.',
      },
      {
        nom: 'searchTitle',
        libelle: 'Titre de recherche',
        type: 'text',
        exemple: 'Logiciel de gestion pour bowling',
        aide:
          'Le titre de l’onglet et celui que les moteurs affichent. Il contient les mots qu’on tape, '
          + 'là où le nom doit tenir sur une ligne. Vide : le nom sert des deux.',
      },
      {
        nom: 'lead',
        libelle: 'Chapô',
        type: 'texte-long',
        lignes: 4,
        requis: true,
        aide: 'Les deux phrases en tête de page, et la description servie aux moteurs.',
      },
      {
        nom: 'activities',
        libelle: 'Ce que fait l’établissement',
        type: 'choix-multiples',
        options: activites.map((a) => ({ valeur: a.id, libelle: a.label })),
        aide:
          'Les modules proposés sur la page en découlent : cochez ce que l’établissement FAIT, pas ce '
          + 'que vous voulez lui vendre. ⚠ Les cinq métiers d’origine gardent leur préréglage écrit '
          + 'dans le produit — pour eux, ces cases ne changent pas les modules affichés.',
      },
      {
        nom: 'slug',
        libelle: 'Adresse',
        type: 'text',
        exemple: 'bowling',
        aide:
          'Vide : c’est le code qui sert. ⚠ Une fois le métier publié, elle ne peut plus changer — '
          + 'les liens partagés et les pages indexées pointeraient dans le vide.',
      },
      {
        nom: 'position',
        libelle: 'Rang',
        type: 'nombre',
        aide: 'L’ordre dans la liste des métiers. Les cinq d’origine vont de 10 à 50.',
      },
      {
        nom: 'status',
        libelle: 'État',
        type: 'choix',
        options: [
          { valeur: 'draft', libelle: 'Brouillon — invisible du public' },
          { valeur: 'published', libelle: 'Publié' },
        ],
        aide: 'Un brouillon existe ici et n’apparaît nulle part sur le site.',
      },
    ],
    colonnes: [
      { cle: 'name', titre: 'Métier', rendu: (r) => <span className="nm">{r.name}</span> },
      { cle: 'slug', titre: 'Adresse', rendu: (r) => <span className="mut">/metiers/{r.slug}</span> },
      {
        cle: 'activities',
        titre: 'Activités',
        num: true,
        rendu: (r) => (r.activities || []).length,
      },
      {
        cle: 'modules',
        titre: 'Modules suggérés',
        rendu: (r) =>
          (r.modules || []).length > 0
            ? <span className="mut">{(r.modules || []).join(', ')}</span>
            : <span className="mut">aucun</span>,
      },
      {
        cle: 'status',
        titre: 'État',
        rendu: (r) =>
          r.status === 'published'
            ? <span className="badge ok">Publié</span>
            : <span className="badge">Brouillon</span>,
      },
    ],
  }
}

// ── Les blocs de la page d'accueil ──────────────────────────────────────────────────────────────

/**
 * Un bloc, dans la forme que son type impose.
 *
 * ⚠ LE TYPE VIENT DU SERVEUR ET NE SE MODIFIE PAS. C'est le gabarit de la page qui le déclare : un
 * bloc « quatre cartes » qui recevrait une phrase casserait la page à la première visite, c'est-à-dire
 * après l'enregistrement, loin de celui qui a écrit.
 */
function EditeurDeBloc({ bloc, onEnregistrer, enCours }) {
  const [valeur, setValeur] = useState(bloc.value)
  const [modifie, setModifie] = useState(false)

  // La valeur rendue par le serveur fait foi après un enregistrement : elle a pu être normalisée
  // (une carte vide écartée, des espaces coupés). Sans cette resynchronisation, l'écran garderait
  // ce qu'on a tapé et la page afficherait autre chose.
  useEffect(() => {
    setValeur(bloc.value)
    setModifie(false)
  }, [bloc.value])

  const changer = (v) => {
    setValeur(v)
    setModifie(true)
  }

  const vide = valeur === null || valeur === undefined

  return (
    <div className="card" style={{ marginBottom: 'var(--esp-bloc)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 'var(--esp-large)' }}>
        <h3 style={{ margin: 0 }}>{bloc.label}</h3>
        <span className="mut" style={{ fontSize: 12 }}>{bloc.id}</span>
      </div>

      {bloc.help && <div className="hint">{bloc.help}</div>}

      {/* ⚠ UN BLOC JAMAIS REMPLI SE DIT. La page ne rend rien pour lui — elle n'invente aucun texte
          de remplacement — donc l'écran et la page montrent la même chose. */}
      {vide && (
        <p className="badge warn" style={{ display: 'inline-block', margin: 'var(--esp-normal) 0' }}>
          Jamais rempli : cette partie de la page n’affiche rien.
        </p>
      )}

      {(bloc.type === 'line' || bloc.type === 'paragraph') && (
        <>
          <label className="lbl" htmlFor={`bloc-${bloc.id}`}>Texte</label>
          {bloc.type === 'line' ? (
            <input
              id={`bloc-${bloc.id}`}
              className="input"
              type="text"
              value={valeur?.text || ''}
              onChange={(e) => changer({ text: e.target.value })}
            />
          ) : (
            <textarea
              id={`bloc-${bloc.id}`}
              className="input"
              rows={5}
              value={valeur?.text || ''}
              onChange={(e) => changer({ text: e.target.value })}
            />
          )}
          {bloc.type === 'paragraph' && (
            <div className="hint">Une ligne vide sépare deux paragraphes.</div>
          )}
        </>
      )}

      {bloc.type === 'rich' && (
        <>
          <label className="lbl" htmlFor={`bloc-${bloc.id}`}>Texte de la page</label>
          <textarea
            id={`bloc-${bloc.id}`}
            className="input"
            rows={14}
            value={valeur?.html || ''}
            onChange={(e) => changer({ html: e.target.value })}
          />
          <div className="hint">
            HTML accepté : sous-titres (h2, h3), paragraphes, listes, gras, liens, images, citations.
            Tout le reste est retiré à l’enregistrement. ⚠ La description courte de la page vient du
            catalogue du produit et ne se modifie pas ici — sans quoi le site dirait autre chose que
            ce qui est vendu.
          </div>
        </>
      )}

      {bloc.type === 'items' && (
        <>
          <label className="lbl" htmlFor={`bloc-${bloc.id}`}>Une entrée par ligne</label>
          <textarea
            id={`bloc-${bloc.id}`}
            className="input"
            rows={7}
            value={(valeur?.items || []).join('\n')}
            onChange={(e) => changer({ items: e.target.value.split('\n') })}
          />
        </>
      )}

      {bloc.type === 'cards' && (
        <div>
          {(valeur?.items || []).map((carte, i) => (
            <div key={i} style={{ borderTop: '1px solid var(--line)', paddingTop: 'var(--esp-large)', marginTop: 'var(--esp-large)' }}>
              <label className="lbl" htmlFor={`bloc-${bloc.id}-t-${i}`}>Titre {i + 1}</label>
              <input
                id={`bloc-${bloc.id}-t-${i}`}
                className="input"
                type="text"
                value={carte.title || ''}
                onChange={(e) => {
                  const items = [...(valeur?.items || [])]
                  items[i] = { ...items[i], title: e.target.value }
                  changer({ items })
                }}
              />
              <label className="lbl" htmlFor={`bloc-${bloc.id}-x-${i}`}>Texte {i + 1}</label>
              <textarea
                id={`bloc-${bloc.id}-x-${i}`}
                className="input"
                rows={3}
                value={carte.text || ''}
                onChange={(e) => {
                  const items = [...(valeur?.items || [])]
                  items[i] = { ...items[i], text: e.target.value }
                  changer({ items })
                }}
              />
              <button
                className="btn"
                type="button"
                onClick={() => changer({ items: (valeur?.items || []).filter((_, j) => j !== i) })}
              >
                Retirer ce bloc {i + 1}
              </button>
            </div>
          ))}
          <button
            className="btn"
            type="button"
            style={{ marginTop: 'var(--esp-large)' }}
            onClick={() => changer({ items: [...(valeur?.items || []), { title: '', text: '' }] })}
          >
            Ajouter un bloc
          </button>
        </div>
      )}

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 'var(--esp-large)' }}>
        <button
          className="btn primary"
          type="button"
          disabled={!modifie || enCours}
          onClick={() => onEnregistrer(bloc, valeur)}
        >
          Enregistrer {bloc.label}
        </button>
      </div>
    </div>
  )
}

/**
 * Les blocs d'un groupe — `accueil` ou `modules`.
 *
 * ⚠ UN SEUL COMPOSANT POUR LES DEUX, ET LE GROUPE VIENT DU SERVEUR. La déclaration des blocs vit
 * dans `SiteBlocks` : ajouter un module au produit fait apparaître son bloc ici, sans toucher à cet
 * écran. Une liste écrite côté navigateur aurait divergé au premier module ajouté.
 */
function EditeurDeBlocs({ groupe, introduction }) {
  // ⚠ `null` = PAS LU, `[]` = LU ET VIDE. Même règle que `ReferentielEditable` : sur une lecture
  // refusée, afficher « aucun bloc » dirait une absence qu'on n'a pas mesurée.
  const [blocs, setBlocs] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)
  const [enCours, setEnCours] = useState(false)

  const recharger = useCallback(async () => {
    setErreur(null)
    try {
      setBlocs(membres(await api.editorBlocs()).filter((b) => b.groupe === groupe))
    } catch (e) {
      setErreur(e?.message || 'Les blocs de la page d’accueil n’ont pas pu être lus.')
    }
  }, [groupe])

  useEffect(() => {
    recharger()
  }, [recharger])

  const enregistrer = async (bloc, valeur) => {
    setEnCours(true)
    setErreur(null)
    setSucces(null)
    try {
      await api.enregistrerEditorBloc(bloc.id, { id: bloc.id, value: valeur || {} })
      setSucces(`« ${bloc.label} » est enregistré. La page publique l’affiche déjà.`)
      await recharger()
    } catch (e) {
      setErreur(e?.message || 'L’enregistrement a échoué.')
    } finally {
      setEnCours(false)
    }
  }

  if (erreur && blocs === null) {
    return <p className="banner banner-error">{erreur}</p>
  }

  return (
    <div>
      <p className="hint">{introduction}</p>

      {erreur && <p className="banner banner-error">{erreur}</p>}
      {succes && <p className="ok">{succes}</p>}

      {(blocs || []).map((bloc) => (
        <EditeurDeBloc key={bloc.id} bloc={bloc} onEnregistrer={enregistrer} enCours={enCours} />
      ))}
    </div>
  )
}

// ── L'écran ─────────────────────────────────────────────────────────────────────────────────────

export default function SiteVitrine({ peutEcrire = true }) {
  const [onglet, setOnglet] = useState('articles')
  const [rubriques, setRubriques] = useState([])
  const [activites, setActivites] = useState([])

  // Les rubriques alimentent la liste déroulante du formulaire d'article. Elles sont relues quand
  // on revient sur l'onglet des articles : sans ça, une rubrique créée à l'instant n'apparaîtrait
  // pas dans le formulaire, et le rédacteur croirait qu'elle n'a pas été enregistrée.
  const relireLesRubriques = useCallback(async () => {
    try {
      setRubriques(membres(await api.editorRubriques()))
    } catch {
      // La liste déroulante se contentera de « Sans rubrique ». L'erreur qui compte — celle de la
      // liste elle-même — est affichée par l'onglet Rubriques, qui la lit pour de bon.
      setRubriques([])
    }
  }, [])

  useEffect(() => {
    relireLesRubriques()
  }, [relireLesRubriques, onglet])

  /*
   * Les neuf activites, une fois. Elles ne changent pas d'une session a l'autre — D15 les gele —
   * mais elles viennent quand meme du serveur : voir `descripteurMetiers`.
   */
  useEffect(() => {
    let vivant = true

    api.editorActivites()
      .then((r) => { if (vivant) setActivites(membres(r)) })
      .catch(() => { if (vivant) setActivites([]) })

    return () => { vivant = false }
  }, [])

  return (
    <section>
      <h2>Site vitrine</h2>
      <p className="hint">
        Le site public de Fluvia — <a href="https://vitrine.hector-conseil.com" target="_blank" rel="noreferrer">vitrine.hector-conseil.com</a>.
        Ce qui est enregistré ici est en ligne immédiatement : la page est rendue à chaque visite,
        il n’y a rien à régénérer.
      </p>

      <div role="tablist" aria-label="Parties du site" style={{ display: 'flex', gap: 'var(--esp-normal)', margin: 'var(--esp-large) 0' }}>
        {[
          ['articles', 'Articles'],
          ['rubriques', 'Rubriques'],
          ['metiers', 'Métiers'],
          ['accueil', 'Page d’accueil'],
          ['modules', 'Pages de modules'],
        ].map(([cle, libelle]) => (
          <button
            key={cle}
            type="button"
            role="tab"
            aria-selected={onglet === cle}
            className={onglet === cle ? 'btn primary' : 'btn'}
            onClick={() => setOnglet(cle)}
          >
            {libelle}
          </button>
        ))}
      </div>

      {onglet === 'articles' && (
        <ReferentielEditable descripteur={descripteurArticles(rubriques)} peutEcrire={peutEcrire} />
      )}
      {onglet === 'rubriques' && (
        <ReferentielEditable
          descripteur={descripteurRubriques()}
          peutEcrire={peutEcrire}
          onEcrit={relireLesRubriques}
        />
      )}
      {onglet === 'metiers' && (
        <>
          {/* ⚠ SANS LES NEUF, LE FORMULAIRE NE PEUT PAS ETRE HONNETE : une liste d'activites
              vide laisserait creer un metier sans composition, dont la page n'afficherait aucun
              module — et rien ne dirait pourquoi. On le dit plutot que de l'afficher quand meme. */}
          {activites.length === 0 && (
            <p className="banner banner-error">
              Les activités n’ont pas pu être chargées. Créer un métier maintenant le laisserait
              sans composition, et sa page n’afficherait aucun module.
            </p>
          )}
          <ReferentielEditable
            descripteur={descripteurMetiers(activites)}
            peutEcrire={peutEcrire && activites.length > 0}
          />
        </>
      )}
      {onglet === 'accueil' && (
        <EditeurDeBlocs
          groupe="accueil"
          introduction={
            'Le texte de la page d’accueil. La mise en page reste dans le code ; ce sont les mots qui '
            + 'se modifient ici. ⚠ Les prix ne s’écrivent nulle part : la section Tarifs lit le '
            + 'catalogue réel, pour qu’un prix affiché ne puisse pas diverger du prix facturé.'
          }
        />
      )}
      {onglet === 'modules' && (
        <EditeurDeBlocs
          groupe="modules"
          introduction={
            'Une page par module, à l’adresse /modules/…. Le titre et la description courte viennent '
            + 'du catalogue du produit — ils ne se saisissent pas. Ce que vous écrivez ici est le '
            + 'texte long de la page : il n’est pas obligatoire, et la page tient sans lui.'
          }
        />
      )}
    </section>
  )
}
