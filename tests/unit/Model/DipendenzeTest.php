<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Model;

use OCA\Impagina\Model\Dipendenze;
use PHPUnit\Framework\TestCase;

final class DipendenzeTest extends TestCase {
	public function testIPercorsiCodificatiDalServizioVengonoDecodificati(): void {
		$dipendenze = Dipendenze::daArray(['file' => [
			['percorso' => 'immagini/schema.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			['percorso' => 'Citt%C3%A0%20e%20mare/il%20%22punto%22.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
		]]);

		self::assertSame(
			['immagini/schema.png', 'Città e mare/il "punto".png'],
			$dipendenze->percorsi(),
		);
	}

	public function testDueRiferimentiAlloStessoFileSonoUnSoloPercorso(): void {
		$dipendenze = Dipendenze::daArray(['file' => [
			['percorso' => 'foto%20mare.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
			['percorso' => 'logo.png', 'tipo' => 'doc-icon', 'stato' => 'da_allegare'],
			['percorso' => 'foto mare.png', 'tipo' => 'immagine', 'stato' => 'da_allegare'],
		]]);

		self::assertSame(['foto mare.png', 'logo.png'], $dipendenze->percorsi());
	}
}
