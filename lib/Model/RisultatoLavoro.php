<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Il campo `risultato` di `GET /lavori/{id}`: cosa si scarichera' da
 * `/lavori/{id}/risultato`.
 */
final readonly class RisultatoLavoro {
	public const string TIPO_PDF = 'application/pdf';
	public const string TIPO_ZIP = 'application/zip';

	public function __construct(
		private string $url,
		private string $tipo,
		private string $nome,
		private int $byte,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati): self {
		return new self(
			Campi::stringa($dati, 'url'),
			Campi::enumerazione($dati, 'tipo', [self::TIPO_PDF, self::TIPO_ZIP]),
			Campi::stringa($dati, 'nome'),
			Campi::intero($dati, 'byte'),
		);
	}

	public function url(): string {
		return $this->url;
	}

	public function tipo(): string {
		return $this->tipo;
	}

	public function nome(): string {
		return $this->nome;
	}

	public function byte(): int {
		return $this->byte;
	}

	public function ePdf(): bool {
		return $this->tipo === self::TIPO_PDF;
	}
}
