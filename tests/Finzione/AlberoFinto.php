<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Cartelle e file di Nextcloud simulati.
 *
 * Bastano i pochi metodi che l'app usa (`get`, `getFirstNodeById`,
 * `getNonExistingName`, `newFile`, `fopen`, `getContent`): il resto dei nodi
 * reali non serve, e cosi' i test non dipendono da un server.
 *
 * @psalm-require-extends \PHPUnit\Framework\TestCase
 */
trait AlberoFinto {
	/** Interfaccia introdotta da Nextcloud 36; fino alla 35 non esiste. */
	private const string CARTELLA_UTENTE = 'OCP\\Files\\IUserFolder';

	/** @var array<string, string> contenuto dei file creati, per percorso */
	private array $fileCreati = [];

	/** @var list<string> file temporanei da ripulire */
	private array $temporaneiAlbero = [];

	/**
	 * @return File&Stub
	 */
	private function fileFinto(
		string $nome,
		string $contenuto,
		int $id = 42,
		bool $leggibile = true,
		?Folder $genitore = null,
	): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($nome);
		if ($genitore !== null) {
			$file->method('getParent')->willReturn($genitore);
		}
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/utente/files/' . $nome);
		$file->method('getContent')->willReturn($contenuto);
		$file->method('getSize')->willReturn(strlen($contenuto));
		$file->method('isReadable')->willReturn($leggibile);
		$file->method('fopen')->willReturnCallback(
			static function (string $modo) use ($contenuto) {
				$gestore = fopen('php://temp', 'r+b');
				if ($gestore === false) {
					return false;
				}
				fwrite($gestore, $contenuto);
				rewind($gestore);

				return $gestore;
			},
		);

		return $file;
	}

	/**
	 * @param array<string, File> $contenuti percorso relativo => file
	 * @return Folder&Stub
	 */
	private function cartellaFinta(array $contenuti = [], int $permessi = Constants::PERMISSION_ALL): Folder {
		$cartella = $this->createStub(Folder::class);
		$cartella->method('getPermissions')->willReturn($permessi);
		$cartella->method('getName')->willReturn('Documenti');
		$cartella->method('getPath')->willReturn('/utente/files/Documenti');

		$cartella->method('get')->willReturnCallback(
			static function (string $percorso) use ($contenuti) {
				if (!isset($contenuti[$percorso])) {
					throw new NotFoundException($percorso);
				}

				return $contenuti[$percorso];
			},
		);

		$esistenti = array_keys($contenuti);
		$cartella->method('getNonExistingName')->willReturnCallback(
			function (string $nome) use ($esistenti): string {
				$occupati = array_merge($esistenti, array_keys($this->fileCreati));
				if (!in_array($nome, $occupati, true)) {
					return $nome;
				}

				$base = pathinfo($nome, PATHINFO_FILENAME);
				$estensione = pathinfo($nome, PATHINFO_EXTENSION);
				for ($numero = 2; $numero < 100; $numero++) {
					$candidato = "$base ($numero)" . ($estensione === '' ? '' : ".$estensione");
					if (!in_array($candidato, $occupati, true)) {
						return $candidato;
					}
				}

				return $nome;
			},
		);

		$cartella->method('newFile')->willReturnCallback(
			function (string $nome) {
				$this->fileCreati[$nome] = '';
				$percorso = tempnam(sys_get_temp_dir(), 'impagina-nuovo-');
				if ($percorso === false) {
					throw new \RuntimeException('nessun file temporaneo');
				}
				$this->temporaneiAlbero[] = $percorso;

				$nuovo = $this->createStub(File::class);
				$nuovo->method('getName')->willReturn($nome);
				$nuovo->method('getId')->willReturn(99);
				$nuovo->method('getPath')->willReturn('/utente/files/Documenti/' . $nome);
				$nuovo->method('fopen')->willReturnCallback(
					function (string $modo) use ($percorso, $nome) {
						$gestore = fopen($percorso, 'w+b');
						if ($gestore === false) {
							return false;
						}
						// Il contenuto scritto si legge a fine test.
						$this->fileCreati[$nome] = $percorso;

						return $gestore;
					},
				);

				return $nuovo;
			},
		);

		return $cartella;
	}

	/**
	 * La cartella dell'utente, con dentro il documento da convertire.
	 *
	 * Fino a Nextcloud 35 `IRootFolder::getUserFolder()` restituisce una
	 * `Folder`; dalla 36 dichiara `IUserFolder`, e PHPUnit rifiuta uno stub del
	 * tipo sbagliato. Lo stub segue quindi quello che la versione installata
	 * dichiara davvero.
	 *
	 * @return IRootFolder&Stub
	 */
	private function radiceFinta(?File $documento): IRootFolder {
		/** @var class-string<Folder> $interfaccia `IUserFolder` estende `Folder` */
		$interfaccia = Folder::class;
		if (interface_exists(self::CARTELLA_UTENTE)) {
			/** @var class-string<Folder> $interfaccia */
			$interfaccia = self::CARTELLA_UTENTE;
		}

		$cartellaUtente = $this->createStub($interfaccia);
		$cartellaUtente->method('getFirstNodeById')->willReturn($documento);

		$radice = $this->createStub(IRootFolder::class);
		$radice->method('getUserFolder')->willReturn($cartellaUtente);

		return $radice;
	}

	private function contenutoCreato(string $nome): string {
		$percorso = $this->fileCreati[$nome] ?? '';
		if ($percorso === '' || !is_file($percorso)) {
			return '';
		}

		$contenuto = file_get_contents($percorso);

		return $contenuto === false ? '' : $contenuto;
	}

	/**
	 * @return list<string>
	 */
	private function nomiCreati(): array {
		return array_keys($this->fileCreati);
	}

	private function pulisciAlbero(): void {
		foreach ($this->temporaneiAlbero as $percorso) {
			if (is_file($percorso)) {
				unlink($percorso);
			}
		}
		$this->temporaneiAlbero = [];
		$this->fileCreati = [];
	}
}
