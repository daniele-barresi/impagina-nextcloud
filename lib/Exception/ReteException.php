<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * Il servizio non ha risposto: DNS, connessione rifiutata, timeout, TLS, o il
 * blocco delle connessioni locali di Nextcloud.
 */
final class ReteException extends ImpaginaException {
}
