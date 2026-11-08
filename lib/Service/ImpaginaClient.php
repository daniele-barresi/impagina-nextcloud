<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCA\Impagina\Exception\ErroreAppException;
use OCA\Impagina\Exception\ImpaginaException;
use OCA\Impagina\Exception\MappaErrori;
use OCA\Impagina\Exception\ReteException;
use OCA\Impagina\Exception\RispostaNonValidaException;
use OCA\Impagina\Exception\ServizioOccupatoException;
use OCA\Impagina\Model\Allegato;
use OCA\Impagina\Model\Campi;
use OCA\Impagina\Model\Connessione;
use OCA\Impagina\Model\DettaglioErrore;
use OCA\Impagina\Model\Dipendenze;
use OCA\Impagina\Model\FileScaricato;
use OCA\Impagina\Model\Lavoro;
use OCA\Impagina\Model\LavoroCreato;
use OCA\Impagina\Model\OpzioniConversione;
use OCA\Impagina\Model\Salute;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Client degli endpoint del servizio impagina (contratto OpenAPI 1.2.0, invariato
 * nella 2.0.0: `docs/impagina-openapi.yaml`).
 *
 * E' senza stato: le coordinate del servizio arrivano a ogni chiamata come
 * {@see Connessione}, cosi' la pagina di amministrazione puo' provare valori
 * non ancora salvati.
 *
 * Scelte che valgono per tutte le richieste:
 *
 * - `http_errors => false`: gli errori li interpreta {@see MappaErrori} a
 *   partire dal `codice` del corpo, non dallo stato HTTP.
 * - `version => 1.1`: il servizio e' un `http.server` della libreria standard
 *   di Python e parla solo HTTP/1.1, mentre il client di Nextcloud tenta HTTP/2
 *   per default.
 * - `nextcloud.allow_local_address => true`: il servizio sta nella rete
 *   interna, che il client di Nextcloud rifiuterebbe. L'host lo decide solo
 *   l'amministratore, quindi gli utenti non possono influenzare l'URL chiamato.
 * - Il token non compare mai nei log: viene aggiunto solo qui e non fa parte di
 *   nessun messaggio d'errore.
 */
final readonly class ImpaginaClient {
	/** Tentativi aggiuntivi dopo un `503` (coda piena o servizio occupato). */
	public const int RITENTATIVI_503 = 2;

	/** Estremi entro cui tenere il `Retry-After` prima di riprovare un invio. */
	public const int ATTESA_MINIMA = 1;
	public const int ATTESA_MASSIMA = 10;

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private IAttesa $attesa,
	) {
	}

	/**
	 * `GET /salute`: raggiungibilita' del servizio, senza autenticazione.
	 */
	public function salute(Connessione $connessione): Salute {
		$risposta = $this->richiesta(
			'get',
			$connessione,
			'/salute',
			['headers' => ['Accept' => 'application/json']],
			autenticata: false,
		);
		$this->verifica('salute', $risposta);

		return $this->interpreta('salute', $risposta, static fn (array $dati): Salute => Salute::daArray($dati));
	}

	/**
	 * `GET /template`: i nomi dei template disponibili. Serve anche a
	 * verificare che il token sia valido.
	 *
	 * @return list<string>
	 */
	public function template(Connessione $connessione): array {
		$risposta = $this->richiesta('get', $connessione, '/template', ['headers' => ['Accept' => 'application/json']]);
		$this->verifica('template', $risposta);

		return $this->interpreta(
			'template',
			$risposta,
			static fn (array $dati): array => Campi::elencoStringhe($dati, 'template'),
		);
	}

	/**
	 * `POST /dipendenze`: i file referenziati dal documento, senza convertirlo.
	 *
	 * Il Markdown si invia come corpo `text/markdown`: e' una stringa, quindi
	 * il `Content-Length` c'e' sempre (il servizio rifiuta i corpi chunked).
	 */
	public function dipendenze(Connessione $connessione, string $markdown): Dipendenze {
		$risposta = $this->conRitentativi(
			'dipendenze',
			fn (): IResponse => $this->richiesta('post', $connessione, '/dipendenze', [
				'headers' => [
					'Accept' => 'application/json',
					'Content-Type' => 'text/markdown; charset=utf-8',
				],
				'body' => $markdown,
			]),
		);

		return $this->interpreta(
			'dipendenze',
			$risposta,
			static fn (array $dati): Dipendenze => Dipendenze::daArray($dati),
		);
	}

	/**
	 * `POST /lavori`: invia il documento con i suoi allegati e mette in coda la
	 * conversione.
	 *
	 * Ogni parte del multipart e' un file vero aperto in lettura: cosi' ha
	 * dimensione nota e Guzzle puo' calcolare il `Content-Length` complessivo.
	 * Il nome di ogni allegato e' il percorso restituito da `/dipendenze`.
	 *
	 * Il `Content-Disposition` si scrive a mano: Guzzle, se lo genera lui,
	 * applica `basename()` al nome del file, e `immagini/schema.png` arriverebbe
	 * al servizio come `schema.png`, introvabile per il Markdown.
	 *
	 * @param list<Allegato> $allegati
	 */
	public function creaLavoro(
		Connessione $connessione,
		string $nomeDocumento,
		string $percorsoDocumento,
		array $allegati,
		OpzioniConversione $opzioni,
	): LavoroCreato {
		$risposta = $this->conRitentativi(
			'creaLavoro',
			function () use ($connessione, $nomeDocumento, $percorsoDocumento, $allegati, $opzioni): IResponse {
				$gestori = [];

				try {
					$parti = [];
					$gestori[] = $gestore = $this->apri($percorsoDocumento);
					$parti[] = [
						'name' => 'documento',
						'contents' => $gestore,
						'filename' => $nomeDocumento,
						'headers' => [
							'Content-Disposition' => self::disposizione('documento', $nomeDocumento),
							'Content-Type' => 'text/markdown; charset=utf-8',
						],
					];

					foreach ($allegati as $allegato) {
						$gestori[] = $gestore = $this->apri($allegato->percorsoLocale());
						$parti[] = [
							'name' => 'allegato',
							'contents' => $gestore,
							'filename' => $allegato->percorso(),
							'headers' => ['Content-Disposition' => self::disposizione('allegato', $allegato->percorso())],
						];
					}

					foreach ($opzioni->campi() as $nome => $valore) {
						$parti[] = ['name' => $nome, 'contents' => $valore];
					}

					return $this->richiesta('post', $connessione, '/lavori', [
						'headers' => ['Accept' => 'application/json'],
						'multipart' => $parti,
					]);
				} finally {
					foreach ($gestori as $gestore) {
						// Guzzle puo' aver gia' chiuso lo stream dopo l'invio:
						// una risorsa chiusa non supera is_resource().
						/** @psalm-suppress RedundantConditionGivenDocblockType */
						if (is_resource($gestore)) {
							fclose($gestore);
						}
					}
				}
			},
		);

		$attesaSuggerita = $this->retryAfter($risposta);

		return $this->interpreta(
			'creaLavoro',
			$risposta,
			static fn (array $dati): LavoroCreato => LavoroCreato::daArray($dati, $attesaSuggerita),
		);
	}

	/**
	 * `GET /lavori/{id}`: stato del lavoro. Finche' non e' terminato la
	 * risposta porta un `Retry-After` che dice ogni quanto richiedere.
	 */
	public function stato(Connessione $connessione, string $id): Lavoro {
		$risposta = $this->richiesta(
			'get',
			$connessione,
			'/lavori/' . rawurlencode($id),
			['headers' => ['Accept' => 'application/json']],
		);
		$this->verifica('stato', $risposta);
		$attesaSuggerita = $this->retryAfter($risposta);

		return $this->interpreta(
			'stato',
			$risposta,
			static fn (array $dati): Lavoro => Lavoro::daArray($dati, $attesaSuggerita),
		);
	}

	/**
	 * `GET /lavori/{id}/risultato`: scarica il file prodotto in
	 * `$percorsoDestinazione`, senza tenerlo in memoria.
	 *
	 * Se il lavoro e' fallito la risposta e' un errore JSON: finisce anch'esso
	 * nel file di destinazione, che viene letto e poi cancellato.
	 */
	public function risultato(Connessione $connessione, string $id, string $percorsoDestinazione): FileScaricato {
		$risposta = $this->richiesta(
			'get',
			$connessione,
			'/lavori/' . rawurlencode($id) . '/risultato',
			['sink' => $percorsoDestinazione],
		);

		if ($risposta->getStatusCode() !== 200) {
			$corpo = @file_get_contents($percorsoDestinazione);
			@unlink($percorsoDestinazione);

			throw MappaErrori::eccezione(
				'risultato',
				$risposta->getStatusCode(),
				$this->dettaglio(is_string($corpo) ? $corpo : ''),
				$this->retryAfter($risposta),
			);
		}

		clearstatcache(true, $percorsoDestinazione);
		$byte = @filesize($percorsoDestinazione);

		return new FileScaricato(
			$percorsoDestinazione,
			$this->tipoContenuto($risposta),
			$byte === false ? 0 : $byte,
		);
	}

	/**
	 * `DELETE /lavori/{id}`: cancella lavoro e risultato, o toglie il lavoro
	 * dalla coda.
	 */
	public function elimina(Connessione $connessione, string $id): void {
		$risposta = $this->richiesta('delete', $connessione, '/lavori/' . rawurlencode($id));

		if ($risposta->getStatusCode() !== 204) {
			$this->verifica('elimina', $risposta);
		}
	}

	/**
	 * Come {@see self::elimina}, ma senza propagare errori: si usa per la
	 * pulizia dopo che il PDF e' stato salvato, dove un fallimento non cambia
	 * nulla per l'utente (il servizio cancella comunque tutto alla scadenza).
	 */
	public function eliminaSePossibile(Connessione $connessione, string $id): void {
		try {
			$this->elimina($connessione, $id);
		} catch (ImpaginaException $e) {
			$this->logger->debug('impagina: eliminazione del lavoro {id} non riuscita', [
				'id' => $id,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Esegue la richiesta ritentando i `503`: coda piena o servizio occupato
	 * sono stati momentanei, e il servizio dice lui quanto aspettare.
	 *
	 * @param callable(): IResponse $invio
	 */
	private function conRitentativi(string $operazione, callable $invio): IResponse {
		$tentativo = 0;

		while (true) {
			$risposta = $invio();

			try {
				$this->verifica($operazione, $risposta);

				return $risposta;
			} catch (ServizioOccupatoException $e) {
				if ($tentativo >= self::RITENTATIVI_503) {
					throw $e;
				}

				$attesa = $this->limita($e->retryAfter() ?? self::ATTESA_MINIMA);
				$this->logger->info('impagina: servizio occupato, riprovo fra {secondi}s', [
					'secondi' => $attesa,
					'operazione' => $operazione,
					'tentativo' => $tentativo + 1,
				]);
				$this->attesa->dormi($attesa);
				$tentativo++;
			}
		}
	}

	/**
	 * Esegue la richiesta HTTP, traducendo ogni guasto di trasporto in
	 * {@see ReteException}.
	 *
	 * @param 'delete'|'get'|'post' $metodo
	 * @param array<string, mixed> $opzioni
	 */
	private function richiesta(
		string $metodo,
		Connessione $connessione,
		string $endpoint,
		array $opzioni = [],
		bool $autenticata = true,
	): IResponse {
		$url = $connessione->url($endpoint);
		$client = $this->clientService->newClient();

		$comuni = [
			'verify' => $connessione->verificaTls(),
			'connect_timeout' => $connessione->timeoutConnessione(),
			'timeout' => $connessione->timeoutRichiesta(),
			'http_errors' => false,
			'version' => '1.1',
			'nextcloud' => ['allow_local_address' => true],
		];

		if ($autenticata) {
			/** @var array<string, string> $intestazioni */
			$intestazioni = is_array($opzioni['headers'] ?? null) ? $opzioni['headers'] : [];
			$intestazioni['Authorization'] = 'Bearer ' . $connessione->token();
			$opzioni['headers'] = $intestazioni;
		}

		try {
			return match ($metodo) {
				'get' => $client->get($url, array_merge($comuni, $opzioni)),
				'post' => $client->post($url, array_merge($comuni, $opzioni)),
				'delete' => $client->delete($url, array_merge($comuni, $opzioni)),
			};
		} catch (\Exception $e) {
			$this->logger->warning('impagina: {metodo} {endpoint} non ha raggiunto il servizio', [
				'metodo' => strtoupper($metodo),
				'endpoint' => $endpoint,
				'base' => $connessione->base(),
				'exception' => $e,
			]);

			throw new ReteException(
				sprintf('servizio impagina non raggiungibile su %s', $connessione->base()),
				null,
				0,
				$e,
			);
		}
	}

	/**
	 * Lascia passare solo le risposte riuscite; per le altre costruisce
	 * l'eccezione corrispondente al `codice` del servizio.
	 */
	private function verifica(string $operazione, IResponse $risposta): void {
		$stato = $risposta->getStatusCode();
		if ($stato >= 200 && $stato < 300) {
			return;
		}

		throw MappaErrori::eccezione(
			$operazione,
			$stato,
			$this->dettaglio($this->corpo($risposta)),
			$this->retryAfter($risposta),
		);
	}

	/**
	 * Decodifica il corpo JSON e lo passa al costruttore del modello,
	 * trasformando ogni sorpresa in {@see RispostaNonValidaException}.
	 *
	 * @template T
	 * @param callable(array<string, mixed>): T $costruttore
	 * @return T
	 */
	private function interpreta(string $operazione, IResponse $risposta, callable $costruttore) {
		try {
			return $costruttore(Campi::daJson($this->corpo($risposta)));
		} catch (UnexpectedValueException $e) {
			$this->logger->error('impagina: risposta inattesa da {operazione}', [
				'operazione' => $operazione,
				'stato' => $risposta->getStatusCode(),
				'exception' => $e,
			]);

			throw new RispostaNonValidaException(
				sprintf('%s: risposta non conforme alla specifica (%s)', $operazione, $e->getMessage()),
				null,
				$risposta->getStatusCode(),
				$e,
			);
		}
	}

	/**
	 * Il corpo di errore `{"errore": {...}}`, se c'e'. Una risposta senza corpo
	 * JSON (per esempio la pagina di un reverse proxy) non e' un errore in
	 * se': {@see MappaErrori} ripieghera' sullo stato HTTP.
	 */
	private function dettaglio(string $corpo): ?DettaglioErrore {
		if ($corpo === '') {
			return null;
		}

		try {
			return DettaglioErrore::daArray(Campi::daJson($corpo));
		} catch (UnexpectedValueException) {
			return null;
		}
	}

	private function corpo(IResponse $risposta): string {
		$corpo = $risposta->getBody();
		if (is_string($corpo)) {
			return $corpo;
		}
		if (is_resource($corpo)) {
			$contenuto = stream_get_contents($corpo);

			return $contenuto === false ? '' : $contenuto;
		}

		return '';
	}

	/**
	 * Il `Retry-After` in secondi. La specifica lo manda come intero, ma la
	 * forma con data HTTP e' ammessa dallo standard: si accettano entrambe.
	 */
	private function retryAfter(IResponse $risposta): ?int {
		$valore = trim($risposta->getHeader('Retry-After'));
		if ($valore === '') {
			return null;
		}

		if (preg_match('/^\d+$/', $valore) === 1) {
			return (int)$valore;
		}

		$istante = strtotime($valore);
		if ($istante === false) {
			return null;
		}

		return max(0, $istante - time());
	}

	private function tipoContenuto(IResponse $risposta): string {
		$valore = $risposta->getHeader('Content-Type');
		$puntoEVirgola = strpos($valore, ';');

		return strtolower(trim($puntoEVirgola === false ? $valore : substr($valore, 0, $puntoEVirgola)));
	}

	private function limita(int $secondi): int {
		return max(self::ATTESA_MINIMA, min(self::ATTESA_MASSIMA, $secondi));
	}

	/**
	 * Il `Content-Disposition` di una parte con file, con il nome intero.
	 *
	 * Il nome va fra virgolette con `\\` e `\"` protetti, come si aspetta il
	 * servizio; i caratteri di controllo si tolgono perche' spezzerebbero le
	 * intestazioni della parte. I caratteri non ASCII restano in UTF-8.
	 */
	private static function disposizione(string $campo, string $nomeFile): string {
		$nomeFile = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $nomeFile);

		return sprintf('form-data; name="%s"; filename="%s"', $campo, addcslashes($nomeFile, '"\\'));
	}

	/**
	 * Apre una parte del multipart. Un file temporaneo illeggibile e' un
	 * problema nostro, non del servizio.
	 *
	 * @return resource
	 */
	private function apri(string $percorso) {
		$gestore = @fopen($percorso, 'rb');
		if ($gestore === false) {
			throw new ErroreAppException(sprintf('file temporaneo non leggibile: %s', $percorso));
		}

		return $gestore;
	}
}
