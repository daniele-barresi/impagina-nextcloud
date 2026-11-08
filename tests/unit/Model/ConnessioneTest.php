<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Model;

use OCA\Impagina\Model\Connessione;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnessioneTest extends TestCase {
	/**
	 * @param ?int $porta
	 */
	#[DataProvider('coordinate')]
	public function testCostruisceLUrlDiBase(
		string $schema,
		string $host,
		?int $porta,
		string $percorsoBase,
		string $atteso,
	): void {
		$connessione = Connessione::da($schema, $host, $porta, $percorsoBase, 'imp_x');

		self::assertSame($atteso, $connessione->base());
	}

	/**
	 * @return iterable<string, array{string, string, ?int, string, string}>
	 */
	public static function coordinate(): iterable {
		yield 'https senza porta' => ['https', 'impagina.interno', null, '', 'https://impagina.interno'];
		yield 'porta predefinita omessa' => ['https', 'impagina.interno', 443, '', 'https://impagina.interno'];
		yield 'porta non standard' => ['https', 'impagina.interno', 8443, '', 'https://impagina.interno:8443'];
		yield 'http con porta' => ['http', '192.0.2.30', 8080, '', 'http://192.0.2.30:8080'];
		yield 'http porta 80 omessa' => ['http', '192.0.2.30', 80, '', 'http://192.0.2.30'];
		yield 'con path di base' => ['https', 'proxy.interno', null, '/impagina/', 'https://proxy.interno/impagina'];
		yield 'path senza barre' => ['https', 'proxy.interno', null, 'api', 'https://proxy.interno/api'];
		yield 'path solo barra' => ['https', 'proxy.interno', null, '/', 'https://proxy.interno'];
	}

	public function testComponeGliEndpointSenzaBarreDoppie(): void {
		$connessione = Connessione::da('https', 'proxy.interno', null, '/impagina', 'imp_x');

		self::assertSame('https://proxy.interno/impagina/salute', $connessione->url('/salute'));
		self::assertSame('https://proxy.interno/impagina/lavori/abc', $connessione->url('lavori/abc'));
	}

	public function testConTokenNonToccaIlRestoDellaConfigurazione(): void {
		$connessione = new Connessione('https://impagina.interno', 'imp_vecchio', false, 5, 30);

		$altra = $connessione->conToken('imp_nuovo');

		self::assertSame('imp_nuovo', $altra->token());
		self::assertSame('imp_vecchio', $connessione->token());
		self::assertSame('https://impagina.interno', $altra->base());
		self::assertFalse($altra->verificaTls());
		self::assertSame(5, $altra->timeoutConnessione());
		self::assertSame(30, $altra->timeoutRichiesta());
	}

	public function testSaSeIlTokenEStatoImpostato(): void {
		self::assertTrue((new Connessione('https://x', 'imp_x'))->haToken());
		self::assertFalse((new Connessione('https://x', ''))->haToken());
	}
}
