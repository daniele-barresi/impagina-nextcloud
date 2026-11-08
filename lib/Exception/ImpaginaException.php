<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

use OCA\Impagina\Model\DettaglioErrore;
use RuntimeException;

/**
 * Radice delle eccezioni del client.
 *
 * Le sottoclassi rappresentano il **trattamento** dell'errore (problema di
 * configurazione, del documento, del servizio...), non il codice HTTP: la
 * scelta avviene sul `codice` stabile della risposta, come prescrive la
 * specifica. Il messaggio dell'eccezione e' tecnico e va nel log; cio' che si
 * mostra all'utente si ricava da {@see self::dettaglio()}.
 */
abstract class ImpaginaException extends RuntimeException {
	public function __construct(
		string $messaggio,
		private readonly ?DettaglioErrore $dettaglio = null,
		private readonly int $stato = 0,
		?\Throwable $precedente = null,
	) {
		parent::__construct($messaggio, 0, $precedente);
	}

	/**
	 * Il corpo di errore del servizio, se la risposta ne aveva uno.
	 */
	public function dettaglio(): ?DettaglioErrore {
		return $this->dettaglio;
	}

	/**
	 * Il codice stabile del servizio, o stringa vuota se l'errore non viene da
	 * una risposta (per esempio un problema di rete).
	 */
	public function codice(): string {
		return $this->dettaglio?->codice() ?? '';
	}

	/**
	 * Codice HTTP della risposta, `0` se non c'e' stata risposta.
	 */
	public function stato(): int {
		return $this->stato;
	}
}
