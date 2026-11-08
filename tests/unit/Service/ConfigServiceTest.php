<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Service;

use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Tests\Finzione\ConfigurazioneFinta;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase {
	use ConfigurazioneFinta;

	private ConfigService $config;

	#[\Override]
	protected function setUp(): void {
		$this->config = new ConfigService($this->appConfigFinto());
	}

	public function testSenzaConfigurazioneIValoriSonoQuelliPredefiniti(): void {
		$impostazioni = $this->config->impostazioni();

		self::assertSame('https', $impostazioni->schema);
		self::assertSame('', $impostazioni->host);
		self::assertNull($impostazioni->porta);
		self::assertTrue($impostazioni->verificaTls);
		self::assertSame('company', $impostazioni->template);
		self::assertSame(ImpostazioniAdmin::COVER_TEMPLATE, $impostazioni->cover);
		self::assertSame([], $impostazioni->gruppi);
		self::assertFalse($this->config->configurato());
	}

	public function testSalvaERilegge(): void {
		$impostazioni = new ImpostazioniAdmin(
			'http',
			'impagina-api',
			8080,
			'impagina',
			false,
			5,
			30,
			'relazioni',
			true,
			ImpostazioniAdmin::COVER_MAI,
			'BOZZA',
			25,
			240,
			['redazione'],
		);

		$this->config->salva($impostazioni);
		$riletta = $this->config->impostazioni();

		self::assertEquals($impostazioni, $riletta);
	}

	public function testPortaAssenteSiSalvaComeZeroESiRileggeComeNulla(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno'));

		self::assertNull($this->config->impostazioni()->porta);
	}

	public function testIlTokenSiSalvaComeValoreSensibile(): void {
		$this->config->salvaToken('imp_segretissimo');

		self::assertSame('imp_segretissimo', $this->config->token());
		self::assertTrue($this->config->haToken());
		self::assertTrue(
			$this->configurazioneSensibile(ConfigService::CHIAVE_TOKEN),
			'il token deve essere scritto con il flag sensitive',
		);
	}

	public function testCancellaToken(): void {
		$this->config->salvaToken('imp_segretissimo');
		$this->config->cancellaToken();

		self::assertSame('', $this->config->token());
		self::assertFalse($this->config->haToken());
	}

	public function testConfiguratoRichiedeHostEToken(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno'));
		self::assertFalse($this->config->configurato());

		$this->config->salvaToken('imp_segretissimo');
		self::assertTrue($this->config->configurato());
	}

	public function testLaConnessioneUniscelmpostazioniEToken(): void {
		$this->config->salva(new ImpostazioniAdmin('https', 'impagina.interno', 8443, 'api'));
		$this->config->salvaToken('imp_segretissimo');

		$connessione = $this->config->connessione();

		self::assertSame('https://impagina.interno:8443/api', $connessione->base());
		self::assertSame('imp_segretissimo', $connessione->token());
	}

	public function testLeOpzioniDiConversioneVengonoDallaConfigurazione(): void {
		$this->config->salva(new ImpostazioniAdmin(
			template: 'relazioni',
			toc: true,
			cover: ImpostazioniAdmin::COVER_SEMPRE,
			watermark: 'BOZZA',
		));

		self::assertSame(
			['template' => 'relazioni', 'toc' => 'true', 'cover' => 'true', 'watermark' => 'BOZZA'],
			$this->config->opzioni()->campi(),
		);
	}
}
