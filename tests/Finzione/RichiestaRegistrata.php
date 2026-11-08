<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use LogicException;

/**
 * Una richiesta passata al client HTTP, con accessori tipizzati sulle opzioni
 * che interessano ai test.
 */
final readonly class RichiestaRegistrata {
	/** @var list<array{name: string, contents: string, filename: ?string, headers: array<string, string>}>|null */
	private ?array $parti;

	/**
	 * Le parti del multipart vengono lette subito: il client chiude gli stream
	 * appena la richiesta e' partita, quindi dopo non sarebbero piu' leggibili.
	 *
	 * @param array<array-key, mixed> $opzioni
	 */
	public function __construct(
		public string $metodo,
		public string $url,
		public array $opzioni,
	) {
		$this->parti = self::leggiParti($opzioni);
	}

	public function intestazione(string $nome): ?string {
		if (!isset($this->opzioni['headers']) || !is_array($this->opzioni['headers'])) {
			return null;
		}

		/** @psalm-suppress MixedAssignment il tipo del valore e' verificato qui sotto */
		foreach ($this->opzioni['headers'] as $chiave => $valore) {
			if (is_string($chiave) && strcasecmp($chiave, $nome) === 0 && is_string($valore)) {
				return $valore;
			}
		}

		return null;
	}

	public function corpo(): ?string {
		if (!isset($this->opzioni['body']) || !is_string($this->opzioni['body'])) {
			return null;
		}

		return $this->opzioni['body'];
	}

	public function sink(): ?string {
		if (!isset($this->opzioni['sink']) || !is_string($this->opzioni['sink'])) {
			return null;
		}

		return $this->opzioni['sink'];
	}

	public function opzione(string $nome): mixed {
		return $this->opzioni[$nome] ?? null;
	}

	/**
	 * Le parti del multipart, ciascuna con `name`, `contents` (gia' letto),
	 * l'eventuale `filename` e le intestazioni della parte.
	 *
	 * @return list<array{name: string, contents: string, filename: ?string, headers: array<string, string>}>
	 */
	public function parti(): array {
		if ($this->parti === null) {
			throw new LogicException('la richiesta non ha un corpo multipart');
		}

		return $this->parti;
	}

	/**
	 * @param array<array-key, mixed> $opzioni
	 * @return list<array{name: string, contents: string, filename: ?string, headers: array<string, string>}>|null
	 */
	private static function leggiParti(array $opzioni): ?array {
		if (!isset($opzioni['multipart']) || !is_array($opzioni['multipart'])) {
			return null;
		}

		$parti = [];
		/** @psalm-suppress MixedAssignment il contenuto e' verificato qui sotto */
		foreach ($opzioni['multipart'] as $parte) {
			if (!is_array($parte) || !isset($parte['name']) || !is_string($parte['name'])) {
				throw new LogicException('parte multipart senza nome');
			}

			/** @psalm-suppress MixedAssignment il tipo e' verificato subito sotto */
			$contenuto = $parte['contents'] ?? null;
			if (is_resource($contenuto)) {
				$letto = stream_get_contents($contenuto);
				$contenuto = $letto === false ? '' : $letto;
			}
			if (!is_string($contenuto)) {
				throw new LogicException('parte multipart senza contenuto testuale');
			}

			/** @psalm-suppress MixedAssignment il tipo e' verificato subito sotto */
			$nomeFile = $parte['filename'] ?? null;
			/** @psalm-suppress MixedAssignment il tipo e' verificato subito sotto */
			$daLeggere = $parte['headers'] ?? [];
			$intestazioni = [];
			/** @psalm-suppress MixedAssignment il tipo e' verificato subito sotto */
			foreach (is_array($daLeggere) ? $daLeggere : [] as $chiave => $valore) {
				if (is_string($chiave) && is_string($valore)) {
					$intestazioni[$chiave] = $valore;
				}
			}
			$parti[] = [
				'name' => $parte['name'],
				'contents' => $contenuto,
				'filename' => is_string($nomeFile) ? $nomeFile : null,
				'headers' => $intestazioni,
			];
		}

		return $parti;
	}

	/**
	 * I nomi dei file allegati, nell'ordine di invio.
	 *
	 * @return list<string>
	 */
	public function nomiAllegati(): array {
		$nomi = [];
		foreach ($this->parti() as $parte) {
			if ($parte['name'] === 'allegato' && $parte['filename'] !== null) {
				$nomi[] = $parte['filename'];
			}
		}

		return $nomi;
	}

	/**
	 * Il `Content-Disposition` di ogni parte con file, nell'ordine di invio:
	 * e' cio' che arriva davvero al servizio, perche' Guzzle non lo riscrive.
	 *
	 * @return list<?string>
	 */
	public function disposizioni(): array {
		$disposizioni = [];
		foreach ($this->parti() as $parte) {
			if ($parte['filename'] !== null) {
				$disposizioni[] = $parte['headers']['Content-Disposition'] ?? null;
			}
		}

		return $disposizioni;
	}

	/**
	 * I campi non-file del multipart (le opzioni di conversione).
	 *
	 * @return array<string, string>
	 */
	public function campi(): array {
		$campi = [];
		foreach ($this->parti() as $parte) {
			if ($parte['filename'] === null) {
				$campi[$parte['name']] = $parte['contents'];
			}
		}

		return $campi;
	}
}
