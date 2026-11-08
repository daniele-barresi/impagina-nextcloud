<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

use InvalidArgumentException;

/**
 * Un valore rifiutato dalla validazione della pagina di amministrazione.
 *
 * Porta con se' il **campo** e un **codice** stabile invece di una frase:
 * la traduzione la fa il controller, che ha a disposizione `IL10N`.
 */
final class ConfigurazioneNonValidaException extends InvalidArgumentException {
	public function __construct(
		private readonly string $campo,
		private readonly string $codice,
	) {
		parent::__construct("valore non valido per '$campo' ($codice)");
	}

	public function campo(): string {
		return $this->campo;
	}

	public function codice(): string {
		return $this->codice;
	}
}
