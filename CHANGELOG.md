# Diario delle modifiche

Le modifiche rilevanti di **Impagina per Nextcloud** sono annotate qui, dalla
più recente alla più vecchia. Il formato segue
[Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e la numerazione il
[versionamento semantico](https://semver.org/lang/it/).

La versione è quella di `appinfo/info.xml`.

## [Non rilasciato]

Nulla per ora.

## [1.0.0] — 2026-11-08

Primo rilascio pubblico. Le versioni 0.x non sono state pubblicate.

### Funzionalità

- **Azione "Converti in PDF con Impagina"** nel menu contestuale di File, per i
  file `.md` e `.markdown`. Il PDF viene salvato nella stessa cartella del
  sorgente; se il nome è già occupato si aggiunge un numero progressivo
  (`documento (2).pdf`), senza mai sovrascrivere.
- **Conversione immediata**: la conversione si prova subito, durante la
  richiesta, e di norma il PDF compare nell'elenco in un paio di secondi. Un
  avviso lo apre con un clic. Se il servizio è occupato, irraggiungibile o
  impiega più di 20 secondi, la conversione passa a un lavoro in background e
  l'avviso lo dice.
- **Immagini rilevate da sole** con `POST /dipendenze` e inviate insieme al
  documento, anche da sottocartelle e con spazi o caratteri non ASCII nel
  nome. Un documento che referenzia immagini remote, percorsi assoluti o file
  fuori dalla propria cartella viene rifiutato prima di creare il lavoro, con
  l'elenco completo dei riferimenti da correggere.
- **Notifiche** di fine lavoro, con messaggi distinti per problemi del
  documento, di configurazione, di rete o del servizio. I dettagli tecnici
  restano nel log.
- **Pagina Impostazioni → Amministrazione → Impagina**: indirizzo del servizio,
  token salvato come valore sensibile, verifica del certificato TLS, timeout,
  opzioni di conversione predefinite (template, indice, copertina, filigrana),
  limiti di dimensione e di attesa, gruppi abilitati.
- **Verifica della connessione in due passi**, con esiti separati per la
  raggiungibilità del servizio e la validità del token, e un avviso se il
  servizio dichiara una serie diversa dalla 1.x o dalla 2.x.
- Due clic ravvicinati sullo stesso file non producono due PDF: la conversione
  in corso è protetta da un blocco nella memcache di Nextcloud.
- Interfaccia e notifiche in inglese e in italiano.

### Compatibilità

- Nextcloud 33, 34 e 35, PHP 8.3–8.5.
- Servizio [impagina](https://github.com/daniele-barresi/impagina) con API
  1.2.0 o successiva, serie 1.x e 2.x: la 2.0.0 espone lo stesso contratto
  della 1.2.0 (`docs/impagina-openapi.yaml`).

[Non rilasciato]: https://github.com/daniele-barresi/impagina-nextcloud/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/daniele-barresi/impagina-nextcloud/releases/tag/v1.0.0
