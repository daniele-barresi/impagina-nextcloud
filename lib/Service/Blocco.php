<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * Impedisce due conversioni contemporanee dello stesso file per lo stesso
 * utente: due clic ravvicinati, o un clic mentre il lavoro in background e'
 * gia' partito, produrrebbero due PDF.
 *
 * Usa la cache distribuita, che ha un `add()` atomico. Senza una memcache
 * configurata la protezione non c'e', ma la conversione funziona lo stesso.
 * La durata limita i danni di un processo interrotto a meta'.
 */
final readonly class Blocco {
	private ICache $cache;

	public function __construct(ICacheFactory $fabbrica) {
		$this->cache = $fabbrica->createDistributed('impagina-conversioni');
	}

	public function prendi(string $userId, int $fileId, int $durata): bool {
		if (!$this->cache instanceof IMemcache) {
			return true;
		}

		return $this->cache->add($this->chiave($userId, $fileId), 1, $durata);
	}

	public function rilascia(string $userId, int $fileId): void {
		$this->cache->remove($this->chiave($userId, $fileId));
	}

	private function chiave(string $userId, int $fileId): string {
		return md5($userId) . '-' . $fileId;
	}
}
