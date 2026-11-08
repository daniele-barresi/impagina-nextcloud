<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Un file da allegare a `POST /lavori`.
 *
 * `percorso` e' il nome inviato nel multipart e deve coincidere **esattamente**
 * con quello restituito da `/dipendenze`: e' cosi' che il Markdown ritrova
 * l'immagine. `percorsoLocale` e' il file temporaneo da cui leggere: serve un
 * file vero, con dimensione nota, perche' il servizio rifiuta i corpi chunked.
 */
final readonly class Allegato {
	public function __construct(
		private string $percorso,
		private string $percorsoLocale,
	) {
	}

	public function percorso(): string {
		return $this->percorso;
	}

	public function percorsoLocale(): string {
		return $this->percorsoLocale;
	}
}
