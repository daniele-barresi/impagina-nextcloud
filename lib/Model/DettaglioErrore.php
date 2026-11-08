<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Il corpo `{"errore": {...}}` comune a tutte le risposte di errore del
 * servizio. Il `codice` e' stabile e si puo' confrontare; il `messaggio` e il
 * `suggerimento` sono in italiano e pensati per le persone.
 */
final readonly class DettaglioErrore {
	public function __construct(
		private string $codice,
		private string $messaggio,
		private ?string $suggerimento = null,
	) {
	}

	/**
	 * @param array<string, mixed> $dati il corpo JSON completo della risposta
	 */
	public static function daArray(array $dati): self {
		$errore = Campi::oggettoOpzionale($dati, 'errore');
		if ($errore === null) {
			throw new \UnexpectedValueException('risposta di errore senza campo "errore"');
		}

		return new self(
			Campi::stringa($errore, 'codice'),
			Campi::stringa($errore, 'messaggio'),
			Campi::stringaOpzionale($errore, 'suggerimento'),
		);
	}

	public function codice(): string {
		return $this->codice;
	}

	public function messaggio(): string {
		return $this->messaggio;
	}

	public function suggerimento(): ?string {
		return $this->suggerimento;
	}

	/**
	 * Messaggio e suggerimento in una riga sola, per notifiche e log.
	 */
	public function testo(): string {
		if ($this->suggerimento === null || $this->suggerimento === '') {
			return $this->messaggio;
		}

		return $this->messaggio . ' ' . $this->suggerimento;
	}
}
