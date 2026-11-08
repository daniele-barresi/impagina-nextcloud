<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * Invio oltre i limiti del servizio: `troppo_grande` o `troppi_file` (413).
 *
 * Il limite configurato dall'amministratore e' solo un pre-controllo:
 * l'autorita' e' questa risposta.
 */
final class LimiteException extends ImpaginaException {
}
