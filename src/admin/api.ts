/**
 * Chiamate agli endpoint OCS dell'app.
 *
 * Il token viaggia solo in salita: nessuna risposta lo contiene mai.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type Cover = 'template' | 'sempre' | 'mai'

export interface Impostazioni {
	schema: string
	host: string
	porta: number | null
	percorsoBase: string
	verificaTls: boolean
	timeoutConnessione: number
	timeoutRichiesta: number
	template: string
	toc: boolean
	cover: Cover
	watermark: string
	dimensioneMassimaMb: number
	attesaMassima: number
	gruppi: string[]
}

export interface Gruppo {
	id: string
	nome: string
}

export interface EsitoPasso {
	stato: 'ok' | 'errore' | 'mancante' | 'non_provato'
	messaggio: string
	avviso?: string | null
	versione?: string
	template?: string[]
}

export interface RisultatoVerifica {
	servizio: EsitoPasso
	token: EsitoPasso
}

interface RispostaOcs<T> {
	ocs: {
		meta: { status: string, statuscode: number, message: string }
		data: T
	}
}

const intestazioni = { 'OCS-APIRequest': 'true' }

function url(endpoint: string): string {
	return generateOcsUrl('apps/impagina/api/{endpoint}', { endpoint })
}

/**
 * Il messaggio dell'involucro OCS, quando c'e': e' li' che finiscono gli
 * errori di validazione del backend.
 *
 * @param errore l'errore intercettato dalla chiamata
 */
export function messaggioErrore(errore: unknown): string | null {
	const risposta = (errore as { response?: { data?: RispostaOcs<unknown> } }).response
	const messaggio = risposta?.data?.ocs?.meta?.message
	return typeof messaggio === 'string' && messaggio !== '' ? messaggio : null
}

export async function salvaImpostazioni(
	impostazioni: Impostazioni,
	token: string | null,
	cancellaToken: boolean,
): Promise<{ impostazioni: Impostazioni, tokenImpostato: boolean }> {
	const risposta = await axios.post<RispostaOcs<{ impostazioni: Impostazioni, tokenImpostato: boolean }>>(
		url('impostazioni'),
		{ impostazioni, token, cancellaToken },
		{ headers: intestazioni },
	)
	return risposta.data.ocs.data
}

export async function verificaConnessione(
	impostazioni: Impostazioni,
	token: string | null,
): Promise<RisultatoVerifica> {
	const risposta = await axios.post<RispostaOcs<RisultatoVerifica>>(
		url('verifica'),
		{ impostazioni, token },
		{ headers: intestazioni },
	)
	return risposta.data.ocs.data
}

export async function elencaTemplate(): Promise<string[]> {
	const risposta = await axios.get<RispostaOcs<{ template: string[] }>>(
		url('template'),
		{ headers: intestazioni },
	)
	return risposta.data.ocs.data.template
}
