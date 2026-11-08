<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Risposta di `POST /dipendenze`: i file referenziati dal documento, quelli
 * che non si possono allegare e la presenza di Typst grezzo.
 */
final readonly class Dipendenze {
	/**
	 * @param list<FileReferenziato> $file
	 * @param list<RiferimentoIgnorato> $ignorati
	 */
	public function __construct(
		private array $file,
		private array $ignorati,
		private bool $typstGrezzo,
		private ?string $documento = null,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati): self {
		$file = [];
		foreach (Campi::elencoOggetti($dati, 'file') as $voce) {
			$file[] = FileReferenziato::daArray($voce);
		}

		$ignorati = [];
		foreach (Campi::elencoOggetti($dati, 'ignorati') as $voce) {
			$ignorati[] = RiferimentoIgnorato::daArray($voce);
		}

		return new self(
			$file,
			$ignorati,
			Campi::booleanoOpzionale($dati, 'typst_grezzo') ?? false,
			Campi::stringaOpzionale($dati, 'documento'),
		);
	}

	/**
	 * @return list<FileReferenziato>
	 */
	public function file(): array {
		return $this->file;
	}

	/**
	 * @return list<RiferimentoIgnorato>
	 */
	public function ignorati(): array {
		return $this->ignorati;
	}

	public function typstGrezzo(): bool {
		return $this->typstGrezzo;
	}

	public function documento(): ?string {
		return $this->documento;
	}

	/**
	 * I percorsi da usare come nome degli allegati, nell'ordine dato dal
	 * servizio.
	 *
	 * Il servizio 1.2.0 restituisce i percorsi come compaiono nel Markdown,
	 * con spazi e caratteri non ASCII codificati (`Citt%C3%A0%20e%20mare/x.png`),
	 * ma la conversione cerca l'allegato con il nome decodificato: lo stesso
	 * che ha il file in Nextcloud. Due riferimenti che si decodificano nello
	 * stesso percorso sono un solo allegato.
	 *
	 * @return list<string>
	 */
	public function percorsi(): array {
		return array_values(array_unique(array_map(
			static fn (FileReferenziato $f): string => rawurldecode($f->percorso()),
			$this->file,
		)));
	}
}
