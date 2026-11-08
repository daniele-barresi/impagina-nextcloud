<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCA\Impagina\Exception\ConfigurazioneException;
use OCA\Impagina\Exception\ConversioneFallitaException as Fallita;
use OCA\Impagina\Exception\DocumentoException;
use OCA\Impagina\Exception\ImpaginaException;
use OCA\Impagina\Exception\LimiteException;
use OCA\Impagina\Exception\ReteException;
use OCA\Impagina\Exception\ServizioException;
use OCA\Impagina\Exception\ServizioOccupatoException;
use OCA\Impagina\Model\Allegato;
use OCA\Impagina\Model\Connessione;
use OCA\Impagina\Model\Dipendenze;
use OCA\Impagina\Model\EsitoConversione as Esito;
use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Model\Lavoro;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Il flusso completo di una conversione, dal `.md` dell'utente al PDF salvato
 * nella stessa cartella.
 *
 * Sta in un servizio e non dentro il job perche' cosi' si prova senza il
 * meccanismo dei lavori in background. Il job si limita a rileggere gli
 * argomenti e a chiamare {@see self::esegui()}.
 *
 * Si entra da due parti:
 *
 * - {@see self::subito()}, chiamata durante la richiesta dell'utente: il
 *   servizio converte in meno di un secondo, quindi di solito il PDF e' pronto
 *   prima della risposta. Se il servizio e' occupato, irraggiungibile o lento,
 *   la conversione viene **rimandata** e il chiamante la mette in coda;
 * - {@see self::esegui()}, chiamata dal lavoro in background: aspetta fino
 *   all'attesa massima configurata e notifica sempre l'esito.
 *
 * In tutti e due i casi l'attesa del risultato avviene **dentro** la stessa
 * esecuzione: dividere invio e attesa in due lavori aggiungerebbe un giro di
 * cron (in AIO, minuti) a ogni conversione.
 */
final class Conversione {
	/** Limite del servizio per `POST /dipendenze` (`IMPAGINA_API_MAX_MD`). */
	public const int MASSIMO_MARKDOWN = 5 * 1024 * 1024;

	/** Estremi entro cui rispettare il `Retry-After` durante l'attesa. */
	public const int ATTESA_MINIMA = 1;
	public const int ATTESA_MASSIMA = 5;

	/**
	 * Durata del blocco contro le conversioni doppie per un lavoro in
	 * background: l'attesa massima configurabile piu' un margine per i
	 * trasferimenti.
	 */
	public const int DURATA_BLOCCO_BACKGROUND = ImpostazioniAdmin::ATTESA_MASSIMA_MASSIMA + 300;

	/**
	 * Motivi per cui una conversione immediata passa al background invece di
	 * fallire: dipendono dal momento, non dal documento, e riprovando fra
	 * qualche minuto possono sparire.
	 *
	 * @var list<string>
	 */
	private const array RIMANDABILI = [
		Fallita::MOTIVO_OCCUPATO,
		Fallita::MOTIVO_RETE,
		Fallita::MOTIVO_TEMPO_SCADUTO,
		Fallita::MOTIVO_CODA_TROPPO_LUNGA,
	];

	/** @var list<string> */
	private array $temporanei = [];

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ConfigService $config,
		private readonly ImpaginaClient $client,
		private readonly AttachmentCollector $allegati,
		private readonly OutputNamer $nomi,
		private readonly Notifiche $notifiche,
		private readonly ITempManager $temp,
		private readonly IAttesa $attesa,
		private readonly ITimeFactory $tempo,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Converte il file e notifica l'esito. Non solleva eccezioni: un lavoro in
	 * background che fallisce deve lasciare l'utente informato, non una riga
	 * di log e basta.
	 */
	public function esegui(string $userId, int $fileId): void {
		$this->procedi($userId, $fileId, null);
	}

	/**
	 * Prova a convertire subito, aspettando il risultato al massimo
	 * `$attesaMassima` secondi (e comunque non oltre l'attesa configurata).
	 *
	 * Come {@see self::esegui()} notifica l'esito e non solleva eccezioni;
	 * in piu' restituisce l'esito al chiamante. Una conversione rimandata non
	 * produce notifiche: la dara' il lavoro in background.
	 */
	public function subito(string $userId, int $fileId, int $attesaMassima): Esito {
		return $this->procedi($userId, $fileId, $attesaMassima);
	}

	/**
	 * @param int|null $attesaMassima null = quella configurata, e nessun rimando
	 */
	private function procedi(string $userId, int $fileId, ?int $attesaMassima): Esito {
		$sorgente = '';

		try {
			$file = $this->documento($userId, $fileId);
			$sorgente = $file->getName();
			$pdf = $this->converti($userId, $file, $attesaMassima);

			return Esito::completata($pdf->getId(), $pdf->getName());
		} catch (Fallita $e) {
			if ($attesaMassima !== null && in_array($e->motivo(), self::RIMANDABILI, true)) {
				$this->logger->info('impagina: conversione immediata rimandata al background ({motivo})', [
					'motivo' => $e->motivo(),
					'utente' => $userId,
					'fileId' => $fileId,
				]);

				return Esito::rimandata($e->motivo());
			}

			$this->logger->warning('impagina: conversione non riuscita ({motivo})', [
				'motivo' => $e->motivo(),
				'utente' => $userId,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			$this->notifiche->fallita($userId, $fileId, $sorgente, $e->motivo(), $e->parametri());

			return Esito::fallita($e->motivo(), $e->parametri());
		} catch (\Throwable $e) {
			$this->logger->error('impagina: errore inatteso durante la conversione', [
				'utente' => $userId,
				'fileId' => $fileId,
				'exception' => $e,
			]);
			$this->notifiche->fallita($userId, $fileId, $sorgente, Fallita::MOTIVO_INTERNO);

			return Esito::fallita(Fallita::MOTIVO_INTERNO);
		} finally {
			$this->pulisci();
		}
	}

	/**
	 * Rilegge il file con i permessi dell'utente. `getUserFolder()` restituisce
	 * una cartella pigra che inizializza da se' il filesystem: non serve (ne'
	 * e' permesso) chiamare le vecchie funzioni statiche di setup.
	 */
	private function documento(string $userId, int $fileId): File {
		$cartellaUtente = $this->rootFolder->getUserFolder($userId);
		$nodo = $cartellaUtente->getFirstNodeById($fileId);

		if (!$nodo instanceof File || !$nodo->isReadable()) {
			throw new Fallita(Fallita::MOTIVO_FILE_NON_TROVATO);
		}

		return $nodo;
	}

	private function converti(string $userId, File $file, ?int $attesaMassima): File {
		$cartella = $file->getParent();
		if (($cartella->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			throw new Fallita(Fallita::MOTIVO_PERMESSI);
		}

		if (!$this->config->configurato()) {
			throw new Fallita(Fallita::MOTIVO_CONFIGURAZIONE);
		}

		$impostazioni = $this->config->impostazioni();
		$connessione = $this->config->connessione();

		$markdown = $file->getContent();
		if (strlen($markdown) > self::MASSIMO_MARKDOWN) {
			throw new Fallita(Fallita::MOTIVO_TROPPO_GRANDE, [
				'dettaglio' => $this->confrontoDimensioni(strlen($markdown), self::MASSIMO_MARKDOWN),
			]);
		}

		$dipendenze = $this->dipendenze($connessione, $markdown);
		$this->rifiutaRiferimentiNonAllegabili($dipendenze);

		$allegati = $this->preparaAllegati($cartella, $dipendenze);
		$percorsoMarkdown = $this->fileTemporaneo('.md');
		file_put_contents($percorsoMarkdown, $markdown);

		$this->verificaDimensioneTotale($percorsoMarkdown, $allegati, $impostazioni->dimensioneMassimaByte());

		$creato = $this->chiama(
			fn (): \OCA\Impagina\Model\LavoroCreato => $this->client->creaLavoro(
				$connessione,
				$file->getName(),
				$percorsoMarkdown,
				$allegati,
				$impostazioni->opzioni(),
			),
		);

		$attesa = $attesaMassima === null
			? $impostazioni->attesaMassima
			: min($attesaMassima, $impostazioni->attesaMassima);
		$lavoro = $this->attendi($connessione, $creato->id(), $attesa);

		if (!$lavoro->riuscito()) {
			$this->client->eliminaSePossibile($connessione, $lavoro->id());

			throw $this->fallimentoDelLavoro($lavoro);
		}

		return $this->salva($userId, $connessione, $file, $cartella, $lavoro);
	}

	private function dipendenze(Connessione $connessione, string $markdown): Dipendenze {
		return $this->chiama(fn (): Dipendenze => $this->client->dipendenze($connessione, $markdown));
	}

	/**
	 * Indirizzi remoti, percorsi assoluti o fuori dalla cartella, e Typst
	 * grezzo: il servizio li rifiuterebbe o li ignorerebbe in silenzio, quindi
	 * si ferma tutto qui, **prima** di creare il lavoro, elencando ogni
	 * riferimento con il motivo scritto dal servizio.
	 */
	private function rifiutaRiferimentiNonAllegabili(Dipendenze $dipendenze): void {
		if ($dipendenze->typstGrezzo()) {
			throw new Fallita(Fallita::MOTIVO_TYPST_GREZZO);
		}

		$ignorati = $dipendenze->ignorati();
		if ($ignorati === []) {
			return;
		}

		$elenco = [];
		foreach ($ignorati as $riferimento) {
			$elenco[] = $riferimento->riferimento() . ' — ' . $riferimento->motivo();
		}

		throw new Fallita(Fallita::MOTIVO_RIFERIMENTI_NON_ALLEGABILI, ['elenco' => $elenco]);
	}

	/**
	 * Risolve i file referenziati e ne fa una copia temporanea: il multipart
	 * ha bisogno di parti con dimensione nota, e gli stream di alcuni storage
	 * (cifrati, esterni) non la conoscono.
	 *
	 * @return list<Allegato>
	 */
	private function preparaAllegati(Folder $cartella, Dipendenze $dipendenze): array {
		$risoluzione = $this->allegati->risolvi($cartella, $dipendenze->percorsi());

		if ($risoluzione['mancanti'] !== []) {
			// Tutti, non solo il primo: cosi' l'utente li sistema in una volta.
			throw new Fallita(Fallita::MOTIVO_FILE_MANCANTI, ['elenco' => $risoluzione['mancanti']]);
		}

		$allegati = [];
		foreach ($risoluzione['trovati'] as $percorso => $file) {
			$destinazione = $this->fileTemporaneo();
			$this->copiaSuDisco($file, $destinazione);
			$allegati[] = new Allegato($percorso, $destinazione);
		}

		return $allegati;
	}

	private function copiaSuDisco(File $file, string $destinazione): void {
		$lettura = $file->fopen('r');
		if ($lettura === false) {
			throw new Fallita(Fallita::MOTIVO_FILE_MANCANTI, ['elenco' => [$file->getName()]]);
		}

		try {
			$scrittura = @fopen($destinazione, 'wb');
			if ($scrittura === false) {
				throw new Fallita(Fallita::MOTIVO_INTERNO, [], "file temporaneo non scrivibile: $destinazione");
			}

			try {
				stream_copy_to_stream($lettura, $scrittura);
			} finally {
				fclose($scrittura);
			}
		} finally {
			fclose($lettura);
		}
	}

	/**
	 * @param list<Allegato> $allegati
	 */
	private function verificaDimensioneTotale(string $percorsoMarkdown, array $allegati, int $massimo): void {
		$totale = $this->dimensione($percorsoMarkdown);
		foreach ($allegati as $allegato) {
			$totale += $this->dimensione($allegato->percorsoLocale());
		}

		if ($totale > $massimo) {
			throw new Fallita(Fallita::MOTIVO_TROPPO_GRANDE, [
				'dettaglio' => $this->confrontoDimensioni($totale, $massimo),
			]);
		}
	}

	/**
	 * Interroga lo stato finche' il lavoro non e' terminato, rispettando il
	 * `Retry-After` del servizio ma senza scendere sotto un secondo ne' salire
	 * sopra cinque.
	 */
	private function attendi(Connessione $connessione, string $id, int $attesaMassima): Lavoro {
		$scadenza = $this->tempo->getTime() + $attesaMassima;

		while (true) {
			$lavoro = $this->chiama(fn (): Lavoro => $this->client->stato($connessione, $id));
			if ($lavoro->terminato()) {
				return $lavoro;
			}

			if ($this->tempo->getTime() >= $scadenza) {
				$this->client->eliminaSePossibile($connessione, $id);

				throw new Fallita($lavoro->inCoda()
					? Fallita::MOTIVO_CODA_TROPPO_LUNGA
					: Fallita::MOTIVO_TEMPO_SCADUTO);
			}

			$this->attesa->dormi($this->limita($lavoro->retryAfter() ?? self::ATTESA_MINIMA));
		}
	}

	/**
	 * Scarica il risultato, controlla che sia davvero un PDF e lo salva
	 * accanto al sorgente. Il lavoro si cancella subito dopo: il servizio lo
	 * terrebbe comunque solo un'ora.
	 */
	private function salva(
		string $userId,
		Connessione $connessione,
		File $sorgente,
		Folder $cartella,
		Lavoro $lavoro,
	): File {
		$risultato = $lavoro->risultato();
		if ($risultato !== null && !$risultato->ePdf()) {
			throw new Fallita(Fallita::MOTIVO_RISULTATO_NON_PDF);
		}

		$percorsoPdf = $this->fileTemporaneo('.pdf');
		$scaricato = $this->chiama(
			fn (): \OCA\Impagina\Model\FileScaricato => $this->client->risultato($connessione, $lavoro->id(), $percorsoPdf),
		);

		if (!$scaricato->ePdf()) {
			throw new Fallita(Fallita::MOTIVO_RISULTATO_NON_PDF);
		}

		try {
			$pdf = $this->nomi->crea($cartella, $this->nomi->nomePdf($sorgente->getName()), $percorsoPdf);
		} catch (\Throwable $e) {
			throw new Fallita(Fallita::MOTIVO_SALVATAGGIO, [], 'salvataggio del PDF non riuscito', $e);
		}

		$this->client->eliminaSePossibile($connessione, $lavoro->id());

		$avvisi = $lavoro->documenti() === [] ? [] : $lavoro->documenti()[0]->avvisi();
		$this->notifiche->riuscita(
			$userId,
			$pdf->getId(),
			$pdf->getName(),
			$pdf->getPath(),
			$sorgente->getName(),
			$avvisi,
		);

		return $pdf;
	}

	/**
	 * Il lavoro e' finito male: si riporta il dettaglio del documento, se c'e',
	 * altrimenti quello del lavoro.
	 */
	private function fallimentoDelLavoro(Lavoro $lavoro): Fallita {
		$errore = $lavoro->erroreSignificativo();
		if ($errore === null) {
			return new Fallita(Fallita::MOTIVO_SERVIZIO);
		}

		$eccezione = \OCA\Impagina\Exception\MappaErrori::eccezione('lavoro', 422, $errore);

		return new Fallita($this->motivoPer($eccezione), ['dettaglio' => $errore->testo()]);
	}

	/**
	 * Esegue una chiamata al servizio traducendo le sue eccezioni nel motivo
	 * che l'utente vedra' nella notifica.
	 *
	 * @template T
	 * @param callable(): T $chiamata
	 * @return T
	 */
	private function chiama(callable $chiamata) {
		try {
			return $chiamata();
		} catch (ImpaginaException $e) {
			$dettaglio = $e->dettaglio();
			$motivo = $this->motivoPer($e);
			$parametri = $motivo === Fallita::MOTIVO_DOCUMENTO || $motivo === Fallita::MOTIVO_TROPPO_GRANDE
				? ['dettaglio' => $dettaglio?->testo() ?? '']
				: [];

			throw new Fallita($motivo, $parametri, $e->getMessage(), $e);
		}
	}

	/**
	 * Solo gli errori che parlano del documento arrivano all'utente con le
	 * parole del servizio; gli altri diventano messaggi generici, con i
	 * dettagli nel log.
	 */
	private function motivoPer(ImpaginaException $e): string {
		return match (true) {
			$e instanceof DocumentoException => Fallita::MOTIVO_DOCUMENTO,
			$e instanceof ConfigurazioneException => Fallita::MOTIVO_CONFIGURAZIONE,
			$e instanceof LimiteException => Fallita::MOTIVO_TROPPO_GRANDE,
			$e instanceof ServizioOccupatoException => Fallita::MOTIVO_OCCUPATO,
			$e instanceof ReteException => Fallita::MOTIVO_RETE,
			$e instanceof ServizioException => Fallita::MOTIVO_SERVIZIO,
			default => Fallita::MOTIVO_INTERNO,
		};
	}

	private function limita(int $secondi): int {
		return max(self::ATTESA_MINIMA, min(self::ATTESA_MASSIMA, $secondi));
	}

	private function fileTemporaneo(string $suffisso = ''): string {
		$percorso = $this->temp->getTemporaryFile($suffisso);
		if ($percorso === false) {
			throw new Fallita(Fallita::MOTIVO_INTERNO, [], 'nessun file temporaneo disponibile');
		}

		$this->temporanei[] = $percorso;

		return $percorso;
	}

	private function dimensione(string $percorso): int {
		clearstatcache(true, $percorso);
		$byte = @filesize($percorso);

		return $byte === false ? 0 : $byte;
	}

	private function confrontoDimensioni(int $effettiva, int $massima): string {
		return sprintf('%.1f MB > %.1f MB', $effettiva / 1048576, $massima / 1048576);
	}

	private function pulisci(): void {
		foreach ($this->temporanei as $percorso) {
			if (is_file($percorso)) {
				@unlink($percorso);
			}
		}

		$this->temporanei = [];
	}
}
