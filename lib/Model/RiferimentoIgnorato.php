<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Una voce di `ignorati[]` in `POST /dipendenze`: un riferimento che non si
 * puo' allegare (indirizzo remoto, percorso assoluto o che esce dalla cartella
 * del documento).
 *
 * L'app rifiuta la conversione se ce n'e' anche uno solo, elencandoli tutti
 * con il `motivo` scritto dal servizio.
 */
final readonly class RiferimentoIgnorato {
	public const string STATO_REMOTO = 'remoto';
	public const string STATO_ESTERNO = 'esterno';

	public function __construct(
		private string $riferimento,
		private string $stato,
		private string $motivo,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati): self {
		return new self(
			Campi::stringa($dati, 'riferimento'),
			Campi::enumerazione($dati, 'stato', [self::STATO_REMOTO, self::STATO_ESTERNO]),
			Campi::stringa($dati, 'motivo'),
		);
	}

	public function riferimento(): string {
		return $this->riferimento;
	}

	public function stato(): string {
		return $this->stato;
	}

	public function motivo(): string {
		return $this->motivo;
	}
}
