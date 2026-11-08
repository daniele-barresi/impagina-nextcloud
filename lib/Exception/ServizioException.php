<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * Guasto interno del servizio: `errore_interno`, `scrittura_fallita`,
 * `interrotto` (500).
 *
 * All'utente basta "errore del servizio di conversione"; i dettagli vanno nel
 * log dell'amministratore.
 */
final class ServizioException extends ImpaginaException {
}
