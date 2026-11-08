<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * Il problema e' nel documento dell'utente: front-matter non valido,
 * conversione fallita, file non trovato, percorso non valido, tempo scaduto.
 *
 * Sono gli unici casi in cui `messaggio` e `suggerimento` del servizio vanno
 * mostrati cosi' come sono: sono scritti per le persone e in italiano.
 */
final class DocumentoException extends ImpaginaException {
}
