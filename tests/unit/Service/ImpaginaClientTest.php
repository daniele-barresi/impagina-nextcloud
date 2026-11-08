<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Service;

use OCA\Impagina\Exception\ConfigurazioneException;
use OCA\Impagina\Exception\DocumentoException;
use OCA\Impagina\Exception\ErroreAppException;
use OCA\Impagina\Exception\LavoroNonTrovatoException;
use OCA\Impagina\Exception\LimiteException;
use OCA\Impagina\Exception\NonProntoException;
use OCA\Impagina\Exception\ReteException;
use OCA\Impagina\Exception\RispostaNonValidaException;
use OCA\Impagina\Exception\ServizioException;
use OCA\Impagina\Exception\ServizioOccupatoException;
use OCA\Impagina\Model\Allegato;
use OCA\Impagina\Model\Connessione;
use OCA\Impagina\Model\Lavoro;
use OCA\Impagina\Model\OpzioniConversione;
use OCA\Impagina\Service\ImpaginaClient;
use OCA\Impagina\Tests\Finzione\AttesaFinta;
use OCA\Impagina\Tests\Finzione\RispostaFinta;
use OCA\Impagina\Tests\Finzione\ServizioFinto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ImpaginaClientTest extends TestCase {
	private ServizioFinto $servizio;
	private AttesaFinta $attesa;
	private ImpaginaClient $client;
	private Connessione $connessione;

	/** @var list<string> */
	private array $temporanei = [];

	#[\Override]
	protected function setUp(): void {
		$this->servizio = new ServizioFinto();
		$this->attesa = new AttesaFinta();
		$this->client = new ImpaginaClient($this->servizio, new NullLogger(), $this->attesa);
		$this->connessione = new Connessione('https://impagina.interno:8443', 'imp_segretissimo');
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->temporanei as $percorso) {
			if (is_file($percorso)) {
				unlink($percorso);
			}
		}
		$this->temporanei = [];
	}

	// --- /salute ---------------------------------------------------------

	public function testSaluteLeggeStatoVersioneECoda(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'stato' => 'ok',
			'versione' => '1.2.0',
			'worker' => 1,
			'in_coda' => 2,
			'in_corso' => 1,
		]));

		$salute = $this->client->salute($this->connessione);

		self::assertSame('ok', $salute->stato());
		self::assertSame('1.2.0', $salute->versione());
		self::assertSame(1, $salute->worker());
		self::assertSame(2, $salute->inCoda());
		self::assertSame(1, $salute->inCorso());
		self::assertSame(1, $salute->major());
		self::assertTrue($salute->supportata());

		$richiesta = $this->servizio->richiesta();
		self::assertSame('GET', $richiesta->metodo);
		self::assertSame('https://impagina.interno:8443/salute', $richiesta->url);
	}

	public function testSaluteNonInviaIlToken(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'stato' => 'ok', 'versione' => '1.2.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
		]));

		$this->client->salute($this->connessione);

		self::assertNull($this->servizio->richiesta()->intestazione('Authorization'));
	}

	public function testOgniRichiestaEvitaHttp2EConsenteGliIndirizziLocali(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'stato' => 'ok', 'versione' => '1.2.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
		]));

		$this->client->salute($this->connessione);

		$richiesta = $this->servizio->richiesta();
		self::assertSame('1.1', $richiesta->opzione('version'));
		self::assertSame(['allow_local_address' => true], $richiesta->opzione('nextcloud'));
		self::assertFalse($richiesta->opzione('http_errors'));
		self::assertTrue($richiesta->opzione('verify'));
	}

	public function testServizioIrraggiungibileDiventaReteException(): void {
		$this->servizio->prepara(new RuntimeException('Connection refused'));

		$this->expectException(ReteException::class);
		$this->client->salute($this->connessione);
	}

	public function testRispostaNonJsonDiventaRispostaNonValida(): void {
		$this->servizio->prepara(new RispostaFinta(200, '<html>proxy</html>', ['Content-Type' => 'text/html']));

		$this->expectException(RispostaNonValidaException::class);
		$this->client->salute($this->connessione);
	}

	public function testCampoMancanteDiventaRispostaNonValida(): void {
		$this->servizio->prepara(RispostaFinta::json(200, ['stato' => 'ok', 'versione' => '1.2.0']));

		$this->expectException(RispostaNonValidaException::class);
		$this->client->salute($this->connessione);
	}

	// --- /template -------------------------------------------------------

	public function testTemplateInviaIlTokenERestituisceINomi(): void {
		$this->servizio->prepara(RispostaFinta::json(200, ['template' => ['company', 'relazioni']]));

		self::assertSame(['company', 'relazioni'], $this->client->template($this->connessione));
		self::assertSame(
			'Bearer imp_segretissimo',
			$this->servizio->richiesta()->intestazione('Authorization'),
		);
	}

	public function testTokenNonValidoDiventaProblemaDiConfigurazione(): void {
		$this->servizio->prepara(RispostaFinta::errore(401, 'non_autorizzato', 'chiave non valida.'));

		try {
			$this->client->template($this->connessione);
			self::fail('attesa una ConfigurazioneException');
		} catch (ConfigurazioneException $e) {
			self::assertSame('non_autorizzato', $e->codice());
			self::assertSame(401, $e->stato());
			self::assertStringNotContainsString('imp_segretissimo', $e->getMessage());
		}
	}

	// --- /dipendenze -----------------------------------------------------

	public function testDipendenzeInviaIlMarkdownComeTestoEInterpretaLaRisposta(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'documento' => 'relazione.md',
			'file' => [
				['percorso' => 'immagini/schema.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
				['percorso' => 'logo.svg', 'tipo' => 'doc-icon', 'stato' => 'da_allegare'],
			],
			'ignorati' => [
				[
					'riferimento' => 'https://example.com/logo.png',
					'stato' => 'remoto',
					'motivo' => 'Typst non scarica file remoti: scaricalo e allegalo.',
				],
			],
			'typst_grezzo' => false,
		]));

		$dipendenze = $this->client->dipendenze($this->connessione, "# Titolo\n\n![](immagini/schema.png)\n");

		self::assertSame(['immagini/schema.png', 'logo.svg'], $dipendenze->percorsi());
		self::assertFalse($dipendenze->typstGrezzo());
		self::assertCount(1, $dipendenze->ignorati());
		self::assertSame('remoto', $dipendenze->ignorati()[0]->stato());
		self::assertSame('relazione.md', $dipendenze->documento());

		$richiesta = $this->servizio->richiesta();
		self::assertSame('POST', $richiesta->metodo);
		self::assertSame('https://impagina.interno:8443/dipendenze', $richiesta->url);
		self::assertSame("# Titolo\n\n![](immagini/schema.png)\n", $richiesta->corpo());
		self::assertSame('text/markdown; charset=utf-8', $richiesta->intestazione('Content-Type'));
	}

	public function testFrontMatterNonValidoEUnProblemaDelDocumento(): void {
		$this->servizio->prepara(RispostaFinta::errore(
			422,
			'front_matter_non_valido',
			"metadato 'fontsize' non valido.",
			'Usa una lunghezza con unita\' di misura, per esempio 11pt.',
		));

		try {
			$this->client->dipendenze($this->connessione, "---\nfontsize: rosso\n---\n");
			self::fail('attesa una DocumentoException');
		} catch (DocumentoException $e) {
			$dettaglio = $e->dettaglio();
			self::assertNotNull($dettaglio);
			self::assertSame('front_matter_non_valido', $dettaglio->codice());
			self::assertSame("metadato 'fontsize' non valido.", $dettaglio->messaggio());
			self::assertSame('Usa una lunghezza con unita\' di misura, per esempio 11pt.', $dettaglio->suggerimento());
		}
	}

	// --- /lavori ---------------------------------------------------------

	public function testCreaLavoroCostruisceIlMultipartConIPercorsiDegliAllegati(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$png = $this->fileTemporaneo('PNG-finto');

		$this->servizio->prepara(RispostaFinta::json(202, [
			'id' => '169e201112dc4da9876768b16991ac02',
			'stato' => 'in_coda',
			'posizione_in_coda' => 1,
			'stato_url' => '/lavori/169e201112dc4da9876768b16991ac02',
			'risultato_url' => '/lavori/169e201112dc4da9876768b16991ac02/risultato',
		], ['Retry-After' => '2']));

		$lavoro = $this->client->creaLavoro(
			$this->connessione,
			'relazione.md',
			$md,
			[new Allegato('immagini/schema.png', $png)],
			new OpzioniConversione('relazioni', true, null, ''),
		);

		self::assertSame('169e201112dc4da9876768b16991ac02', $lavoro->id());
		self::assertSame(1, $lavoro->posizioneInCoda());
		self::assertSame(2, $lavoro->retryAfter());

		$richiesta = $this->servizio->richiesta();
		self::assertSame('https://impagina.interno:8443/lavori', $richiesta->url);

		$parti = $richiesta->parti();
		self::assertSame('documento', $parti[0]['name']);
		self::assertSame('relazione.md', $parti[0]['filename']);
		self::assertSame("# Relazione\n", $parti[0]['contents']);
		self::assertSame(['immagini/schema.png'], $richiesta->nomiAllegati());
		// Guzzle toglierebbe `immagini/` dal nome: il Content-Disposition e' nostro.
		self::assertSame([
			'form-data; name="documento"; filename="relazione.md"',
			'form-data; name="allegato"; filename="immagini/schema.png"',
		], $richiesta->disposizioni());
		// `cover` omessa (decide il template) e `watermark` vuota omessa.
		self::assertSame(['template' => 'relazioni', 'toc' => 'true'], $richiesta->campi());
	}

	public function testCreaLavoroProteggeVirgoletteEControlliNeiNomiDeiFile(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$png = $this->fileTemporaneo('PNG-finto');
		$this->servizio->prepara(RispostaFinta::json(202, [
			'id' => 'c' . str_repeat('0', 31),
			'stato' => 'in_coda',
			'stato_url' => '/lavori/x',
			'risultato_url' => '/lavori/x/risultato',
		]));

		$this->client->creaLavoro(
			$this->connessione,
			'la "relazione".md',
			$md,
			[new Allegato("città/a\"b\r\nX-Finta: 1.png", $png)],
			new OpzioniConversione(),
		);

		self::assertSame([
			'form-data; name="documento"; filename="la \"relazione\".md"',
			'form-data; name="allegato"; filename="città/a\"bX-Finta: 1.png"',
		], $this->servizio->richiesta()->disposizioni());
	}

	public function testCreaLavoroInviaCoverEWatermarkQuandoImpostate(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$this->servizio->prepara(RispostaFinta::json(202, [
			'id' => 'a' . str_repeat('0', 31),
			'stato' => 'in_coda',
			'stato_url' => '/lavori/x',
			'risultato_url' => '/lavori/x/risultato',
		]));

		$this->client->creaLavoro(
			$this->connessione,
			'relazione.md',
			$md,
			[],
			new OpzioniConversione('company', false, false, 'BOZZA'),
		);

		self::assertSame([
			'template' => 'company',
			'toc' => 'false',
			'cover' => 'false',
			'watermark' => 'BOZZA',
		], $this->servizio->richiesta()->campi());
	}

	public function testCreaLavoroRiprovaDopoUn503RispettandoRetryAfter(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$this->servizio->prepara(
			RispostaFinta::errore(503, 'coda_piena', 'coda piena.', null, ['Retry-After' => '3']),
			RispostaFinta::json(202, [
				'id' => 'b' . str_repeat('0', 31),
				'stato' => 'in_coda',
				'stato_url' => '/lavori/x',
				'risultato_url' => '/lavori/x/risultato',
			]),
		);

		$lavoro = $this->client->creaLavoro($this->connessione, 'relazione.md', $md, [], new OpzioniConversione());

		self::assertSame('b' . str_repeat('0', 31), $lavoro->id());
		self::assertSame(2, $this->servizio->numeroRichieste());
		self::assertSame([3], $this->attesa->attese());
	}

	public function testCreaLavoroSiArrendeDopoITentativiPrevisti(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$occupato = static fn (): RispostaFinta => RispostaFinta::errore(
			503,
			'server_occupato',
			'troppe richieste.',
			null,
			['Retry-After' => '60'],
		);
		$this->servizio->prepara($occupato(), $occupato(), $occupato());

		try {
			$this->client->creaLavoro($this->connessione, 'relazione.md', $md, [], new OpzioniConversione());
			self::fail('attesa una ServizioOccupatoException');
		} catch (ServizioOccupatoException $e) {
			self::assertSame('server_occupato', $e->codice());
		}

		self::assertSame(ImpaginaClient::RITENTATIVI_503 + 1, $this->servizio->numeroRichieste());
		// Il Retry-After del servizio viene limitato: non si aspetta un minuto.
		self::assertSame(
			array_fill(0, ImpaginaClient::RITENTATIVI_503, ImpaginaClient::ATTESA_MASSIMA),
			$this->attesa->attese(),
		);
	}

	public function testInvioTroppoGrandeDiventaLimiteException(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$this->servizio->prepara(RispostaFinta::errore(413, 'troppo_grande', 'richiesta oltre 50M.'));

		$this->expectException(LimiteException::class);
		$this->client->creaLavoro($this->connessione, 'relazione.md', $md, [], new OpzioniConversione());
	}

	public function testOpzioneNonValidaEUnErroreDellApp(): void {
		$md = $this->fileTemporaneo("# Relazione\n");
		$this->servizio->prepara(RispostaFinta::errore(400, 'opzione_non_valida', "opzione 'colore' sconosciuta."));

		$this->expectException(ErroreAppException::class);
		$this->client->creaLavoro($this->connessione, 'relazione.md', $md, [], new OpzioniConversione());
	}

	// --- /lavori/{id} ----------------------------------------------------

	public function testStatoInCorsoPortaIlRetryAfter(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'id' => 'c' . str_repeat('0', 31),
			'stato' => 'in_corso',
			'creato' => '2026-09-19T10:00:00Z',
			'opzioni' => ['template' => 'company'],
			'documenti' => [],
		], ['Retry-After' => '1']));

		$lavoro = $this->client->stato($this->connessione, 'c' . str_repeat('0', 31));

		self::assertSame(Lavoro::STATO_IN_CORSO, $lavoro->stato());
		self::assertFalse($lavoro->terminato());
		self::assertSame(1, $lavoro->retryAfter());
	}

	public function testStatoCompletatoDescriveIlRisultato(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'id' => 'd' . str_repeat('0', 31),
			'stato' => 'completato',
			'esito' => 'ok',
			'creato' => '2026-09-19T10:00:00Z',
			'scade' => '2026-09-19T11:00:00Z',
			'opzioni' => ['template' => 'company'],
			'documenti' => [
				['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf', 'avvisi' => ['font sostituito']],
			],
			'risultato' => [
				'url' => '/lavori/d/risultato',
				'tipo' => 'application/pdf',
				'nome' => 'relazione.pdf',
				'byte' => 12345,
			],
		]));

		$lavoro = $this->client->stato($this->connessione, 'd' . str_repeat('0', 31));

		self::assertTrue($lavoro->riuscito());
		$risultato = $lavoro->risultato();
		self::assertNotNull($risultato);
		self::assertTrue($risultato->ePdf());
		self::assertSame('relazione.pdf', $risultato->nome());
		self::assertSame(12345, $risultato->byte());
		self::assertSame(['font sostituito'], $lavoro->documenti()[0]->avvisi());
		self::assertSame('2026-09-19T11:00:00Z', $lavoro->scade());
	}

	public function testStatoFallitoPortaLErroreDelDocumento(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'id' => 'e' . str_repeat('0', 31),
			'stato' => 'fallito',
			'esito' => 'errore',
			'creato' => '2026-09-19T10:00:00Z',
			'opzioni' => [],
			'documenti' => [
				[
					'sorgente' => 'relazione.md',
					'esito' => 'errore',
					'codice_uscita' => 3,
					'errore' => [
						'codice' => 'conversione_fallita',
						'messaggio' => 'typst ha segnalato un errore.',
						'suggerimento' => 'Controlla la tabella a pagina 2.',
					],
				],
			],
		]));

		$lavoro = $this->client->stato($this->connessione, 'e' . str_repeat('0', 31));

		self::assertTrue($lavoro->terminato());
		self::assertFalse($lavoro->riuscito());
		$errore = $lavoro->erroreSignificativo();
		self::assertNotNull($errore);
		self::assertSame('conversione_fallita', $errore->codice());
		self::assertSame(
			'typst ha segnalato un errore. Controlla la tabella a pagina 2.',
			$errore->testo(),
		);
	}

	public function testLavoroInesistenteDiventaLavoroNonTrovato(): void {
		$this->servizio->prepara(RispostaFinta::errore(404, 'lavoro_non_trovato', 'lavoro non trovato.'));

		$this->expectException(LavoroNonTrovatoException::class);
		$this->client->stato($this->connessione, 'f' . str_repeat('0', 31));
	}

	// --- /lavori/{id}/risultato ------------------------------------------

	public function testRisultatoScaricaSuFileERiconosceIlPdf(): void {
		$destinazione = $this->percorsoTemporaneo();
		$this->servizio->prepara(new RispostaFinta(
			200,
			"%PDF-1.7\n...",
			['Content-Type' => 'application/pdf; charset=binary'],
		));

		$scaricato = $this->client->risultato($this->connessione, 'a' . str_repeat('0', 31), $destinazione);

		self::assertSame($destinazione, $this->servizio->richiesta()->sink());
		self::assertSame('application/pdf', $scaricato->tipo());
		self::assertTrue($scaricato->ePdf());
		self::assertSame(12, $scaricato->byte());
		self::assertFileExists($destinazione);
	}

	public function testRisultatoConTipoGiustoMaContenutoSbagliatoNonEUnPdf(): void {
		$destinazione = $this->percorsoTemporaneo();
		$this->servizio->prepara(new RispostaFinta(
			200,
			'<html>errore del proxy</html>',
			['Content-Type' => 'application/pdf'],
		));

		$scaricato = $this->client->risultato($this->connessione, 'a' . str_repeat('0', 31), $destinazione);

		self::assertTrue($scaricato->tipoDichiaratoPdf());
		self::assertFalse($scaricato->iniziaComePdf());
		self::assertFalse($scaricato->ePdf());
	}

	public function testRisultatoChiestoTroppoPrestoNonLasciaFileEDiceDiAspettare(): void {
		$destinazione = $this->percorsoTemporaneo();
		$this->servizio->prepara(RispostaFinta::errore(
			409,
			'non_pronto',
			'il lavoro non e\' ancora terminato.',
			null,
			['Retry-After' => '2'],
		));

		try {
			$this->client->risultato($this->connessione, 'a' . str_repeat('0', 31), $destinazione);
			self::fail('attesa una NonProntoException');
		} catch (NonProntoException $e) {
			self::assertSame(2, $e->retryAfter());
		}

		self::assertFileDoesNotExist($destinazione);
	}

	public function testRisultatoDiUnLavoroFallitoRiportaLErroreDelDocumento(): void {
		$destinazione = $this->percorsoTemporaneo();
		$this->servizio->prepara(RispostaFinta::errore(422, 'conversione_fallita', 'typst ha segnalato un errore.'));

		$this->expectException(DocumentoException::class);
		$this->client->risultato($this->connessione, 'a' . str_repeat('0', 31), $destinazione);
	}

	// --- DELETE /lavori/{id} ---------------------------------------------

	public function testEliminaAccetta204(): void {
		$this->servizio->prepara(new RispostaFinta(204));

		$this->client->elimina($this->connessione, 'a' . str_repeat('0', 31));

		self::assertSame('DELETE', $this->servizio->richiesta()->metodo);
	}

	public function testEliminaSePossibileIgnoraGliErrori(): void {
		$this->servizio->prepara(RispostaFinta::errore(404, 'lavoro_non_trovato', 'lavoro non trovato.'));

		$this->client->eliminaSePossibile($this->connessione, 'a' . str_repeat('0', 31));

		self::assertSame(1, $this->servizio->numeroRichieste());
	}

	// --- mappatura degli errori ------------------------------------------

	/**
	 * @param class-string $atteso
	 */
	#[DataProvider('codiciDiErrore')]
	public function testOgniCodiceFinisceNellaCategoriaGiusta(int $stato, string $codice, string $atteso): void {
		$this->servizio->prepara(RispostaFinta::errore($stato, $codice));

		try {
			$this->client->template($this->connessione);
			self::fail("attesa una $atteso per $codice");
		} catch (RuntimeException $e) {
			self::assertInstanceOf($atteso, $e, "codice $codice");
		}
	}

	/**
	 * @return iterable<string, array{int, string, class-string}>
	 */
	public static function codiciDiErrore(): iterable {
		yield 'non_autorizzato' => [401, 'non_autorizzato', ConfigurazioneException::class];
		yield 'template_non_trovato' => [422, 'template_non_trovato', ConfigurazioneException::class];
		yield 'front_matter_non_valido' => [422, 'front_matter_non_valido', DocumentoException::class];
		yield 'conversione_fallita' => [422, 'conversione_fallita', DocumentoException::class];
		yield 'input_non_trovato' => [422, 'input_non_trovato', DocumentoException::class];
		yield 'percorso_non_valido' => [400, 'percorso_non_valido', DocumentoException::class];
		yield 'tempo_scaduto' => [422, 'tempo_scaduto', DocumentoException::class];
		yield 'troppo_grande' => [413, 'troppo_grande', LimiteException::class];
		yield 'troppi_file' => [413, 'troppi_file', LimiteException::class];
		yield 'coda_piena' => [503, 'coda_piena', ServizioOccupatoException::class];
		yield 'server_occupato' => [503, 'server_occupato', ServizioOccupatoException::class];
		yield 'errore_interno' => [500, 'errore_interno', ServizioException::class];
		yield 'scrittura_fallita' => [500, 'scrittura_fallita', ServizioException::class];
		yield 'interrotto' => [500, 'interrotto', ServizioException::class];
		yield 'uso_errato' => [500, 'uso_errato', ErroreAppException::class];
		yield 'richiesta_non_valida' => [400, 'richiesta_non_valida', ErroreAppException::class];
		yield 'opzione_non_valida' => [400, 'opzione_non_valida', ErroreAppException::class];
		yield 'lunghezza_richiesta' => [411, 'lunghezza_richiesta', ErroreAppException::class];
		yield 'tipo_non_supportato' => [415, 'tipo_non_supportato', ErroreAppException::class];
		yield 'metodo_non_consentito' => [405, 'metodo_non_consentito', ErroreAppException::class];
		yield 'risorsa_non_trovata' => [404, 'risorsa_non_trovata', ErroreAppException::class];
		yield 'nessun_documento' => [422, 'nessun_documento', ErroreAppException::class];
		yield 'zip_non_valido' => [422, 'zip_non_valido', ErroreAppException::class];
		yield 'lavoro_non_trovato' => [404, 'lavoro_non_trovato', LavoroNonTrovatoException::class];
		yield 'non_pronto' => [409, 'non_pronto', NonProntoException::class];
		// Codice sconosciuto (servizio piu' recente dell'app): decide lo stato.
		yield 'codice ignoto 500' => [500, 'codice_del_futuro', ServizioException::class];
		yield 'codice ignoto 403' => [403, 'codice_del_futuro', ConfigurazioneException::class];
	}

	public function testRispostaDiErroreSenzaCorpoUsaLoStatoHttp(): void {
		$this->servizio->prepara(new RispostaFinta(502, 'Bad Gateway', ['Content-Type' => 'text/plain']));

		$this->expectException(ServizioException::class);
		$this->client->template($this->connessione);
	}

	public function testRetryAfterPuoEssereUnaDataHttp(): void {
		$fraDueSecondi = gmdate('D, d M Y H:i:s \G\M\T', time() + 2);
		$this->servizio->prepara(RispostaFinta::errore(
			409,
			'non_pronto',
			'non pronto.',
			null,
			['Retry-After' => $fraDueSecondi],
		));

		try {
			$this->client->risultato($this->connessione, 'a' . str_repeat('0', 31), $this->percorsoTemporaneo());
			self::fail('attesa una NonProntoException');
		} catch (NonProntoException $e) {
			$attesa = $e->retryAfter();
			self::assertNotNull($attesa);
			self::assertGreaterThanOrEqual(1, $attesa);
			self::assertLessThanOrEqual(2, $attesa);
		}
	}

	// --- utilita' ---------------------------------------------------------

	private function fileTemporaneo(string $contenuto): string {
		$percorso = $this->percorsoTemporaneo();
		file_put_contents($percorso, $contenuto);

		return $percorso;
	}

	private function percorsoTemporaneo(): string {
		$percorso = tempnam(sys_get_temp_dir(), 'impagina-test-');
		self::assertIsString($percorso);
		$this->temporanei[] = $percorso;

		return $percorso;
	}
}
