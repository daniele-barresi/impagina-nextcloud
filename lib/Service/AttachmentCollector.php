<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Risolve i percorsi restituiti da `/dipendenze` sui nodi di Nextcloud.
 *
 * Si usano **solo** le API dei nodi, mai il filesystem del server: cosi'
 * nessun percorso puo' uscire dalla cartella dell'utente. Se il nome non
 * combacia si riprova con le due normalizzazioni Unicode: un `é` puo' essere
 * un solo carattere (NFC) o `e` piu' accento (NFD), e client diversi salvano
 * in forme diverse.
 */
final class AttachmentCollector {
	/**
	 * @param list<string> $percorsi percorsi relativi alla cartella del documento
	 * @return array{trovati: array<string, File>, mancanti: list<string>}
	 */
	public function risolvi(Folder $cartella, array $percorsi): array {
		$trovati = [];
		$mancanti = [];

		foreach ($percorsi as $percorso) {
			$file = $this->cerca($cartella, $percorso);
			if ($file === null) {
				$mancanti[] = $percorso;
				continue;
			}

			$trovati[$percorso] = $file;
		}

		return ['trovati' => $trovati, 'mancanti' => $mancanti];
	}

	private function cerca(Folder $cartella, string $percorso): ?File {
		if (!$this->percorsoAmmesso($percorso)) {
			return null;
		}

		foreach ($this->varianti($percorso) as $variante) {
			try {
				$nodo = $cartella->get($variante);
			} catch (NotFoundException|NotPermittedException) {
				continue;
			}

			if ($nodo instanceof File && $nodo->isReadable()) {
				return $nodo;
			}
		}

		return null;
	}

	/**
	 * Il servizio normalizza gia' i percorsi, ma un controllo in piu' non
	 * costa nulla: niente percorsi assoluti e niente risalite.
	 */
	private function percorsoAmmesso(string $percorso): bool {
		if ($percorso === '' || str_starts_with($percorso, '/') || str_contains($percorso, "\0")) {
			return false;
		}

		foreach (explode('/', $percorso) as $segmento) {
			if ($segmento === '..' || $segmento === '') {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private function varianti(string $percorso): array {
		$varianti = [$percorso];

		if (class_exists(\Normalizer::class)) {
			foreach ([\Normalizer::FORM_C, \Normalizer::FORM_D] as $forma) {
				/**
				 * @psalm-suppress MixedAssignment il tipo e' verificato subito sotto
				 * @psalm-suppress InternalMethod senza l'estensione intl risolve al polyfill di Symfony
				 */
				$normalizzato = \Normalizer::normalize($percorso, $forma);
				if (is_string($normalizzato) && !in_array($normalizzato, $varianti, true)) {
					$varianti[] = $normalizzato;
				}
			}
		}

		return $varianti;
	}
}
