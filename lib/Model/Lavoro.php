<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Risposta di `GET /lavori/{id}`.
 */
final readonly class Lavoro {
	public const string STATO_IN_CODA = 'in_coda';
	public const string STATO_IN_CORSO = 'in_corso';
	public const string STATO_COMPLETATO = 'completato';
	public const string STATO_FALLITO = 'fallito';

	public const string ESITO_OK = 'ok';
	public const string ESITO_PARZIALE = 'parziale';
	public const string ESITO_ERRORE = 'errore';

	/**
	 * @param list<DocumentoLavoro> $documenti
	 */
	public function __construct(
		private string $id,
		private string $stato,
		private ?string $esito,
		private array $documenti,
		private ?DettaglioErrore $errore = null,
		private ?RisultatoLavoro $risultato = null,
		private ?string $scade = null,
		private ?int $posizioneInCoda = null,
		private ?int $retryAfter = null,
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function daArray(array $dati, ?int $retryAfter = null): self {
		$documenti = [];
		foreach (Campi::elencoOggetti($dati, 'documenti') as $voce) {
			$documenti[] = DocumentoLavoro::daArray($voce);
		}

		$errore = Campi::oggettoOpzionale($dati, 'errore');
		$risultato = Campi::oggettoOpzionale($dati, 'risultato');

		return new self(
			Campi::stringa($dati, 'id'),
			Campi::enumerazione($dati, 'stato', [
				self::STATO_IN_CODA,
				self::STATO_IN_CORSO,
				self::STATO_COMPLETATO,
				self::STATO_FALLITO,
			]),
			Campi::enumerazioneOpzionale($dati, 'esito', [self::ESITO_OK, self::ESITO_PARZIALE, self::ESITO_ERRORE]),
			$documenti,
			$errore === null ? null : DettaglioErrore::daArray(['errore' => $errore]),
			$risultato === null ? null : RisultatoLavoro::daArray($risultato),
			Campi::stringaOpzionale($dati, 'scade'),
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

	public function esito(): ?string {
		return $this->esito;
	}

	/**
	 * @return list<DocumentoLavoro>
	 */
	public function documenti(): array {
		return $this->documenti;
	}

	public function errore(): ?DettaglioErrore {
		return $this->errore;
	}

	public function risultato(): ?RisultatoLavoro {
		return $this->risultato;
	}

	public function scade(): ?string {
		return $this->scade;
	}

	public function posizioneInCoda(): ?int {
		return $this->posizioneInCoda;
	}

	public function retryAfter(): ?int {
		return $this->retryAfter;
	}

	public function terminato(): bool {
		return $this->stato === self::STATO_COMPLETATO || $this->stato === self::STATO_FALLITO;
	}

	public function inCoda(): bool {
		return $this->stato === self::STATO_IN_CODA;
	}

	/**
	 * Vero solo se il lavoro e' finito e **tutti** i documenti sono riusciti:
	 * `parziale` non puo' prodursi con un solo `.md`, ma se arrivasse va
	 * trattato come errore.
	 */
	public function riuscito(): bool {
		return $this->stato === self::STATO_COMPLETATO && $this->esito === self::ESITO_OK;
	}

	/**
	 * Il dettaglio d'errore piu' specifico disponibile: prima quello del
	 * documento, poi quello del lavoro.
	 */
	public function erroreSignificativo(): ?DettaglioErrore {
		foreach ($this->documenti as $documento) {
			$errore = $documento->errore();
			if ($errore !== null) {
				return $errore;
			}
		}

		return $this->errore;
	}
}
