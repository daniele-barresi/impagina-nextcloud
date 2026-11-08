<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le traduzioni restano allineate al codice.
 *
 * Nextcloud non ha qui l'infrastruttura di Transifex: i file `l10n/` si
 * scrivono a mano, quindi e' facile aggiungere una stringa e dimenticarla. Il
 * test estrae le stringhe sorgente dal codice e le confronta con il catalogo.
 */
final class TraduzioniTest extends TestCase {
	private const string RADICE = __DIR__ . '/../..';

	/** @var list<string> */
	private const array LINGUE = ['it'];

	/**
	 * @return array{singolari: list<string>, plurali: list<string>}
	 */
	private static function stringheSorgente(): array {
		$singolari = [];
		$plurali = [];

		foreach (self::file(self::RADICE . '/lib', 'php') as $percorso) {
			$contenuto = (string)file_get_contents($percorso);

			if (preg_match_all('/->t\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $contenuto, $trovate) !== false) {
				foreach ($trovate[1] as $stringa) {
					$singolari[] = stripslashes($stringa);
				}
			}

			$espressione = '/->n\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/';
			if (preg_match_all($espressione, $contenuto, $trovate) !== false) {
				foreach ($trovate[1] as $indice => $singolare) {
					$plurali[] = '_' . stripslashes($singolare) . '_::_' . stripslashes($trovate[2][$indice]) . '_';
				}
			}
		}

		foreach (array_merge(self::file(self::RADICE . '/src', 'ts'), self::file(self::RADICE . '/src', 'vue')) as $percorso) {
			$contenuto = (string)file_get_contents($percorso);
			if (preg_match_all('/\bt\(\s*\'impagina\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $contenuto, $trovate) !== false) {
				foreach ($trovate[1] as $stringa) {
					$singolari[] = stripslashes($stringa);
				}
			}
		}

		return [
			'singolari' => array_values(array_unique($singolari)),
			'plurali' => array_values(array_unique($plurali)),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function file(string $cartella, string $estensione): array {
		$percorsi = [];
		$iteratore = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cartella));

		/** @psalm-suppress MixedAssignment l'iteratore non e' tipizzato: il controllo e' qui sotto */
		foreach ($iteratore as $file) {
			if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === $estensione) {
				$percorsi[] = $file->getPathname();
			}
		}

		sort($percorsi);

		return $percorsi;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function catalogo(string $lingua): array {
		$contenuto = file_get_contents(self::RADICE . "/l10n/$lingua.json");
		self::assertIsString($contenuto, "manca l10n/$lingua.json");
		$dati = json_decode($contenuto, true, 16, JSON_THROW_ON_ERROR);
		self::assertIsArray($dati);
		self::assertArrayHasKey('translations', $dati);
		self::assertIsArray($dati['translations']);

		/** @var array<string, mixed> */
		return $dati['translations'];
	}

	public function testOgniStringaDelCodiceHaLaSuaTraduzione(): void {
		$sorgente = self::stringheSorgente();
		self::assertNotSame([], $sorgente['singolari']);

		foreach (self::LINGUE as $lingua) {
			$catalogo = self::catalogo($lingua);

			foreach (array_merge($sorgente['singolari'], $sorgente['plurali']) as $stringa) {
				self::assertArrayHasKey($stringa, $catalogo, "manca la traduzione $lingua di: $stringa");
				self::assertNotSame('', $catalogo[$stringa], "traduzione $lingua vuota per: $stringa");
			}
		}
	}

	public function testIlCatalogoNonContieneStringheSparite(): void {
		$sorgente = self::stringheSorgente();
		$attese = array_merge($sorgente['singolari'], $sorgente['plurali']);

		foreach (self::LINGUE as $lingua) {
			foreach (array_keys(self::catalogo($lingua)) as $chiave) {
				self::assertContains($chiave, $attese, "traduzione $lingua di una stringa che non esiste piu': $chiave");
			}
		}
	}

	public function testIlFileJsDelFrontendCoincideConIlJson(): void {
		foreach (self::LINGUE as $lingua) {
			$js = file_get_contents(self::RADICE . "/l10n/$lingua.js");
			self::assertIsString($js, "manca l10n/$lingua.js");

			$catalogo = self::catalogo($lingua);
			/** @psalm-suppress MixedAssignment il tipo del valore e' verificato qui sotto */
			foreach ($catalogo as $chiave => $valore) {
				self::assertStringContainsString(
					self::comeJson($chiave),
					$js,
					"l10n/$lingua.js non contiene: $chiave",
				);
				if (is_string($valore)) {
					self::assertStringContainsString(
						self::comeJson($valore),
						$js,
						"l10n/$lingua.js non contiene la traduzione di: $chiave",
					);
				}
			}
		}
	}

	private static function comeJson(string $testo): string {
		return json_encode($testo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	public function testLePluraliHannoDueForme(): void {
		foreach (self::LINGUE as $lingua) {
			/** @psalm-suppress MixedAssignment il tipo del valore e' proprio cio' che si verifica */
			foreach (self::catalogo($lingua) as $chiave => $valore) {
				if (!str_contains($chiave, '_::_')) {
					self::assertIsString($valore, "la stringa $chiave non e' plurale ma ha piu' forme");
					continue;
				}

				self::assertIsArray($valore, "la stringa plurale $chiave deve avere due forme");
				self::assertCount(2, $valore, "la stringa plurale $chiave deve avere due forme");
			}
		}
	}
}
