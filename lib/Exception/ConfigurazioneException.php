<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

/**
 * Il servizio risponde, ma l'app e' configurata male: token mancante o non
 * valido (`non_autorizzato`), template inesistente (`template_non_trovato`).
 *
 * All'utente va un messaggio generico che rimanda all'amministratore; i
 * dettagli restano nel log.
 */
final class ConfigurazioneException extends ImpaginaException {
}
