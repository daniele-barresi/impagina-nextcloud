<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Service;

use OCA\Impagina\Service\AttachmentCollector;
use OCA\Impagina\Tests\Finzione\AlberoFinto;
use PHPUnit\Framework\TestCase;

final class AttachmentCollectorTest extends TestCase {
	use AlberoFinto;

	private AttachmentCollector $collector;

	#[\Override]
	protected function setUp(): void {
		$this->collector = new AttachmentCollector();
	}

	#[\Override]
	protected function tearDown(): void {
		$this->pulisciAlbero();
	}

	public function testTrovaIFileReferenziati(): void {
		$cartella = $this->cartellaFinta([
			'immagini/schema.png' => $this->fileFinto('schema.png', 'PNG'),
			'logo.svg' => $this->fileFinto('logo.svg', 'SVG'),
		]);

		$risoluzione = $this->collector->risolvi($cartella, ['immagini/schema.png', 'logo.svg']);

		self::assertSame([], $risoluzione['mancanti']);
		self::assertSame(['immagini/schema.png', 'logo.svg'], array_keys($risoluzione['trovati']));
	}

	public function testElencaTuttiIMancantiNonSoloIlPrimo(): void {
		$cartella = $this->cartellaFinta(['presente.png' => $this->fileFinto('presente.png', 'PNG')]);

		$risoluzione = $this->collector->risolvi(
			$cartella,
			['manca1.png', 'presente.png', 'sotto/manca2.png'],
		);

		self::assertSame(['manca1.png', 'sotto/manca2.png'], $risoluzione['mancanti']);
		self::assertCount(1, $risoluzione['trovati']);
	}

	public function testRitrovaIlFileConLAltraNormalizzazioneUnicode(): void {
		if (!class_exists(\Normalizer::class)) {
			self::markTestSkipped('serve la normalizzazione Unicode');
		}

		// Il file e' salvato in NFD (e + accento combinante), il servizio
		// restituisce il percorso in NFC (un solo carattere).
		/** @psalm-suppress InternalMethod senza l'estensione intl risolve al polyfill di Symfony */
		$nfd = \Normalizer::normalize('perche\u{0301}.png', \Normalizer::FORM_D);
		self::assertIsString($nfd);
		$cartella = $this->cartellaFinta([$nfd => $this->fileFinto('perche.png', 'PNG')]);

		/** @psalm-suppress InternalMethod senza l'estensione intl risolve al polyfill di Symfony */
		$nfc = \Normalizer::normalize($nfd, \Normalizer::FORM_C);
		self::assertIsString($nfc);
		$risoluzione = $this->collector->risolvi($cartella, [$nfc]);

		self::assertSame([], $risoluzione['mancanti']);
		self::assertCount(1, $risoluzione['trovati']);
	}

	public function testRifiutaPercorsiCheEsconoDallaCartella(): void {
		$cartella = $this->cartellaFinta(['ok.png' => $this->fileFinto('ok.png', 'PNG')]);

		$risoluzione = $this->collector->risolvi($cartella, ['../fuori.png', '/etc/passwd', 'ok.png']);

		self::assertSame(['../fuori.png', '/etc/passwd'], $risoluzione['mancanti']);
		self::assertCount(1, $risoluzione['trovati']);
	}

	public function testUnFileNonLeggibileContaComeMancante(): void {
		$cartella = $this->cartellaFinta([
			'segreta.png' => $this->fileFinto('segreta.png', 'PNG', leggibile: false),
		]);

		$risoluzione = $this->collector->risolvi($cartella, ['segreta.png']);

		self::assertSame(['segreta.png'], $risoluzione['mancanti']);
	}
}
