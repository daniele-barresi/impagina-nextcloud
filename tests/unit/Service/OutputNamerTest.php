<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Service;

use OCA\Impagina\Service\OutputNamer;
use OCA\Impagina\Tests\Finzione\AlberoFinto;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

final class OutputNamerTest extends TestCase {
	use AlberoFinto;

	private OutputNamer $nomi;

	/** @var list<string> */
	private array $temporaneiTest = [];

	#[\Override]
	protected function setUp(): void {
		$this->nomi = new OutputNamer();
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->temporaneiTest as $percorso) {
			if (is_file($percorso)) {
				unlink($percorso);
			}
		}
		$this->temporaneiTest = [];
		$this->pulisciAlbero();
	}

	public function testIlNomeDelPdfVieneDalNomeDelMarkdown(): void {
		self::assertSame('relazione.pdf', $this->nomi->nomePdf('relazione.md'));
		self::assertSame('relazione.pdf', $this->nomi->nomePdf('relazione.markdown'));
		self::assertSame('note di riunione.pdf', $this->nomi->nomePdf('note di riunione.md'));
		self::assertSame('senza-estensione.pdf', $this->nomi->nomePdf('senza-estensione'));
	}

	public function testScriveIlPdfConIlNomeProposto(): void {
		$cartella = $this->cartellaFinta();
		$percorso = $this->pdfTemporaneo('%PDF-1.7 contenuto');

		$file = $this->nomi->crea($cartella, 'relazione.pdf', $percorso);

		self::assertSame('relazione.pdf', $file->getName());
		self::assertSame('%PDF-1.7 contenuto', $this->contenutoCreato('relazione.pdf'));
	}

	public function testNonSovrascriveMaNumera(): void {
		$cartella = $this->cartellaFinta(['relazione.pdf' => $this->fileFinto('relazione.pdf', 'vecchio')]);
		$percorso = $this->pdfTemporaneo('%PDF-1.7 nuovo');

		$file = $this->nomi->crea($cartella, 'relazione.pdf', $percorso);

		self::assertSame('relazione (2).pdf', $file->getName());
	}

	public function testRiprovaSeQualcunAltroOccupaIlNomeNelFrattempo(): void {
		$tentativi = 0;
		$cartella = $this->createStub(Folder::class);
		$cartella->method('getNonExistingName')->willReturnCallback(
			static fn (string $nome): string => $nome,
		);
		$cartella->method('newFile')->willReturnCallback(
			function (string $nome) use (&$tentativi): File {
				$tentativi++;
				if ($tentativi === 1) {
					throw new NotPermittedException('gia\' esistente');
				}

				$file = $this->createStub(File::class);
				$file->method('getName')->willReturn($nome);
				$file->method('fopen')->willReturn(fopen('php://temp', 'w+b'));

				return $file;
			},
		);

		$file = $this->nomi->crea($cartella, 'relazione.pdf', $this->pdfTemporaneo('%PDF-1.7'));

		self::assertSame(2, $tentativi);
		self::assertSame('relazione.pdf', $file->getName());
	}

	public function testSiArrendeSeIlNomeRestaSempreOccupato(): void {
		$cartella = $this->createStub(Folder::class);
		$cartella->method('getNonExistingName')->willReturnCallback(static fn (string $nome): string => $nome);
		$cartella->method('newFile')->willThrowException(new NotPermittedException('sempre occupato'));

		$this->expectException(NotPermittedException::class);
		$this->nomi->crea($cartella, 'relazione.pdf', $this->pdfTemporaneo('%PDF-1.7'));
	}

	private function pdfTemporaneo(string $contenuto): string {
		$percorso = tempnam(sys_get_temp_dir(), 'impagina-pdf-');
		self::assertIsString($percorso);
		file_put_contents($percorso, $contenuto);
		$this->temporaneiTest[] = $percorso;

		return $percorso;
	}
}
