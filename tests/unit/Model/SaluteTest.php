<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Model;

use OCA\Impagina\Model\Salute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaluteTest extends TestCase {
	/**
	 * @return iterable<string, array{string, ?int, bool}>
	 */
	public static function versioni(): iterable {
		yield 'contratto di partenza' => ['1.2.0', 1, true];
		yield 'primo rilascio pubblico del servizio' => ['2.0.0', 2, true];
		yield 'minore successiva della 2' => ['2.3.1', 2, true];
		yield 'major mai provata' => ['3.0.0', 3, false];
		yield 'serie 0' => ['0.9.0', 0, false];
		yield 'versione non numerica' => ['sviluppo', null, false];
	}

	#[DataProvider('versioni')]
	public function testMajorESerieSupportata(string $versione, ?int $major, bool $supportata): void {
		$salute = new Salute('ok', $versione, 1, 0, 0);

		self::assertSame($major, $salute->major());
		self::assertSame($supportata, $salute->supportata());
	}
}
