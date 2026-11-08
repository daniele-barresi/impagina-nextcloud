<?php

declare(strict_types=1);

/**
 * Stub per l'analisi statica: l'evento appartiene all'app File di Nextcloud,
 * che non fa parte degli stub `nextcloud/ocp`. A runtime la classe vera c'e'
 * sempre, perche' l'app File non si puo' disabilitare.
 *
 * Questo file non viene mai caricato: serve solo a Psalm (vedi psalm.xml).
 */

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

class LoadAdditionalScriptsEvent extends Event {
}
