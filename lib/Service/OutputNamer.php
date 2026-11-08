<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;

/**
 * Scrive il PDF accanto al sorgente senza sovrascrivere nulla.
 *
 * La numerazione (`relazione.pdf`, `relazione (2).pdf`, ...) la fa gia'
 * `Folder::getNonExistingName()`. Qui si aggiunge la difesa dalla corsa fra il
 * momento in cui si sceglie il nome e quello in cui si crea il file: fra i due
 * puo' essersi inserito qualcun altro.
 */
final class OutputNamer {
	public const int TENTATIVI = 5;

	/**
	 * Nome del PDF a partire da quello del Markdown: `relazione.md` ->
	 * `relazione.pdf`.
	 */
	public function nomePdf(string $nomeSorgente): string {
		$senzaEstensione = pathinfo($nomeSorgente, PATHINFO_FILENAME);

		return ($senzaEstensione === '' ? $nomeSorgente : $senzaEstensione) . '.pdf';
	}

	/**
	 * Crea il file con il primo nome libero, copiandoci dentro il contenuto di
	 * un file locale senza tenerlo tutto in memoria.
	 *
	 * @throws NotPermittedException se dopo piu' tentativi il nome resta occupato
	 */
	public function crea(Folder $cartella, string $nomeProposto, string $percorsoLocale): File {
		$ultimo = null;

		for ($tentativo = 0; $tentativo < self::TENTATIVI; $tentativo++) {
			$nome = $cartella->getNonExistingName($nomeProposto);

			try {
				$file = $cartella->newFile($nome);
			} catch (NotPermittedException $e) {
				// Qualcun altro ha creato un file con questo nome nel frattempo.
				$ultimo = $e;
				continue;
			}

			$this->copia($percorsoLocale, $file);

			return $file;
		}

		throw $ultimo ?? new NotPermittedException('impossibile creare il PDF: nome sempre occupato');
	}

	private function copia(string $percorsoLocale, File $destinazione): void {
		$sorgente = @fopen($percorsoLocale, 'rb');
		if ($sorgente === false) {
			throw new NotPermittedException("file temporaneo non leggibile: $percorsoLocale");
		}

		try {
			$scrittura = $destinazione->fopen('w');
			if ($scrittura === false) {
				throw new NotPermittedException('impossibile scrivere il PDF nella cartella');
			}

			try {
				stream_copy_to_stream($sorgente, $scrittura);
			} finally {
				fclose($scrittura);
			}
		} finally {
			fclose($sorgente);
		}
	}
}
