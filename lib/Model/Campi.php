<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

use UnexpectedValueException;

/**
 * Lettura tipizzata dei campi di una risposta JSON.
 *
 * Tutti i modelli passano da qui: e' l'unico punto in cui un valore `mixed`
 * che arriva da `json_decode` viene ristretto a un tipo, e l'unico che sa
 * come segnalare una risposta malformata (`UnexpectedValueException`, che
 * `ImpaginaClient` traduce in `RispostaNonValidaException`).
 */
final class Campi {
	/**
	 * Decodifica un corpo JSON in un array associativo.
	 *
	 * @return array<string, mixed>
	 */
	public static function daJson(string $json): array {
		try {
			/** @psalm-suppress MixedAssignment il tipo e' verificato subito sotto */
			$dati = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new UnexpectedValueException('corpo non in formato JSON', 0, $e);
		}

		if (!is_array($dati)) {
			throw new UnexpectedValueException('il corpo JSON non e\' un oggetto');
		}

		/** @var array<string, mixed> */
		return $dati;
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function stringa(array $dati, string $chiave): string {
		if (!isset($dati[$chiave]) || !is_string($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' mancante o non testuale");
		}

		return $dati[$chiave];
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function stringaOpzionale(array $dati, string $chiave): ?string {
		if (!isset($dati[$chiave])) {
			return null;
		}
		if (!is_string($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' non testuale");
		}

		return $dati[$chiave];
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function intero(array $dati, string $chiave): int {
		if (!isset($dati[$chiave]) || !is_int($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' mancante o non intero");
		}

		return $dati[$chiave];
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function interoOpzionale(array $dati, string $chiave): ?int {
		if (!isset($dati[$chiave])) {
			return null;
		}
		if (!is_int($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' non intero");
		}

		return $dati[$chiave];
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	public static function booleanoOpzionale(array $dati, string $chiave): ?bool {
		if (!isset($dati[$chiave])) {
			return null;
		}
		if (!is_bool($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' non booleano");
		}

		return $dati[$chiave];
	}

	/**
	 * Un oggetto annidato, oppure `null` se assente o esplicitamente nullo.
	 *
	 * @param array<string, mixed> $dati
	 * @return array<string, mixed>|null
	 */
	public static function oggettoOpzionale(array $dati, string $chiave): ?array {
		if (!isset($dati[$chiave])) {
			return null;
		}
		if (!is_array($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' non e' un oggetto");
		}

		/** @var array<string, mixed> */
		return $dati[$chiave];
	}

	/**
	 * Un elenco di oggetti; un campo assente vale elenco vuoto.
	 *
	 * @param array<string, mixed> $dati
	 * @return list<array<string, mixed>>
	 */
	public static function elencoOggetti(array $dati, string $chiave): array {
		if (!isset($dati[$chiave])) {
			return [];
		}
		if (!is_array($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' non e' un elenco");
		}

		$elenco = [];
		/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
		foreach ($dati[$chiave] as $voce) {
			if (!is_array($voce)) {
				throw new UnexpectedValueException("voce di '$chiave' non e' un oggetto");
			}
			/** @var array<string, mixed> $voce */
			$elenco[] = $voce;
		}

		return $elenco;
	}

	/**
	 * Un elenco di stringhe; un campo assente vale elenco vuoto.
	 *
	 * @param array<string, mixed> $dati
	 * @return list<string>
	 */
	public static function elencoStringhe(array $dati, string $chiave): array {
		if (!isset($dati[$chiave])) {
			return [];
		}
		if (!is_array($dati[$chiave])) {
			throw new UnexpectedValueException("campo '$chiave' non e' un elenco");
		}

		$elenco = [];
		/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
		foreach ($dati[$chiave] as $voce) {
			if (!is_string($voce)) {
				throw new UnexpectedValueException("voce di '$chiave' non e' una stringa");
			}
			$elenco[] = $voce;
		}

		return $elenco;
	}

	/**
	 * Un valore fra quelli ammessi.
	 *
	 * @param array<string, mixed> $dati
	 * @param list<string> $ammessi
	 */
	public static function enumerazione(array $dati, string $chiave, array $ammessi): string {
		$valore = self::stringa($dati, $chiave);
		if (!in_array($valore, $ammessi, true)) {
			throw new UnexpectedValueException("valore '$valore' non ammesso per '$chiave'");
		}

		return $valore;
	}

	/**
	 * Come {@see self::enumerazione}, ma il campo puo' mancare o essere nullo.
	 *
	 * @param array<string, mixed> $dati
	 * @param list<string> $ammessi
	 */
	public static function enumerazioneOpzionale(array $dati, string $chiave, array $ammessi): ?string {
		$valore = self::stringaOpzionale($dati, $chiave);
		if ($valore === null) {
			return null;
		}
		if (!in_array($valore, $ammessi, true)) {
			throw new UnexpectedValueException("valore '$valore' non ammesso per '$chiave'");
		}

		return $valore;
	}
}
