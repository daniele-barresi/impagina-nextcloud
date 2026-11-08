<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

use OCA\Impagina\Model\DettaglioErrore;

/**
 * `non_pronto` (409): il risultato e' stato chiesto troppo presto. Non e' un
 * errore, e' un invito ad aspettare ancora.
 */
final class NonProntoException extends ImpaginaException {
	public function __construct(
		string $messaggio,
		?DettaglioErrore $dettaglio = null,
		int $stato = 409,
		private readonly ?int $retryAfter = null,
	) {
		parent::__construct($messaggio, $dettaglio, $stato);
	}

	public function retryAfter(): ?int {
		return $this->retryAfter;
	}
}
