<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

use OCA\Impagina\Model\DettaglioErrore;

/**
 * Traduce una risposta di errore del servizio nell'eccezione che descrive
 * **come va trattata**.
 *
 * La scelta si fa sul `codice`, che la specifica garantisce stabile; il codice
 * HTTP e' solo un ripiego per i codici che non conosciamo (per esempio una
 * versione del servizio piu' recente dell'app).
 */
final class MappaErrori {
	/** Problemi di configurazione dell'istanza: li risolve l'amministratore. */
	private const array CONFIGURAZIONE = [
		'non_autorizzato',
		'template_non_trovato',
	];

	/** Problemi del documento dell'utente: messaggio e suggerimento sono per lui. */
	private const array DOCUMENTO = [
		'front_matter_non_valido',
		'conversione_fallita',
		'input_non_trovato',
		'percorso_non_valido',
		'tempo_scaduto',
	];

	/** Limiti di dimensione o di numero di file. */
	private const array LIMITE = [
		'troppo_grande',
		'troppi_file',
	];

	/** Il servizio e' momentaneamente saturo: si puo' riprovare. */
	private const array OCCUPATO = [
		'coda_piena',
		'server_occupato',
	];

	/** Guasti interni del servizio. */
	private const array SERVIZIO = [
		'errore_interno',
		'scrittura_fallita',
		'interrotto',
	];

	/**
	 * Richieste che l'app non avrebbe dovuto costruire cosi': sono bug nostri
	 * o un disallineamento di versione, non qualcosa da spiegare all'utente.
	 */
	private const array ERRORE_APP = [
		'richiesta_non_valida',
		'opzione_non_valida',
		'lunghezza_richiesta',
		'tipo_non_supportato',
		'metodo_non_consentito',
		'risorsa_non_trovata',
		'nessun_documento',
		'zip_non_valido',
		'uso_errato',
	];

	public static function eccezione(
		string $operazione,
		int $stato,
		?DettaglioErrore $dettaglio,
		?int $retryAfter = null,
	): ImpaginaException {
		$codice = $dettaglio?->codice() ?? '';
		$messaggio = sprintf(
			'%s: il servizio impagina ha risposto %d%s',
			$operazione,
			$stato,
			$codice === '' ? '' : " ($codice: " . ($dettaglio?->messaggio() ?? '') . ')',
		);

		if (in_array($codice, self::CONFIGURAZIONE, true)) {
			return new ConfigurazioneException($messaggio, $dettaglio, $stato);
		}
		if (in_array($codice, self::DOCUMENTO, true)) {
			return new DocumentoException($messaggio, $dettaglio, $stato);
		}
		if (in_array($codice, self::LIMITE, true)) {
			return new LimiteException($messaggio, $dettaglio, $stato);
		}
		if (in_array($codice, self::OCCUPATO, true)) {
			return new ServizioOccupatoException($messaggio, $dettaglio, $stato, $retryAfter);
		}
		if (in_array($codice, self::SERVIZIO, true)) {
			return new ServizioException($messaggio, $dettaglio, $stato);
		}
		if (in_array($codice, self::ERRORE_APP, true)) {
			return new ErroreAppException($messaggio, $dettaglio, $stato);
		}
		if ($codice === 'lavoro_non_trovato') {
			return new LavoroNonTrovatoException($messaggio, $dettaglio, $stato);
		}
		if ($codice === 'non_pronto') {
			return new NonProntoException($messaggio, $dettaglio, $stato, $retryAfter);
		}

		// Codice sconosciuto (o risposta senza corpo di errore): decide lo stato HTTP.
		return match (true) {
			$stato === 401 || $stato === 403 => new ConfigurazioneException($messaggio, $dettaglio, $stato),
			$stato === 404 => new LavoroNonTrovatoException($messaggio, $dettaglio, $stato),
			$stato === 409 => new NonProntoException($messaggio, $dettaglio, $stato, $retryAfter),
			$stato === 413 => new LimiteException($messaggio, $dettaglio, $stato),
			$stato === 503 => new ServizioOccupatoException($messaggio, $dettaglio, $stato, $retryAfter),
			$stato >= 500 => new ServizioException($messaggio, $dettaglio, $stato),
			default => new ErroreAppException($messaggio, $dettaglio, $stato),
		};
	}
}
