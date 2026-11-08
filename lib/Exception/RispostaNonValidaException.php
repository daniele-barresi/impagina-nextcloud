<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * La risposta non e' quella che la specifica descrive: JSON malformato, campi
 * mancanti, tipo di contenuto inatteso.
 *
 * Capita anche quando si parla con qualcosa che non e' il servizio impagina
 * (per esempio la pagina di errore di un reverse proxy).
 */
final class RispostaNonValidaException extends ImpaginaException {
}
