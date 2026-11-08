<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Model;

use OCA\Impagina\Model\Campi;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CampiTest extends TestCase {
	public function testDaJsonRifiutaUnCorpoNonJson(): void {
		$this->expectException(UnexpectedValueException::class);
		Campi::daJson('<html></html>');
	}

	public function testDaJsonRifiutaUnValoreScalare(): void {
		$this->expectException(UnexpectedValueException::class);
		Campi::daJson('"solo una stringa"');
	}

	public function testStringaRifiutaUnCampoDiTipoSbagliato(): void {
		$this->expectException(UnexpectedValueException::class);
		Campi::stringa(['nome' => 42], 'nome');
	}

	public function testICampiOpzionaliTolleranoNulloEAssenza(): void {
		self::assertNull(Campi::stringaOpzionale([], 'x'));
		self::assertNull(Campi::stringaOpzionale(['x' => null], 'x'));
		self::assertNull(Campi::interoOpzionale(['x' => null], 'x'));
		self::assertNull(Campi::booleanoOpzionale([], 'x'));
	}

	public function testElencoOggettiRifiutaVociNonOggetto(): void {
		$this->expectException(UnexpectedValueException::class);
		Campi::elencoOggetti(['file' => ['non-un-oggetto']], 'file');
	}

	public function testEnumerazioneRifiutaValoriFuoriElenco(): void {
		$this->expectException(UnexpectedValueException::class);
		Campi::enumerazione(['stato' => 'sconosciuto'], 'stato', ['in_coda', 'completato']);
	}
}
