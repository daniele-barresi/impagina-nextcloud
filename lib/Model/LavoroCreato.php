<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Risposta `202` di `POST /lavori`.
 *
 * `retryAfter` viene dall'omonima intestazione: e' il ritmo di interrogazione
 * suggerito dal servizio per il primo controllo dello stato.
 */
final readonly class LavoroCreato {
	public function __construct(
		private string $id,
		private string $stato,
		private string $statoUrl,
		private string $risultatoUrl,
		private ?int $posizioneInCoda = null,
		private ?int $retryAfter = null,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati, ?int $retryAfter = null): self {
		return new self(
			Campi::stringa($dati, 'id'),
			Campi::stringa($dati, 'stato'),
			Campi::stringa($dati, 'stato_url'),
			Campi::stringa($dati, 'risultato_url'),
			Campi::interoOpzionale($dati, 'posizione_in_coda'),
			$retryAfter,
		);
	}

	public function id(): string {
		return $this->id;
	}

	public function stato(): string {
		return $this->stato;
	}

	public function statoUrl(): string {
		return $this->statoUrl;
	}

	public function risultatoUrl(): string {
		return $this->risultatoUrl;
	}

	public function posizioneInCoda(): ?int {
		return $this->posizioneInCoda;
	}

	public function retryAfter(): ?int {
		return $this->retryAfter;
	}
}
