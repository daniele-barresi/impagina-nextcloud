<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Una voce di `documenti[]` in `GET /lavori/{id}`: l'esito del singolo `.md`.
 * L'app ne invia sempre uno solo, ma il servizio prevede il caso multiplo.
 */
final readonly class DocumentoLavoro {
	public const string ESITO_OK = 'ok';
	public const string ESITO_ERRORE = 'errore';

	/**
	 * @param list<string> $avvisi
	 */
	public function __construct(
		private string $sorgente,
		private string $esito,
		private ?string $pdf = null,
		private array $avvisi = [],
		private ?int $codiceUscita = null,
		private ?DettaglioErrore $errore = null,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati): self {
		$errore = Campi::oggettoOpzionale($dati, 'errore');

		return new self(
			Campi::stringa($dati, 'sorgente'),
			Campi::enumerazione($dati, 'esito', [self::ESITO_OK, self::ESITO_ERRORE]),
			Campi::stringaOpzionale($dati, 'pdf'),
			Campi::elencoStringhe($dati, 'avvisi'),
			Campi::interoOpzionale($dati, 'codice_uscita'),
			$errore === null ? null : DettaglioErrore::daArray(['errore' => $errore]),
		);
	}

	public function sorgente(): string {
		return $this->sorgente;
	}

	public function esito(): string {
		return $this->esito;
	}

	public function riuscito(): bool {
		return $this->esito === self::ESITO_OK;
	}

	public function pdf(): ?string {
		return $this->pdf;
	}

	/**
	 * @return list<string>
	 */
	public function avvisi(): array {
		return $this->avvisi;
	}

	public function codiceUscita(): ?int {
		return $this->codiceUscita;
	}

	public function errore(): ?DettaglioErrore {
		return $this->errore;
	}
}
