<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Un file scaricato dal servizio e appoggiato su disco.
 *
 * Il tipo dichiarato non basta: prima di salvare il PDF nella cartella
 * dell'utente si controlla anche l'intestazione `%PDF-`, perche' un reverse
 * proxy mal configurato puo' benissimo restituire una pagina HTML con il
 * `Content-Type` sbagliato.
 */
final readonly class FileScaricato {
	public function __construct(
		private string $percorsoLocale,
		private string $tipo,
		private int $byte,
	) {
	}

	public function percorsoLocale(): string {
		return $this->percorsoLocale;
	}

	public function tipo(): string {
		return $this->tipo;
	}

	public function byte(): int {
		return $this->byte;
	}

	public function tipoDichiaratoPdf(): bool {
		return $this->tipo === RisultatoLavoro::TIPO_PDF;
	}

	/**
	 * Vero se il file comincia davvero con `%PDF-`.
	 */
	public function iniziaComePdf(): bool {
		$gestore = @fopen($this->percorsoLocale, 'rb');
		if ($gestore === false) {
			return false;
		}

		try {
			$inizio = fread($gestore, 5);
		} finally {
			fclose($gestore);
		}

		return $inizio === '%PDF-';
	}

	public function ePdf(): bool {
		return $this->tipoDichiaratoPdf() && $this->iniziaComePdf();
	}
}
