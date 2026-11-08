<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * Il servizio ha rifiutato una richiesta che l'app non avrebbe dovuto
 * costruire cosi': `richiesta_non_valida`, `opzione_non_valida`,
 * `lunghezza_richiesta`, `tipo_non_supportato`, `metodo_non_consentito`,
 * `risorsa_non_trovata`, `uso_errato`.
 *
 * Sono bug dell'app o disallineamenti di versione fra app e servizio: vanno
 * loggati come errore, non spiegati all'utente.
 */
final class ErroreAppException extends ImpaginaException {
}
