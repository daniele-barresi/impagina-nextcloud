<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Risposta di `GET /salute`: l'unico endpoint senza autenticazione.
 */
final readonly class Salute {
	public function __construct(
		private string $stato,
		private string $versione,
		private int $worker,
		private int $inCoda,
		private int $inCorso,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati): self {
		return new self(
			Campi::stringa($dati, 'stato'),
			Campi::stringa($dati, 'versione'),
			Campi::intero($dati, 'worker'),
			Campi::intero($dati, 'in_coda'),
			Campi::intero($dati, 'in_corso'),
		);
	}

	public function stato(): string {
		return $this->stato;
	}

	public function versione(): string {
		return $this->versione;
	}

	public function worker(): int {
		return $this->worker;
	}

	public function inCoda(): int {
		return $this->inCoda;
	}

	public function inCorso(): int {
		return $this->inCorso;
	}

	/**
	 * Serie del servizio con cui l'app e' compatibile. L'app e' scritta sul
	 * contratto 1.2.0; la 2.0 di impagina ha cambiato l'installazione dei
	 * template, ma l'API HTTP e' rimasta la stessa.
	 *
	 * @var list<int>
	 */
	public const array MAJOR_SUPPORTATE = [1, 2];

	/**
	 * Versione major dichiarata dal servizio, o `null` se non e' nella forma
	 * `X.Y.Z`.
	 */
	public function major(): ?int {
		if (preg_match('/^(\d+)\./', $this->versione, $parti) !== 1) {
			return null;
		}

		return (int)$parti[1];
	}

	/**
	 * Se la versione dichiarata appartiene a una serie supportata: se no, merita
	 * un avviso all'amministratore (la conversione non viene comunque bloccata).
	 */
	public function supportata(): bool {
		return in_array($this->major(), self::MAJOR_SUPPORTATE, true);
	}
}
