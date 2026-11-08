<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * `lavoro_non_trovato` (404): il lavoro non esiste, e' scaduto (i risultati
 * durano un'ora) o appartiene a un'altra chiave. Durante l'attesa significa
 * quasi sempre che il servizio e' stato riavviato o il risultato e' scaduto.
 */
final class LavoroNonTrovatoException extends ImpaginaException {
}
