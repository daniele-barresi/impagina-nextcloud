<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Coordinate di una istanza del servizio impagina.
 *
 * E' un valore immutabile passato a ogni chiamata di
 * {@see \OCA\Impagina\Service\ImpaginaClient}: cosi' la pagina di
 * amministrazione puo' provare valori non ancora salvati, e il client resta un
 * servizio senza stato.
 */
final readonly class Connessione {
	/**
	 * @param string $base URL di base senza `/` finale, per esempio `https://impagina.interno:8443/api`
	 */
	public function __construct(
		private string $base,
		private string $token,
		private bool $verificaTls = true,
		private int $timeoutConnessione = 10,
		private int $timeoutRichiesta = 60,
	) {
	}

	/**
	 * Costruisce l'URL di base dai campi della pagina di amministrazione.
	 *
	 * @param string $schema `http` o `https`
	 * @param string $percorsoBase path davanti agli endpoint, eventualmente vuoto
	 */
	public static function da(
		string $schema,
		string $host,
		?int $porta,
		string $percorsoBase,
		string $token,
		bool $verificaTls = true,
		int $timeoutConnessione = 10,
		int $timeoutRichiesta = 60,
	): self {
		$base = $schema . '://' . $host;
		if ($porta !== null && $porta !== self::portaPredefinita($schema)) {
			$base .= ':' . $porta;
		}

		$percorsoBase = trim($percorsoBase);
		if ($percorsoBase !== '' && $percorsoBase !== '/') {
			$base .= '/' . trim($percorsoBase, '/');
		}

		return new self($base, $token, $verificaTls, $timeoutConnessione, $timeoutRichiesta);
	}

	private static function portaPredefinita(string $schema): int {
		return $schema === 'https' ? 443 : 80;
	}

	/**
	 * @param string $endpoint percorso assoluto, per esempio `/lavori`
	 */
	public function url(string $endpoint): string {
		return $this->base . '/' . ltrim($endpoint, '/');
	}

	public function base(): string {
		return $this->base;
	}

	public function token(): string {
		return $this->token;
	}

	public function haToken(): bool {
		return $this->token !== '';
	}

	public function verificaTls(): bool {
		return $this->verificaTls;
	}

	public function timeoutConnessione(): int {
		return $this->timeoutConnessione;
	}

	public function timeoutRichiesta(): int {
		return $this->timeoutRichiesta;
	}

	/**
	 * Copia con un altro token: serve alla verifica della connessione, che
	 * prova il token digitato dall'admin prima di salvarlo.
	 */
	public function conToken(string $token): self {
		return new self($this->base, $token, $this->verificaTls, $this->timeoutConnessione, $this->timeoutRichiesta);
	}
}
