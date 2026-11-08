<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Model;

use OCA\Impagina\Model\OpzioniConversione;
use PHPUnit\Framework\TestCase;

final class OpzioniConversioneTest extends TestCase {
	public function testIValoriPredefinitiSonoQuelliDelServizio(): void {
		$opzioni = new OpzioniConversione();

		self::assertSame(['template' => 'company', 'toc' => 'false'], $opzioni->campi());
	}

	public function testCoverNullaSignificaDecideIlTemplateEQuindiSiOmette(): void {
		self::assertArrayNotHasKey('cover', (new OpzioniConversione('company', false, null))->campi());
		self::assertSame('true', (new OpzioniConversione('company', false, true))->campi()['cover']);
		self::assertSame('false', (new OpzioniConversione('company', false, false))->campi()['cover']);
	}

	public function testWatermarkVuotaOSoliSpaziSiOmette(): void {
		self::assertArrayNotHasKey('watermark', (new OpzioniConversione('company', false, null, null))->campi());
		self::assertArrayNotHasKey('watermark', (new OpzioniConversione('company', false, null, '   '))->campi());
	}

	public function testWatermarkVieneTroncataAlLimiteDelServizio(): void {
		$lunga = str_repeat('a', OpzioniConversione::WATERMARK_MAX + 20);

		$campi = (new OpzioniConversione('company', false, null, $lunga))->campi();

		self::assertSame(OpzioniConversione::WATERMARK_MAX, mb_strlen($campi['watermark']));
	}

	public function testTocDiventaTesto(): void {
		self::assertSame('true', (new OpzioniConversione('company', true))->campi()['toc']);
	}
}
