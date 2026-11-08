<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Controller;

use OCA\Impagina\BackgroundJob\ConvertJob;
use OCA\Impagina\Controller\ConvertController;
use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Service\AccessService;
use OCA\Impagina\Service\AttachmentCollector;
use OCA\Impagina\Service\Blocco;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Service\Conversione;
use OCA\Impagina\Service\ImpaginaClient;
use OCA\Impagina\Service\OutputNamer;
use OCA\Impagina\Tests\Finzione\AlberoFinto;
use OCA\Impagina\Tests\Finzione\AttesaFinta;
use OCA\Impagina\Tests\Finzione\ConfigurazioneFinta;
use OCA\Impagina\Tests\Finzione\NotificheFinte;
use OCA\Impagina\Tests\Finzione\RispostaFinta;
use OCA\Impagina\Tests\Finzione\ServizioFinto;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Constants;
use OCP\Files\File;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IMemcache;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Il controller con la conversione vera: servizio HTTP, filesystem, cache e
 * coda dei lavori sono simulati.
 */
final class ConvertControllerTest extends TestCase {
	use AlberoFinto;
	use ConfigurazioneFinta;
	use NotificheFinte;

	private ConfigService $config;
	private ServizioFinto $servizio;

	/** @var array<string, mixed> contenuto della cache distribuita simulata */
	private array $cache = [];

	/** @var list<string> */
	private array $temporaneiTest = [];

	/** @var list<array{classe: string, argomento: mixed}> */
	private array $accodati = [];

	private bool $utenteAbilitato = true;

	#[\Override]
	protected function setUp(): void {
		$this->servizio = new ServizioFinto();
		$this->config = new ConfigService($this->appConfigFinto());
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno'));
		$this->config->salvaToken('imp_segretissimo');
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->temporaneiTest as $percorso) {
			if (is_file($percorso)) {
				unlink($percorso);
			}
		}
		$this->pulisciAlbero();
	}

	private function controller(?File $documento): ConvertController {
		$utente = $this->createStub(IUser::class);
		$utente->method('getUID')->willReturn('anna');
		$sessione = $this->createStub(IUserSession::class);
		$sessione->method('getUser')->willReturn($utente);

		$gruppi = $this->createStub(IGroupManager::class);
		$gruppi->method('isAdmin')->willReturnCallback(fn (): bool => $this->utenteAbilitato);
		$gruppi->method('isInGroup')->willReturn(false);

		$lavori = $this->createStub(IJobList::class);
		$lavori->method('add')->willReturnCallback(
			function (string|\OCP\BackgroundJob\IJob $classe, mixed $argomento = null): void {
				$this->accodati[] = ['classe' => is_string($classe) ? $classe : $classe::class, 'argomento' => $argomento];
			},
		);
		$lavori->method('has')->willReturnCallback(
			function (string|\OCP\BackgroundJob\IJob $classe, mixed $argomento): bool {
				foreach ($this->accodati as $lavoro) {
					if ($lavoro['argomento'] === $argomento) {
						return true;
					}
				}

				return false;
			},
		);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$radice = $this->radiceFinta($documento);

		return new ConvertController(
			'impagina',
			$this->createStub(IRequest::class),
			$sessione,
			$radice,
			new AccessService($this->config, $gruppi),
			$this->config,
			$lavori,
			$this->conversione($radice),
			$this->blocco(),
			$l10n,
		);
	}

	private function conversione(\OCP\Files\IRootFolder $radice): Conversione {
		$temp = $this->createStub(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (string $suffisso = ''): string {
				$percorso = tempnam(sys_get_temp_dir(), 'impagina-ctrl-');
				self::assertIsString($percorso);
				$this->temporaneiTest[] = $percorso;

				return $percorso;
			},
		);

		return new Conversione(
			$radice,
			$this->config,
			new ImpaginaClient($this->servizio, new NullLogger(), new AttesaFinta()),
			new AttachmentCollector(),
			new OutputNamer(),
			$this->notificheFinte(),
			$temp,
			new AttesaFinta(),
			$this->createStub(ITimeFactory::class),
			new NullLogger(),
		);
	}

	private function blocco(): Blocco {
		$memcache = $this->createStub(IMemcache::class);
		$memcache->method('add')->willReturnCallback(function (string $chiave, mixed $valore): bool {
			if (array_key_exists($chiave, $this->cache)) {
				return false;
			}
			$this->cache[$chiave] = $valore;

			return true;
		});
		$memcache->method('remove')->willReturnCallback(function (string $chiave): bool {
			unset($this->cache[$chiave]);

			return true;
		});

		$fabbrica = $this->createStub(ICacheFactory::class);
		$fabbrica->method('createDistributed')->willReturn($memcache);

		return new Blocco($fabbrica);
	}

	private function servizioConverte(): void {
		$this->servizio->prepara(
			RispostaFinta::json(200, ['documento' => 'relazione.md', 'file' => [], 'ignorati' => [], 'typst_grezzo' => false]),
			RispostaFinta::json(202, [
				'id' => 'abc123',
				'stato' => 'in_coda',
				'stato_url' => '/lavori/abc123',
				'risultato_url' => '/lavori/abc123/risultato',
			]),
			RispostaFinta::json(200, [
				'id' => 'abc123',
				'stato' => 'completato',
				'esito' => 'ok',
				'creato' => '2026-09-24T10:00:00Z',
				'opzioni' => [],
				'documenti' => [['sorgente' => 'relazione.md', 'esito' => 'ok', 'pdf' => 'relazione.pdf']],
			]),
			new RispostaFinta(200, "%PDF-1.7\nfinto", ['Content-Type' => 'application/pdf']),
			new RispostaFinta(204),
		);
	}

	private function servizioOccupato(): void {
		$occupato = RispostaFinta::errore(503, 'servizio_occupato', 'coda piena.', null, ['Retry-After' => '1']);
		$this->servizio->prepara($occupato, $occupato, $occupato);
	}

	private function markdown(string $nome = 'relazione.md', int $permessi = Constants::PERMISSION_ALL): File {
		$cartella = $this->cartellaFinta([], $permessi);

		return $this->fileFinto($nome, "# Relazione\n", 7, true, $cartella);
	}

	public function testConverteSubitoERispondeConIlPdf(): void {
		$this->servizioConverte();

		$risposta = $this->controller($this->markdown())->convert(7);

		self::assertSame(Http::STATUS_CREATED, $risposta->getStatus());
		self::assertSame(['stato' => 'completata', 'fileId' => 99, 'nome' => 'relazione.pdf'], $risposta->getData());
		self::assertSame([], $this->accodati, 'niente lavoro in background');
		self::assertSame([], $this->cache, 'il blocco va rilasciato');
	}

	public function testServizioOccupatoAccodaIlLavoro(): void {
		$this->servizioOccupato();

		$risposta = $this->controller($this->markdown())->convert(7);

		self::assertSame(Http::STATUS_ACCEPTED, $risposta->getStatus());
		self::assertSame(['stato' => 'in_coda'], $risposta->getData());
		self::assertSame(
			[['classe' => ConvertJob::class, 'argomento' => ['userId' => 'anna', 'fileId' => 7]]],
			$this->accodati,
		);
		self::assertSame([], $this->cache);
	}

	public function testErroreDelDocumentoRispondeConLaSpiegazione(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'documento' => 'relazione.md',
			'file' => [],
			'ignorati' => [],
			'typst_grezzo' => true,
		]));

		$risposta = $this->controller($this->markdown())->convert(7);

		self::assertSame(Http::STATUS_OK, $risposta->getStatus());
		self::assertSame([
			'stato' => 'fallita',
			'messaggio' => 'The document contains raw Typst code, which the service refuses for security reasons.',
		], $risposta->getData());
		self::assertSame([], $this->accodati);
		self::assertSame([], $this->cache);
	}

	public function testUnLavoroGiaInCodaNonRipeteLaConversione(): void {
		$this->servizioOccupato();
		$controller = $this->controller($this->markdown());
		$controller->convert(7);

		$risposta = $controller->convert(7);

		self::assertSame(['stato' => 'gia_in_coda'], $risposta->getData());
		self::assertCount(1, $this->accodati);
		self::assertSame(3, $this->servizio->numeroRichieste(), 'il secondo clic non chiama il servizio');
	}

	public function testUnaConversioneInCorsoNonVieneRipetuta(): void {
		$controller = $this->controller($this->markdown());
		self::assertTrue($this->blocco()->prendi('anna', 7, 60));

		$risposta = $controller->convert(7);

		self::assertSame(['stato' => 'gia_in_corso'], $risposta->getData());
		self::assertSame(0, $this->servizio->numeroRichieste());
		self::assertSame([], $this->accodati);
	}

	public function testAccettaAncheLEstensioneMarkdown(): void {
		$this->servizioConverte();
		$risposta = $this->controller($this->markdown('relazione.markdown'))->convert(7);

		self::assertSame(Http::STATUS_CREATED, $risposta->getStatus());
	}

	public function testUnUtenteNonAbilitatoRiceveUnRifiuto(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno', gruppi: ['redazione']));
		$this->utenteAbilitato = false;

		$this->expectException(OCSForbiddenException::class);
		$this->controller($this->markdown())->convert(7);
	}

	public function testSenzaConfigurazioneRispondePreconditionFailed(): void {
		$this->config->cancellaToken();

		try {
			$this->controller($this->markdown())->convert(7);
			self::fail('atteso un rifiuto');
		} catch (OCSException $e) {
			self::assertSame(Http::STATUS_PRECONDITION_FAILED, $e->getCode());
		}

		self::assertSame([], $this->accodati);
	}

	public function testFileInesistente(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->controller(null)->convert(7);
	}

	public function testFileNonMarkdown(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->controller($this->markdown('relazione.txt'))->convert(7);
	}

	public function testCartellaSenzaPermessoDiCreazione(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->controller($this->markdown('relazione.md', Constants::PERMISSION_READ))->convert(7);
	}

	public function testFileNonLeggibile(): void {
		$cartella = $this->cartellaFinta();
		$file = $this->fileFinto('relazione.md', '# x', 7, false, $cartella);

		$this->expectException(OCSForbiddenException::class);
		$this->controller($file)->convert(7);
	}

	public function testMarkdownTroppoGrande(): void {
		$cartella = $this->cartellaFinta();
		$file = $this->fileFinto('relazione.md', str_repeat('x', 6 * 1024 * 1024), 7, true, $cartella);

		try {
			$this->controller($file)->convert(7);
			self::fail('atteso un rifiuto');
		} catch (OCSException $e) {
			self::assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $e->getCode());
		}
	}
}
