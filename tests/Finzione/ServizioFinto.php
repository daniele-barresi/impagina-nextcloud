<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use LogicException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IPromise;
use OCP\Http\Client\IResponse;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Servizio HTTP finto: restituisce le risposte preparate, nell'ordine, e
 * registra le richieste ricevute.
 *
 * Implementa `IClient` a mano invece di usare un mock, cosi' i test possono
 * ispezionare le opzioni davvero passate (multipart, intestazioni, `sink`) con
 * accessori tipizzati.
 */
final class ServizioFinto implements IClientService, IClient {
	/** @var list<RispostaFinta|Throwable> */
	private array $risposte = [];

	/** @var list<RichiestaRegistrata> */
	private array $richieste = [];

	public function prepara(RispostaFinta|Throwable ...$risposte): self {
		foreach ($risposte as $risposta) {
			$this->risposte[] = $risposta;
		}

		return $this;
	}

	/**
	 * @return list<RichiestaRegistrata>
	 */
	public function richieste(): array {
		return $this->richieste;
	}

	public function richiesta(int $indice = 0): RichiestaRegistrata {
		if (!isset($this->richieste[$indice])) {
			throw new LogicException("nessuna richiesta all'indice $indice");
		}

		return $this->richieste[$indice];
	}

	public function numeroRichieste(): int {
		return count($this->richieste);
	}

	public function risposteRimaste(): int {
		return count($this->risposte);
	}

	#[\Override]
	public function newClient(?callable $handler = null): IClient {
		return $this;
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function get(string $uri, array $options = []): IResponse {
		return $this->esegui('GET', $uri, $options);
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function post(string $uri, array $options = []): IResponse {
		return $this->esegui('POST', $uri, $options);
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function delete(string $uri, array $options = []): IResponse {
		return $this->esegui('DELETE', $uri, $options);
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function request(string $method, string $uri, array $options = []): IResponse {
		return $this->esegui(strtoupper($method), $uri, $options);
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	private function esegui(string $metodo, string $uri, array $options): IResponse {
		$this->richieste[] = new RichiestaRegistrata($metodo, $uri, $options);

		if ($this->risposte === []) {
			throw new LogicException("nessuna risposta preparata per $metodo $uri");
		}

		$risposta = array_shift($this->risposte);
		if ($risposta instanceof Throwable) {
			throw $risposta;
		}

		// Con l'opzione `sink` il corpo finisce su file, come fa Guzzle.
		if (isset($options['sink']) && is_string($options['sink'])) {
			file_put_contents($options['sink'], $risposta->getBody());
		}

		return $risposta;
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function head(string $uri, array $options = []): IResponse {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function put(string $uri, array $options = []): IResponse {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function patch(string $uri, array $options = []): IResponse {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function options(string $uri, array $options = []): IResponse {
		throw new LogicException('non usato nei test');
	}

	#[\Override]
	public function getResponseFromThrowable(Throwable $e): IResponse {
		throw new LogicException('non usato nei test');
	}

	// Niente #[\Override]: `IClient` lo dichiara solo dalla 34, e con gli stub
	// della 33 la classe non si caricherebbe (vedi psalm.xml).
	public function sendRequest(RequestInterface $request): ResponseInterface {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function getAsync(string $uri, array $options = []): IPromise {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function headAsync(string $uri, array $options = []): IPromise {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function postAsync(string $uri, array $options = []): IPromise {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function putAsync(string $uri, array $options = []): IPromise {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function deleteAsync(string $uri, array $options = []): IPromise {
		throw new LogicException('non usato nei test');
	}

	/**
	 * @param array<array-key, mixed> $options
	 */
	#[\Override]
	public function optionsAsync(string $uri, array $options = []): IPromise {
		throw new LogicException('non usato nei test');
	}
}
