<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Controller;

use OCA\Impagina\Controller\SettingsController;
use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Service\ImpaginaClient;
use OCA\Impagina\Tests\Finzione\AttesaFinta;
use OCA\Impagina\Tests\Finzione\ConfigurazioneFinta;
use OCA\Impagina\Tests\Finzione\RispostaFinta;
use OCA\Impagina\Tests\Finzione\ServizioFinto;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SettingsControllerTest extends TestCase {
	use ConfigurazioneFinta;

	private ServizioFinto $servizio;
	private ConfigService $config;
	private SettingsController $controller;

	#[\Override]
	protected function setUp(): void {
		$this->servizio = new ServizioFinto();
		$this->config = new ConfigService($this->appConfigFinto());

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $testo, array $parametri = []): string {
				if ($parametri === []) {
					return $testo;
				}

				return vsprintf($testo, array_map(
					static fn (mixed $parametro): string => is_scalar($parametro) ? (string)$parametro : '',
					$parametri,
				));
			},
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singolare, string $plurale, int $quanti): string => str_replace(
				'%n',
				(string)$quanti,
				$quanti === 1 ? $singolare : $plurale,
			),
		);

		$this->controller = new SettingsController(
			'impagina',
			$this->createStub(IRequest::class),
			$this->config,
			new ImpaginaClient($this->servizio, new NullLogger(), new AttesaFinta()),
			$l10n,
			new NullLogger(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function impostazioni(): array {
		return [
			'schema' => 'https',
			'host' => 'impagina.interno',
			'porta' => 8443,
			'percorsoBase' => '',
			'verificaTls' => true,
			'timeoutConnessione' => 10,
			'timeoutRichiesta' => 60,
			'template' => 'relazioni',
			'toc' => true,
			'cover' => ImpostazioniAdmin::COVER_TEMPLATE,
			'watermark' => '',
			'dimensioneMassimaMb' => 50,
			'attesaMassima' => 180,
			'gruppi' => ['redazione'],
		];
	}

	/**
	 * @param mixed $dati
	 * @return array<string, mixed>
	 */
	private static function dati($dati): array {
		self::assertIsArray($dati);

		/** @var array<string, mixed> */
		return $dati;
	}

	// --- salvataggio ------------------------------------------------------

	public function testSalvaLaConfigurazioneEIlToken(): void {
		$risposta = $this->controller->salva(self::impostazioni(), 'imp_segretissimo');

		$dati = self::dati($risposta->getData());
		self::assertTrue($dati['tokenImpostato']);
		self::assertIsArray($dati['impostazioni']);
		self::assertArrayNotHasKey('token', $dati['impostazioni']);
		self::assertSame('impagina.interno', $this->config->impostazioni()->host);
		self::assertSame('imp_segretissimo', $this->config->token());
		self::assertTrue($this->configurazioneSensibile(ConfigService::CHIAVE_TOKEN));
	}

	public function testUnTokenVuotoLasciaQuelloGiaSalvato(): void {
		$this->config->salvaToken('imp_vecchio');

		$this->controller->salva(self::impostazioni(), '');

		self::assertSame('imp_vecchio', $this->config->token());
	}

	public function testCancellaTokenSuRichiesta(): void {
		$this->config->salvaToken('imp_vecchio');

		$risposta = $this->controller->salva(self::impostazioni(), null, true);

		self::assertFalse($this->config->haToken());
		self::assertFalse(self::dati($risposta->getData())['tokenImpostato']);
	}

	public function testRifiutaUnHostNonValidoSenzaSalvareNulla(): void {
		$impostazioni = self::impostazioni();
		$impostazioni['host'] = 'https://impagina.interno';

		try {
			$this->controller->salva($impostazioni, 'imp_segretissimo');
			self::fail('atteso un rifiuto');
		} catch (OCSBadRequestException $e) {
			self::assertStringContainsString('host', $e->getMessage());
		}

		self::assertSame('', $this->config->impostazioni()->host);
		self::assertFalse($this->config->haToken());
	}

	public function testRifiutaUnTokenTroppoCorto(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->controller->salva(self::impostazioni(), 'imp_');
	}

	// --- verifica della connessione ---------------------------------------

	public function testVerificaInDuePassiRiuscita(): void {
		$this->servizio->prepara(
			RispostaFinta::json(200, [
				'stato' => 'ok', 'versione' => '1.2.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
			]),
			RispostaFinta::json(200, ['template' => ['company', 'relazioni']]),
		);

		$dati = self::dati($this->controller->verifica(self::impostazioni(), 'imp_segretissimo')->getData());

		self::assertIsArray($dati['servizio']);
		self::assertSame('ok', $dati['servizio']['stato']);
		self::assertNull($dati['servizio']['avviso']);
		self::assertIsArray($dati['token']);
		self::assertSame('ok', $dati['token']['stato']);
		self::assertSame(['company', 'relazioni'], $dati['token']['template']);
		self::assertStringContainsString('2', (string)$dati['token']['messaggio']);
	}

	public function testLaVerificaUsaIValoriDellaPaginaNonQuelliSalvati(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'vecchio.interno'));
		$this->servizio->prepara(
			RispostaFinta::json(200, [
				'stato' => 'ok', 'versione' => '1.2.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
			]),
			RispostaFinta::json(200, ['template' => ['company']]),
		);

		$this->controller->verifica(self::impostazioni(), 'imp_segretissimo');

		self::assertSame('https://impagina.interno:8443/salute', $this->servizio->richiesta(0)->url);
		self::assertSame(
			'Bearer imp_segretissimo',
			$this->servizio->richiesta(1)->intestazione('Authorization'),
		);
	}

	public function testServizioIrraggiungibileNonProvaIlToken(): void {
		$this->servizio->prepara(new RuntimeException('Connection refused'));

		$dati = self::dati($this->controller->verifica(self::impostazioni(), 'imp_segretissimo')->getData());

		self::assertIsArray($dati['servizio']);
		self::assertSame('errore', $dati['servizio']['stato']);
		self::assertIsArray($dati['token']);
		self::assertSame('non_provato', $dati['token']['stato']);
		self::assertSame(1, $this->servizio->numeroRichieste());
	}

	public function testTokenRifiutatoRestaDistintoDalServizioRaggiunto(): void {
		$this->servizio->prepara(
			RispostaFinta::json(200, [
				'stato' => 'ok', 'versione' => '1.2.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
			]),
			RispostaFinta::errore(401, 'non_autorizzato', 'chiave non valida.'),
		);

		$dati = self::dati($this->controller->verifica(self::impostazioni(), 'imp_sbagliato')->getData());

		self::assertIsArray($dati['servizio']);
		self::assertSame('ok', $dati['servizio']['stato']);
		self::assertIsArray($dati['token']);
		self::assertSame('errore', $dati['token']['stato']);
		self::assertStringContainsString('chiave non valida.', (string)$dati['token']['messaggio']);
	}

	public function testSenzaTokenLaVerificaSiFermaAlPrimoPasso(): void {
		$this->servizio->prepara(RispostaFinta::json(200, [
			'stato' => 'ok', 'versione' => '1.2.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
		]));

		$dati = self::dati($this->controller->verifica(self::impostazioni(), null)->getData());

		self::assertIsArray($dati['token']);
		self::assertSame('mancante', $dati['token']['stato']);
		self::assertSame(1, $this->servizio->numeroRichieste());
	}

	public function testLaSerie2DelServizioNonProduceAvvisi(): void {
		$this->servizio->prepara(
			RispostaFinta::json(200, [
				'stato' => 'ok', 'versione' => '2.0.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
			]),
			RispostaFinta::json(200, ['template' => ['company']]),
		);

		$dati = self::dati($this->controller->verifica(self::impostazioni(), 'imp_segretissimo')->getData());

		self::assertIsArray($dati['servizio']);
		self::assertNull($dati['servizio']['avviso']);
		self::assertSame('2.0.0', $dati['servizio']['versione']);
	}

	public function testAvvisaSeLaVersioneMajorDelServizioNonESupportata(): void {
		$this->servizio->prepara(
			RispostaFinta::json(200, [
				'stato' => 'ok', 'versione' => '3.0.0', 'worker' => 1, 'in_coda' => 0, 'in_corso' => 0,
			]),
			RispostaFinta::json(200, ['template' => ['company']]),
		);

		$dati = self::dati($this->controller->verifica(self::impostazioni(), 'imp_segretissimo')->getData());

		self::assertIsArray($dati['servizio']);
		self::assertNotNull($dati['servizio']['avviso']);
		self::assertStringContainsString('3.0.0', (string)$dati['servizio']['avviso']);
	}

	public function testLaVerificaRifiutaValoriNonValidiPrimaDiChiamare(): void {
		$impostazioni = self::impostazioni();
		$impostazioni['porta'] = 70000;

		try {
			$this->controller->verifica($impostazioni, 'imp_segretissimo');
			self::fail('atteso un rifiuto');
		} catch (OCSBadRequestException) {
			self::assertSame(0, $this->servizio->numeroRichieste());
		}
	}

	// --- elenco dei template ---------------------------------------------

	public function testSenzaConfigurazioneLElencoDeiTemplateEVuoto(): void {
		$dati = self::dati($this->controller->template()->getData());

		self::assertSame([], $dati['template']);
		self::assertSame(0, $this->servizio->numeroRichieste());
	}

	public function testElencoDeiTemplateConLaConfigurazioneSalvata(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno'));
		$this->config->salvaToken('imp_segretissimo');
		$this->servizio->prepara(RispostaFinta::json(200, ['template' => ['company', 'relazioni']]));

		$dati = self::dati($this->controller->template()->getData());

		self::assertSame(['company', 'relazioni'], $dati['template']);
	}

	public function testUnErroreNellElencoDeiTemplateNonEFatale(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno'));
		$this->config->salvaToken('imp_segretissimo');
		$this->servizio->prepara(new RuntimeException('Connection refused'));

		$dati = self::dati($this->controller->template()->getData());

		self::assertSame([], $dati['template']);
		self::assertNotSame('', $dati['messaggio']);
	}
}
