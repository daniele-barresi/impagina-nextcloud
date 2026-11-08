<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Una voce di `file[]` in `POST /dipendenze`: un file che il documento
 * referenzia e che va allegato.
 *
 * `percorso` e' quello restituito dal servizio, eventualmente codificato con
 * `%XX`: il nome da dare all'allegato e' quello decodificato, vedi
 * {@see Dipendenze::percorsi()}.
 */
final readonly class FileReferenziato {
	public const string TIPO_IMMAGINE = 'immagine';
	public const string TIPO_DOC_ICON = 'doc-icon';
	public const string STATO_DA_ALLEGARE = 'da_allegare';
	public const string STATO_ALLEGATO = 'allegato';

	public function __construct(
		private string $percorso,
		private string $tipo,
		private string $stato,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati): self {
		return new self(
			Campi::stringa($dati, 'percorso'),
			Campi::enumerazione($dati, 'tipo', [self::TIPO_IMMAGINE, self::TIPO_DOC_ICON]),
			Campi::enumerazione($dati, 'stato', [self::STATO_DA_ALLEGARE, self::STATO_ALLEGATO]),
		);
	}

	public function percorso(): string {
		return $this->percorso;
	}

	public function tipo(): string {
		return $this->tipo;
	}

	public function stato(): string {
		return $this->stato;
	}
}
