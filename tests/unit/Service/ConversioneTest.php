<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Service;

use OCA\Impagina\Exception\ConversioneFallitaException as Fallita;
use OCA\Impagina\Model\EsitoConversione;
use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Service\AttachmentCollector;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Service\Conversione;
use OCA\Impagina\Service\ImpaginaClient;
use OCA\Impagina\Service\Notifiche;
use OCA\Impagina\Service\OutputNamer;
use OCA\Impagina\Tests\Finzione\AlberoFinto;
use OCA\Impagina\Tests\Finzione\AttesaFinta;
use OCA\Impagina\Tests\Finzione\ConfigurazioneFinta;
use OCA\Impagina\Tests\Finzione\NotificheFinte;
use OCA\Impagina\Tests\Finzione\RispostaFinta;
use OCA\Impagina\Tests\Finzione\ServizioFinto;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\File;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Il flusso completo, con il servizio HTTP e il filesystem simulati.
 */
final class ConversioneTest extends TestCase {
	use AlberoFinto;
	use ConfigurazioneFinta;
	use NotificheFinte;

	private const string ID_LAVORO = '169e201112dc4da9876768b16991ac02';

	private ServizioFinto $servizio;
	private ConfigService $config;
	private AttesaFinta $attesa;
	private Notifiche $notifiche;

	/** Istanti restituiti da `ITimeFactory::getTime()`, uno per chiamata. */
	private int $adesso = 1_000_000;
	private int $passoDelTempo = 0;

	/** @var list<string> */
	private array $temporaneiTest = [];

	#[\Override]
	protected function setUp(): void {
		$this->servizio = new ServizioFinto();
		$this->config = new ConfigService($this->appConfigFinto());
		$this->attesa = new AttesaFinta();
		$this->notifiche = $this->notificheFinte();

		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno', 8443, '', true, 10, 60, 'relazioni'));
		$this->config->salvaToken('imp_segretissimo');
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->temporaneiTest as $percorso) {
			if (is_file($percorso)) {
				unlink($percorso);
			}
		}
		$this->temporaneiTest = [];
		$this->pulisciAlbero();
	}

	private function conversione(?File $documento): Conversione {
		$temp = $this->createStub(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (string $suffisso = ''): string {
				$base = tempnam(sys_get_temp_dir(), 'impagina-conv-');
				self::assertIsString($base);

				$percorso = $base;
				if ($suffisso !== '') {
					// Come il vero ITempManager: un solo file, con il suffisso.
					unlink($base);
					$percorso = $base . $suffisso;
					touch($percorso);
				}

				$this->temporaneiTest[] = $percorso;

				return $percorso;
			},
		);

		$tempo = $this->createStub(ITimeFactory::class);
		$tempo->method('getTime')->willReturnCallback(function (): int {
			$istante = $this->adesso;
			$this->adesso += $this->passoDelTempo;

			return $istante;
		});

		return new Conversione(
			$this->radiceFinta($documento),
			$this->config,
			new ImpaginaClient($this->servizio, new NullLogger(), new AttesaFinta()),
			new AttachmentCollector(),
			new OutputNamer(),
			$this->notifiche,
			$temp,
			$this->attesa,
			$tempo,
			new NullLogger(),
		);
	}

	/**
	 * Documento con un'immagine, nella sua cartella.
	 *
	 * @param array<string, File> $vicini
	 */
	private function documento(
		string $markdown = "# Relazione\n\n![](immagini/schema.png)\n",
		array $vicini = [],
		int $permessi = Constants::PERMISSION_ALL,
	): File {
		$cartella = $this->cartellaFinta($vicini, $permessi);

		return $this->fileFinto('relazione.md', $markdown, 7, true, $cartella);
	}

	/**
	 * @param list<array{percorso: string, tipo: string, stato: string}> $file
	 * @param list<array{riferimento: string, stato: string, motivo: string}> $ignorati
	 */
	private function rispostaDipendenze(array $file, array $ignorati = [], bool $typstGrezzo = false): RispostaFinta {
		return RispostaFinta::json(200, [
			'documento' => 'relazione.md',
			'file' => $file,
			'ignorati' => $ignorati,
			'typst_grezzo' => $typstGrezzo,
		]);
	}

	private function rispostaLavoroCreato(): RispostaFinta {
		return RispostaFinta::json(202, [
			'id' => self::ID_LAVORO,
			'stato' => 'in_coda',
			'stato_url' => '/lavori/' . self::ID_LAVORO,
			'risultato_url' => '/lavori/' . self::ID_LAVORO . '/risultato',
		], ['Retry-After' => '1']);
	}

	/**
	 * @param list<array<string, mixed>> $documenti
	 * @param array<string, mixed>|null $risultato
	 */
	private function rispostaStato(
		string $stato,
		?string $esito = null,
		array $documenti = [],
		?array $risultato = null,
		int $retryAfter = 0,
	): RispostaFinta {
		$corpo = [
			'id' => self::ID_LAVORO,
			'stato' => $stato,
			'creato' => '2026-09-19T10:00:00Z',
			'opzioni' => ['template' => 'relazioni'],
			'documenti' => $documenti,
		];
		if ($esito !== null) {
			$corpo['esito'] = $esito;
		}
		if ($risultato !== null) {
			$corpo['risultato'] = $risultato;
		}

		return RispostaFinta::json(
			200,
			$corpo,
			$retryAfter > 0 ? ['Retry-After' => (string)$retryAfter] : [],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function risultatoPdf(): array {
		return [
			'url' => '/lavori/' . self::ID_LAVORO . '/risultato',
			'tipo' => 'application/pdf',
			'nome' => 'relazione.pdf',
			'byte' => 1024,
		];
	}

	// --- percorso felice --------------------------------------------------

	public function testConversioneRiuscitaSalvaIlPdfAccantoAlSorgente(): void {
		$documento = $this->documento(vicini: ['immagini/schema.png' => $this->fileFinto('schema.png', 'PNG')]);

		$this->servizio->prepara(
			$this->rispostaDipendenze([
				['percorso' => 'immagini/schema.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('in_corso', retryAfter: 2),
			$this->rispostaStato('completato', 'ok', [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf', 'avvisi' => ['font sostituito']],
			], self::risultatoPdf()),
			new RispostaFinta(200, "%PDF-1.7\nfinto", ['Content-Type' => 'application/pdf']),
			new RispostaFinta(204),
		);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(['relazione.pdf'], $this->nomiCreati());
		self::assertStringStartsWith('%PDF-', $this->contenutoCreato('relazione.pdf'));

		$notifica = $this->ultimaNotifica();
		self::assertSame(Notifiche::SOGGETTO_RIUSCITA, $notifica['soggetto']);
		self::assertSame('anna', $notifica['utente']);
		self::assertSame('relazione.pdf', $notifica['parametri']['nome']);
		self::assertSame(['font sostituito'], $notifica['parametri']['avvisi']);

		// L'attesa ha rispettato il Retry-After del servizio.
		self::assertSame([2], $this->attesa->attese());
		// Il lavoro viene cancellato subito dopo il salvataggio.
		self::assertSame('DELETE', $this->servizio->richiesta(5)->metodo);
	}

	public function testInviaIlDocumentoEGliAllegatiConIPercorsiDelServizio(): void {
		$documento = $this->documento(vicini: ['immagini/schema.png' => $this->fileFinto('schema.png', 'PNG')]);

		$this->servizio->prepara(
			$this->rispostaDipendenze([
				['percorso' => 'immagini/schema.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('completato', 'ok', [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf'],
			], self::risultatoPdf()),
			new RispostaFinta(200, "%PDF-1.7\nfinto", ['Content-Type' => 'application/pdf']),
			new RispostaFinta(204),
		);

		$this->conversione($documento)->esegui('anna', 7);

		$invio = $this->servizio->richiesta(1);
		self::assertSame('https://impagina.interno:8443/lavori', $invio->url);
		self::assertSame(['immagini/schema.png'], $invio->nomiAllegati());
		self::assertSame('PNG', $invio->parti()[1]['contents']);
		self::assertSame('relazioni', $invio->campi()['template']);
	}

	public function testSenzaImmaginiNonAllegaNulla(): void {
		$documento = $this->documento("# Solo testo\n");

		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('completato', 'ok', [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf'],
			], self::risultatoPdf()),
			new RispostaFinta(200, "%PDF-1.7\nfinto", ['Content-Type' => 'application/pdf']),
			new RispostaFinta(204),
		);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame([], $this->servizio->richiesta(1)->nomiAllegati());
		self::assertSame(Notifiche::SOGGETTO_RIUSCITA, $this->ultimaNotifica()['soggetto']);
	}

	public function testUnSecondoPdfNonSovrascriveIlPrimo(): void {
		$documento = $this->documento(
			"# Solo testo\n",
			['relazione.pdf' => $this->fileFinto('relazione.pdf', '%PDF-1.7 vecchio')],
		);

		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('completato', 'ok', [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf'],
			], self::risultatoPdf()),
			new RispostaFinta(200, "%PDF-1.7\nnuovo", ['Content-Type' => 'application/pdf']),
			new RispostaFinta(204),
		);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(['relazione (2).pdf'], $this->nomiCreati());
	}

	// --- rifiuti prima di creare il lavoro --------------------------------

	public function testRiferimentiNonAllegabiliFermanoTuttoPrimaDiCreareIlLavoro(): void {
		$documento = $this->documento();

		$this->servizio->prepara($this->rispostaDipendenze([], [
			[
				'riferimento' => 'https://example.com/logo.png',
				'stato' => 'remoto',
				'motivo' => 'Typst non scarica file remoti: scaricalo e allegalo.',
			],
			[
				'riferimento' => '/etc/logo.png',
				'stato' => 'esterno',
				'motivo' => 'il percorso esce dalla cartella del documento.',
			],
		]));

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(1, $this->servizio->numeroRichieste(), 'nessun lavoro deve essere creato');
		self::assertSame(Notifiche::SOGGETTO_FALLITA, $this->ultimaNotifica()['soggetto']);
		self::assertSame(Fallita::MOTIVO_RIFERIMENTI_NON_ALLEGABILI, $this->motivoNotificato());
		$elenco = $this->elencoNotificato();
		self::assertCount(2, $elenco, 'vanno elencati tutti, non solo il primo');
		self::assertStringContainsString('https://example.com/logo.png', $elenco[0]);
		self::assertStringContainsString('Typst non scarica file remoti', $elenco[0]);
	}

	public function testTypstGrezzoVieneRifiutato(): void {
		$documento = $this->documento();

		$this->servizio->prepara($this->rispostaDipendenze([], [], true));

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(1, $this->servizio->numeroRichieste());
		self::assertSame(Fallita::MOTIVO_TYPST_GREZZO, $this->motivoNotificato());
	}

	public function testFileMancantiElencatiTuttiSenzaCreareIlLavoro(): void {
		$documento = $this->documento(vicini: ['immagini/schema.png' => $this->fileFinto('schema.png', 'PNG')]);

		$this->servizio->prepara($this->rispostaDipendenze([
			['percorso' => 'immagini/schema.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			['percorso' => 'immagini/manca.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			['percorso' => 'logo.svg', 'tipo' => 'doc-icon', 'stato' => 'da_allegare'],
		]));

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(1, $this->servizio->numeroRichieste());
		self::assertSame(Fallita::MOTIVO_FILE_MANCANTI, $this->motivoNotificato());
		self::assertSame(['immagini/manca.png', 'logo.svg'], $this->elencoNotificato());
	}

	public function testUnInvioTroppoGrandeNonParte(): void {
		$this->config->salva(new ImpostazioniAdmin(
			'https',
			'impagina.interno',
			8443,
			'',
			dimensioneMassimaMb: 1,
		));
		$documento = $this->documento(
			"# Relazione\n",
			['grande.png' => $this->fileFinto('grande.png', str_repeat('x', 2 * 1024 * 1024))],
		);

		$this->servizio->prepara($this->rispostaDipendenze([
			['percorso' => 'grande.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
		]));

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(1, $this->servizio->numeroRichieste());
		self::assertSame(Fallita::MOTIVO_TROPPO_GRANDE, $this->motivoNotificato());
	}

	// --- errori del servizio ----------------------------------------------

	public function testLavoroFallitoRiportaIlMessaggioDelDocumento(): void {
		$documento = $this->documento("# Solo testo\n");

		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('fallito', 'errore', [
				[
					'sorgente' => 'relazione.md',
					'esito' => 'errore',
					'errore' => [
						'codice' => 'conversione_fallita',
						'messaggio' => 'typst ha segnalato un errore.',
						'suggerimento' => 'Controlla la tabella a pagina 2.',
					],
				],
			]),
			new RispostaFinta(204),
		);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(Fallita::MOTIVO_DOCUMENTO, $this->motivoNotificato());
		self::assertSame(
			'typst ha segnalato un errore. Controlla la tabella a pagina 2.',
			$this->ultimaNotifica()['parametri']['dettaglio'],
		);
	}

	public function testAttesaSuperataCancellaIlLavoro(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno', 8443, '', attesaMassima: 10));
		$documento = $this->documento("# Solo testo\n");

		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('in_corso', retryAfter: 1),
			new RispostaFinta(204),
		);

		// Fra la lettura della scadenza e il controllo passa piu' tempo
		// dell'attesa massima: il lavoro viene abbandonato.
		$this->passoDelTempo = 3600;
		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(Fallita::MOTIVO_TEMPO_SCADUTO, $this->motivoNotificato());
		self::assertSame('DELETE', $this->servizio->richiesta(3)->metodo);
	}

	public function testServizioIrraggiungibileNotificaIlMotivoGiusto(): void {
		$documento = $this->documento("# Solo testo\n");
		$this->servizio->prepara(new RuntimeException('Connection refused'));

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(Fallita::MOTIVO_RETE, $this->motivoNotificato());
	}

	public function testTokenNonValidoDiventaProblemaDiConfigurazione(): void {
		$documento = $this->documento("# Solo testo\n");
		$this->servizio->prepara(RispostaFinta::errore(401, 'non_autorizzato', 'chiave non valida.'));

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(Fallita::MOTIVO_CONFIGURAZIONE, $this->motivoNotificato());
	}

	public function testRisultatoCheNonEUnPdfNonVieneSalvato(): void {
		$documento = $this->documento("# Solo testo\n");

		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('completato', 'ok', [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf'],
			], self::risultatoPdf()),
			new RispostaFinta(200, '<html>errore del proxy</html>', ['Content-Type' => 'application/pdf']),
		);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame([], $this->nomiCreati());
		self::assertSame(Fallita::MOTIVO_RISULTATO_NON_PDF, $this->motivoNotificato());
	}

	// --- controlli locali --------------------------------------------------

	public function testFileSparitoNotificaSenzaChiamareIlServizio(): void {
		$this->conversione(null)->esegui('anna', 7);

		self::assertSame(0, $this->servizio->numeroRichieste());
		self::assertSame(Fallita::MOTIVO_FILE_NON_TROVATO, $this->motivoNotificato());
	}

	public function testCartellaNonScrivibileNotificaSenzaChiamareIlServizio(): void {
		$documento = $this->documento("# Solo testo\n", [], Constants::PERMISSION_READ);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(0, $this->servizio->numeroRichieste());
		self::assertSame(Fallita::MOTIVO_PERMESSI, $this->motivoNotificato());
	}

	public function testSenzaConfigurazioneNonSiChiamaIlServizio(): void {
		$this->config->cancellaToken();
		$documento = $this->documento("# Solo testo\n");

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(0, $this->servizio->numeroRichieste());
		self::assertSame(Fallita::MOTIVO_CONFIGURAZIONE, $this->motivoNotificato());
	}

	public function testIFileTemporaneiVengonoRipulitiAncheInCasoDiErrore(): void {
		$documento = $this->documento(vicini: ['immagini/schema.png' => $this->fileFinto('schema.png', 'PNG')]);

		$this->servizio->prepara(
			$this->rispostaDipendenze([
				['percorso' => 'immagini/schema.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			]),
			RispostaFinta::errore(500, 'errore_interno', 'guasto.'),
		);

		$this->conversione($documento)->esegui('anna', 7);

		self::assertSame(Fallita::MOTIVO_SERVIZIO, $this->motivoNotificato());
		foreach ($this->temporaneiTest as $percorso) {
			self::assertFileDoesNotExist($percorso);
		}
	}

	// --- conversione immediata ---------------------------------------------

	private function prepararePercorsoFelice(): void {
		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('completato', 'ok', [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf'],
			], self::risultatoPdf()),
			new RispostaFinta(200, "%PDF-1.7\nfinto", ['Content-Type' => 'application/pdf']),
			new RispostaFinta(204),
		);
	}

	public function testSubitoRestituisceIlPdfCreatoENotifica(): void {
		$this->prepararePercorsoFelice();

		$esito = $this->conversione($this->documento("# Solo testo\n"))->subito('anna', 7, 20);

		self::assertSame(EsitoConversione::COMPLETATA, $esito->stato);
		self::assertSame(99, $esito->fileIdPdf);
		self::assertSame('relazione.pdf', $esito->nomePdf);
		self::assertSame(['relazione.pdf'], $this->nomiCreati());
		self::assertSame(Notifiche::SOGGETTO_RIUSCITA, $this->ultimaNotifica()['soggetto']);
	}

	public function testSubitoConServizioOccupatoRimandaSenzaNotificare(): void {
		$occupato = RispostaFinta::errore(503, 'servizio_occupato', 'coda piena.', null, ['Retry-After' => '1']);
		$this->servizio->prepara($occupato, $occupato, $occupato);

		$esito = $this->conversione($this->documento("# Solo testo\n"))->subito('anna', 7, 20);

		self::assertSame(EsitoConversione::RIMANDATA, $esito->stato);
		self::assertSame(Fallita::MOTIVO_OCCUPATO, $esito->motivo);
		self::assertSame([], $this->notificheInviate);
		self::assertSame([], $this->nomiCreati());
	}

	public function testSubitoConServizioIrraggiungibileRimanda(): void {
		$this->servizio->prepara(new RuntimeException('Connection refused'));

		$esito = $this->conversione($this->documento("# Solo testo\n"))->subito('anna', 7, 20);

		self::assertSame(EsitoConversione::RIMANDATA, $esito->stato);
		self::assertSame(Fallita::MOTIVO_RETE, $esito->motivo);
		self::assertSame([], $this->notificheInviate);
	}

	public function testSubitoTroppoLentaCancellaIlLavoroERimanda(): void {
		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('in_corso', retryAfter: 1),
			new RispostaFinta(204),
		);

		// Fra un controllo e l'altro passa piu' dell'attesa concessa.
		$this->passoDelTempo = 30;
		$esito = $this->conversione($this->documento("# Solo testo\n"))->subito('anna', 7, 20);

		self::assertSame(EsitoConversione::RIMANDATA, $esito->stato);
		self::assertSame(Fallita::MOTIVO_TEMPO_SCADUTO, $esito->motivo);
		self::assertSame('DELETE', $this->servizio->richiesta(3)->metodo, 'il lavoro sul servizio va cancellato');
		self::assertSame([], $this->notificheInviate);
	}

	public function testSubitoNonAspettaPiuDellAttesaConfigurata(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno', 8443, '', attesaMassima: 10));
		$this->servizio->prepara(
			$this->rispostaDipendenze([]),
			$this->rispostaLavoroCreato(),
			$this->rispostaStato('in_corso', retryAfter: 1),
			new RispostaFinta(204),
		);

		// 15 secondi stanno nei 20 chiesti ma non nei 10 configurati.
		$this->passoDelTempo = 15;
		$esito = $this->conversione($this->documento("# Solo testo\n"))->subito('anna', 7, 20);

		self::assertSame(EsitoConversione::RIMANDATA, $esito->stato);
	}

	public function testSubitoConErroreDefinitivoFallisceENotifica(): void {
		$this->servizio->prepara($this->rispostaDipendenze([
			['percorso' => 'manca.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
		]));

		$esito = $this->conversione($this->documento())->subito('anna', 7, 20);

		self::assertSame(EsitoConversione::FALLITA, $esito->stato);
		self::assertSame(Fallita::MOTIVO_FILE_MANCANTI, $esito->motivo);
		self::assertSame(['elenco' => ['manca.png']], $esito->parametri);
		self::assertSame(Fallita::MOTIVO_FILE_MANCANTI, $this->motivoNotificato());
	}

	public function testInBackgroundIlServizioOccupatoRestaUnFallimento(): void {
		$occupato = RispostaFinta::errore(503, 'servizio_occupato', 'coda piena.', null, ['Retry-After' => '1']);
		$this->servizio->prepara($occupato, $occupato, $occupato);

		$this->conversione($this->documento("# Solo testo\n"))->esegui('anna', 7);

		self::assertSame(Fallita::MOTIVO_OCCUPATO, $this->motivoNotificato());
	}
}
