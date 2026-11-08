<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use OCP\Http\Client\IResponse;

/**
 * Risposta HTTP preconfezionata per i test del client.
 */
final readonly class RispostaFinta implements IResponse {
	/**
	 * @param array<string, string> $intestazioni
	 */
	public function __construct(
		private int $stato,
		private string $corpo = '',
		private array $intestazioni = [],
	) {
	}

	/**
	 * @param array<string, mixed> $dati
	 * @param array<string, string> $intestazioni
	 */
	public static function json(int $stato, array $dati, array $intestazioni = []): self {
		return new self(
			$stato,
			json_encode($dati, JSON_THROW_ON_ERROR),
			array_merge(['Content-Type' => 'application/json'], $intestazioni),
		);
	}

	/**
	 * @param array<string, string> $intestazioni
	 */
	public static function errore(
		int $stato,
		string $codice,
		string $messaggio = 'errore simulato',
		?string $suggerimento = null,
		array $intestazioni = [],
	): self {
		return self::json($stato, [
			'errore' => array_filter([
				'codice' => $codice,
				'messaggio' => $messaggio,
				'suggerimento' => $suggerimento,
			], static fn (?string $valore): bool => $valore !== null),
		], $intestazioni);
	}

	#[\Override]
	public function getBody(): string {
		return $this->corpo;
	}

	#[\Override]
	public function getStatusCode(): int {
		return $this->stato;
	}

	#[\Override]
	public function getHeader(string $key): string {
		foreach ($this->intestazioni as $nome => $valore) {
			if (strcasecmp($nome, $key) === 0) {
				return $valore;
			}
		}

		return '';
	}

	/**
	 * @return array<string, string>
	 */
	#[\Override]
	public function getHeaders(): array {
		return $this->intestazioni;
	}
}
