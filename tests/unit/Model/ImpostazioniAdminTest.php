<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Model;

use OCA\Impagina\Exception\ConfigurazioneNonValidaException;
use OCA\Impagina\Model\ImpostazioniAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImpostazioniAdminTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private static function valide(): array {
		return [
			'schema' => 'https',
			'host' => 'impagina.interno',
			'porta' => 8443,
			'percorsoBase' => '/impagina/',
			'verificaTls' => true,
			'timeoutConnessione' => 10,
			'timeoutRichiesta' => 60,
			'template' => 'relazioni',
			'toc' => true,
			'cover' => 'sempre',
			'watermark' => 'BOZZA',
			'dimensioneMassimaMb' => 50,
			'attesaMassima' => 180,
			'gruppi' => ['redazione', 'redazione'],
		];
	}

	public function testAccettaUnaConfigurazioneCompleta(): void {
		$impostazioni = ImpostazioniAdmin::daArray(self::valide());

		self::assertSame('impagina.interno', $impostazioni->host);
		self::assertSame(8443, $impostazioni->porta);
		self::assertSame('impagina', $impostazioni->percorsoBase);
		// I duplicati nei gruppi vengono tolti.
		self::assertSame(['redazione'], $impostazioni->gruppi);
	}

	public function testIValoriNumericiArrivanoAncheComeTesto(): void {
		$dati = self::valide();
		$dati['porta'] = '8443';
		$dati['attesaMassima'] = '240';

		$impostazioni = ImpostazioniAdmin::daArray($dati);

		self::assertSame(8443, $impostazioni->porta);
		self::assertSame(240, $impostazioni->attesaMassima);
	}

	public function testPortaVuotaSignificaQuellaPredefinita(): void {
		$dati = self::valide();
		$dati['porta'] = '';

		self::assertNull(ImpostazioniAdmin::daArray($dati)->porta);
	}

	public function testCoverDiventaIlTerzoStatoDelleOpzioni(): void {
		$dati = self::valide();

		$dati['cover'] = ImpostazioniAdmin::COVER_TEMPLATE;
		self::assertNull(ImpostazioniAdmin::daArray($dati)->opzioni()->cover());

		$dati['cover'] = ImpostazioniAdmin::COVER_SEMPRE;
		self::assertTrue(ImpostazioniAdmin::daArray($dati)->opzioni()->cover());

		$dati['cover'] = ImpostazioniAdmin::COVER_MAI;
		self::assertFalse(ImpostazioniAdmin::daArray($dati)->opzioni()->cover());
	}

	public function testCostruisceLaConnessioneConIlTokenPassato(): void {
		$connessione = ImpostazioniAdmin::daArray(self::valide())->connessione('imp_x');

		self::assertSame('https://impagina.interno:8443/impagina', $connessione->base());
		self::assertSame('imp_x', $connessione->token());
		self::assertSame(10, $connessione->timeoutConnessione());
	}

	public function testLInterfacciaNonRiceveIlToken(): void {
		$perInterfaccia = ImpostazioniAdmin::daArray(self::valide())->perInterfaccia();

		self::assertArrayNotHasKey('token', $perInterfaccia);
		self::assertSame('impagina.interno', $perInterfaccia['host']);
	}

	public function testDimensioneInByte(): void {
		self::assertSame(50 * 1024 * 1024, ImpostazioniAdmin::daArray(self::valide())->dimensioneMassimaByte());
	}

	#[DataProvider('valoriRifiutati')]
	public function testRifiutaIValoriNonValidi(string $campo, mixed $valore): void {
		$dati = self::valide();
		/** @psalm-suppress MixedAssignment il valore e' deliberatamente di tipo qualunque */
		$dati[$campo] = $valore;

		try {
			ImpostazioniAdmin::daArray($dati);
			self::fail("atteso un rifiuto per $campo");
		} catch (ConfigurazioneNonValidaException $e) {
			self::assertSame($campo, $e->campo());
		}
	}

	/**
	 * @return iterable<string, array{string, mixed}>
	 */
	public static function valoriRifiutati(): iterable {
		yield 'protocollo inventato' => ['schema', 'ftp'];
		yield 'host vuoto' => ['host', '   '];
		yield 'host con schema' => ['host', 'https://impagina.interno'];
		yield 'host con percorso' => ['host', 'impagina.interno/api'];
		yield 'host con porta' => ['host', 'impagina.interno:8443'];
		yield 'host con spazio' => ['host', 'impagina interno'];
		yield 'porta zero' => ['porta', 0];
		yield 'porta oltre il massimo' => ['porta', 70000];
		yield 'porta non numerica' => ['porta', 'ottomila'];
		yield 'percorso con risalita' => ['percorsoBase', '/impagina/../etc'];
		yield 'timeout di connessione nullo' => ['timeoutConnessione', 0];
		yield 'timeout di richiesta esagerato' => ['timeoutRichiesta', 6000];
		yield 'template con caratteri strani' => ['template', 'relazioni/../x'];
		yield 'template vuoto' => ['template', ''];
		yield 'cover inventata' => ['cover', 'forse'];
		yield 'filigrana troppo lunga' => ['watermark', str_repeat('a', 101)];
		yield 'dimensione nulla' => ['dimensioneMassimaMb', 0];
		yield 'attesa troppo breve' => ['attesaMassima', 5];
		yield 'attesa troppo lunga' => ['attesaMassima', 100000];
		yield 'gruppi non elenco' => ['gruppi', 'redazione'];
		yield 'gruppo vuoto' => ['gruppi', ['']];
	}

	#[DataProvider('hostAmmessi')]
	public function testAccettaNomiHostEIndirizziIp(string $host): void {
		$dati = self::valide();
		$dati['host'] = $host;

		self::assertSame($host, ImpostazioniAdmin::daArray($dati)->host);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function hostAmmessi(): iterable {
		yield 'nome semplice' => ['impagina'];
		yield 'nome qualificato' => ['impagina.example.internal'];
		yield 'indirizzo IPv4' => ['192.0.2.30'];
		yield 'indirizzo IPv6' => ['fd00::1'];
		yield 'nome del container' => ['impagina-api'];
	}
}
