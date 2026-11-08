/**
 * Azione "Converti in PDF con Impagina" nel menu contestuale dell'app File.
 *
 * Qui si decide solo cosa mostrare: chi puo' davvero convertire lo stabilisce
 * il backend, che rifa' tutti i controlli.
 *
 * Il backend prova a convertire subito: di solito il PDF e' pronto in un paio
 * di secondi, compare nell'elenco e il messaggio permette di aprirlo. Se il
 * servizio e' occupato, la conversione prosegue in background e il messaggio
 * lo dice.
 */

import axios from '@nextcloud/axios'
import { showError, showInfo, showLoading, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import type { ActionContext, ActionContextSingle, INode } from '@nextcloud/files'
import { getClient, getDefaultPropfind, getRootPath, resultToNode } from '@nextcloud/files/dav'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

// Lo stile dei toast non arriva dall'app File: le classi hanno un suffisso
// legato alla versione della libreria, quindi ogni app porta il proprio.
import '@nextcloud/dialogs/style.css'

const ESTENSIONI = ['md', 'markdown']

/** L'app e' configurata e l'utente appartiene a un gruppo abilitato. */
const abilitato = loadState<boolean>('impagina', 'abilitato', false)

const icona = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
	<path d="M4 1h5l3 3v11H4V1zm5 1.5V4h1.5L9 2.5zM5.5 7h5v1h-5V7zm0 2.5h5v1h-5v-1zm0 2.5h3v1h-3v-1z"/>
</svg>`

/** La risposta di `POST /api/convert`, a seconda dello stato. */
type Risposta =
	| { stato: 'completata', fileId: number, nome: string }
	| { stato: 'in_coda' | 'gia_in_coda' | 'gia_in_corso' }
	| { stato: 'fallita', messaggio: string }

/** Il router dell'app File, esposto da Nextcloud su `window.OCP`. */
type RouterFile = {
	goToRoute: (nome: string | null, parametri: Record<string, string>, query: Record<string, string>) => void
}

/**
 * @param nome nome del file, con estensione
 */
function eMarkdown(nome: string): boolean {
	const punto = nome.lastIndexOf('.')
	if (punto < 1) {
		return false
	}
	return ESTENSIONI.includes(nome.slice(punto + 1).toLowerCase())
}

registerFileAction({
	id: 'impagina-converti',

	displayName: () => t('impagina', 'Convert to PDF with Impagina'),

	iconSvgInline: () => icona,

	enabled: ({ nodes, folder }: ActionContext) => {
		if (!abilitato || nodes.length !== 1) {
			return false
		}
		const nodo = nodes[0]
		if (nodo.type !== FileType.File || !eMarkdown(nodo.basename)) {
			return false
		}
		// Il PDF nasce accanto al sorgente: serve poter creare nella cartella.
		return (folder.permissions & Permission.CREATE) !== 0
	},

	exec: async ({ nodes }: ActionContextSingle) => {
		const sorgente = nodes[0]
		const fileId = sorgente.fileid
		if (fileId === undefined) {
			showError(t('impagina', 'This file cannot be converted.'))
			return null
		}

		const attesa = showLoading(t('impagina', 'Converting {file} to PDF…', { file: sorgente.basename }))
		let risposta: Risposta
		try {
			const { data } = await axios.post<{ ocs: { data: Risposta } }>(
				generateOcsUrl('apps/impagina/api/convert'),
				{ fileId },
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			risposta = data.ocs.data
		} catch (errore) {
			const messaggio = (errore as { response?: { data?: { ocs?: { meta?: { message?: string } } } } })
				.response?.data?.ocs?.meta?.message
			showError(typeof messaggio === 'string' && messaggio !== ''
				? messaggio
				: t('impagina', 'The conversion could not be started.'))
			return null
		} finally {
			attesa.hideToast()
		}

		mostra(risposta, sorgente)

		// null e non true: i messaggi li mostra gia' l'azione, e con true
		// l'app File aggiungerebbe il suo generico "fatto".
		return null
	},

	order: 20,
})

/**
 * Il messaggio giusto per l'esito della conversione.
 *
 * @param risposta risposta del backend
 * @param sorgente il file Markdown convertito
 */
function mostra(risposta: Risposta, sorgente: INode): void {
	switch (risposta.stato) {
	case 'completata':
		showSuccess(t('impagina', '{file} is ready. Click here to open it.', { file: risposta.nome }), {
			onClick: () => apri(risposta.fileId, sorgente.dirname),
		})
		aggiungiAllElenco(`${sorgente.dirname}/${risposta.nome}`)
		break
	case 'in_coda':
		showInfo(t(
			'impagina',
			'The conversion service cannot convert the document right now: the conversion will continue in the background and you will get a notification when the PDF is ready.',
		), { timeout: 10000 })
		break
	case 'gia_in_coda':
	case 'gia_in_corso':
		showInfo(t('impagina', 'This document is already being converted.'))
		break
	case 'fallita':
		showError(t('impagina', '{file} was not converted: {reason}', {
			file: sorgente.basename,
			reason: risposta.messaggio,
		}), { timeout: 15000 })
		break
	}
}

/**
 * Apre il PDF nel visualizzatore restando nell'app File; fuori dall'app File
 * (improbabile, ma il router potrebbe mancare) passa dal collegamento breve.
 *
 * @param fileId id del PDF
 * @param cartella cartella del PDF, relativa alla radice dell'utente
 */
function apri(fileId: number, cartella: string): void {
	const router = (window as { OCP?: { Files?: { Router?: RouterFile } } }).OCP?.Files?.Router
	if (router === undefined) {
		window.location.href = generateUrl('/f/{fileId}', { fileId })
		return
	}

	router.goToRoute(null, { view: 'files', fileid: String(fileId) }, { dir: cartella, openfile: 'true' })
}

/**
 * Fa comparire il PDF nell'elenco senza ricaricare la cartella: l'app File
 * aggiunge il nodo quando riceve `files:node:created`.
 *
 * Se non riesce il PDF c'e' comunque, e comparira' al prossimo caricamento:
 * non vale la pena di disturbare l'utente con un errore.
 *
 * @param percorso percorso del PDF, relativo alla radice dell'utente
 */
async function aggiungiAllElenco(percorso: string): Promise<void> {
	try {
		const risultato = await getClient().stat(`${getRootPath()}${percorso}`, {
			details: true,
			data: getDefaultPropfind(),
		}) as { data: Parameters<typeof resultToNode>[0] }
		emit('files:node:created', resultToNode(risultato.data))
	} catch (errore) {
		console.warn('impagina: PDF creato ma non aggiunto all\'elenco', errore)
	}
}
