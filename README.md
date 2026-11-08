# Impagina per Nextcloud

[![PHPUnit](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/phpunit.yml/badge.svg)](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/phpunit.yml)
[![Static analysis](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/static-analysis.yml)
[![Lint PHP](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/lint-php.yml/badge.svg)](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/lint-php.yml)
[![Lint JS](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/lint-js.yml/badge.svg)](https://github.com/daniele-barresi/impagina-nextcloud/actions/workflows/lint-js.yml)

Converte i file **Markdown** archiviati su Nextcloud in **PDF** impaginati con
un template di [impagina](https://github.com/daniele-barresi/impagina), il convertitore Markdown → PDF basato su pandoc
e Typst. L'app non converte da sé: è il client di un **servizio impagina** che
ospiti tu, sulla stessa macchina o in rete locale.

L'amministratore configura una sola volta indirizzo del servizio, token e
opzioni di conversione, validi per tutta l'istanza. Gli utenti convertono i
propri `.md` dal menu contestuale di File: l'app rileva da sola le immagini
referenziate, le invia al servizio insieme al documento, salva il PDF nella
stessa cartella del sorgente e avvisa con una notifica quando il lavoro è
finito.

```
File (menu contestuale) ──▶ POST /api/convert ──▶ lavoro in coda
                                                      │
        POST /dipendenze ◀── quali immagini servono ───┤
        POST /lavori     ◀── documento + immagini ─────┤
        GET  /lavori/id  ◀── attesa del risultato ─────┤
        GET  .../risultato ─▶ PDF salvato accanto al .md
                                                      ▼
                                              notifica all'utente
```

## Indice

- [Requisiti](#requisiti)
- [Installazione su Nextcloud AIO](#installazione-su-nextcloud-aio)
- [Prerequisiti di rete](#prerequisiti-di-rete)
- [Configurazione](#configurazione)
- [Uso](#uso)
- [Latenza dei lavori in background](#latenza-dei-lavori-in-background)
- [Limiti noti](#limiti-noti)
- [Sviluppo](#sviluppo)
- [Compatibilità e aggiornamento alla 36](#compatibilità-e-aggiornamento-alla-36)
- [Licenza](#licenza)

## Requisiti

| Cosa | Versione |
|---|---|
| Nextcloud | 33, 34 o 35 (dichiarate in `appinfo/info.xml`) |
| PHP | 8.3, 8.4 o 8.5 |
| Servizio [impagina](https://github.com/daniele-barresi/impagina) | API **1.2.0** o successiva, serie 1.x o 2.x (2.0.0 consigliata) |
| App Nextcloud | `notifications` abilitata (senza, il PDF viene comunque salvato) |

Il servizio impagina si installa a parte (le istruzioni sono nel suo
[README](https://github.com/daniele-barresi/impagina#servizio-api)) e deve essere
raggiungibile dal container di Nextcloud: vedi
[Prerequisiti di rete](#prerequisiti-di-rete).

## Installazione su Nextcloud AIO

L'app si distribuisce fuori dall'App Store, come archivio da scompattare in
`custom_apps/`.

1. **Scarica l'archivio** dalla pagina delle
   [release](https://github.com/daniele-barresi/impagina-nextcloud/releases) e controllane l'impronta:

   ```bash
   curl -fLO https://github.com/daniele-barresi/impagina-nextcloud/releases/download/v1.0.0/impagina-1.0.0.tar.gz
   curl -fLO https://github.com/daniele-barresi/impagina-nextcloud/releases/download/v1.0.0/impagina-1.0.0.tar.gz.sha256
   sha256sum -c impagina-1.0.0.tar.gz.sha256
   ```

   In alternativa, crealo dai sorgenti (servono PHP 8.3+, Composer, Node 24 e
   `make`):

   ```bash
   make deps    # solo la prima volta
   make dist    # build/artifacts/impagina-1.0.0.tar.gz
   ```

2. **Copia e scompatta** nel container di Nextcloud. In AIO la cartella
   `custom_apps` è nel volume `nextcloud_aio_nextcloud`, montata su
   `/var/www/html/custom_apps`:

   ```bash
   docker cp impagina-1.0.0.tar.gz nextcloud-aio-nextcloud:/tmp/
   docker exec -u www-data nextcloud-aio-nextcloud \
     tar -xzf /tmp/impagina-1.0.0.tar.gz -C /var/www/html/custom_apps
   docker exec nextcloud-aio-nextcloud rm /tmp/impagina-1.0.0.tar.gz
   ```

3. **Abilita l'app**:

   ```bash
   docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable impagina
   ```

Per **aggiornare**, ripeti i passi 1–2 (l'archivio sovrascrive la cartella) e
poi:

```bash
docker exec -u www-data nextcloud-aio-nextcloud php occ upgrade
docker exec -u www-data nextcloud-aio-nextcloud php occ maintenance:repair
```

Per **disinstallare**: `occ app:remove impagina`. La configurazione resta in
`oc_appconfig`; per toglierla anche da lì, `occ config:app:delete impagina <chiave>`.

## Prerequisiti di rete

Il servizio impagina ascolta di default solo su `127.0.0.1`. Perché Nextcloud
lo raggiunga serve pubblicarlo sulla rete locale, preferibilmente dietro un
reverse proxy HTTPS (gli esempi Caddy e nginx sono nel
[README del servizio](https://github.com/daniele-barresi/impagina#sicurezza)).

**Verifica dal container**, prima ancora di configurare l'app:

```bash
docker exec nextcloud-aio-nextcloud \
  curl -sS https://impagina.example.internal/salute
# {"stato": "ok", "versione": "2.0.0", "worker": 1, "in_coda": 0, "in_corso": 0}
```

Se il comando risponde, l'app funzionerà.

**Connessioni verso la rete locale.** Il client HTTP di Nextcloud rifiuta per
default gli indirizzi privati. Questa app passa `allow_local_address` su ogni
singola richiesta, quindi **di norma non serve toccare nulla**: l'indirizzo lo
decide solo l'amministratore, quindi nessun utente può influenzarlo. Se la tua
istanza dovesse comunque rifiutare la connessione (errore "Host violates local
access rules" nel log), puoi allargare il permesso a tutta l'istanza:

```bash
docker exec -u www-data nextcloud-aio-nextcloud \
  php occ config:system:set allow_local_remote_servers --type=bool --value=true
```

È un parametro **di tutta l'istanza**: abilitalo solo se il controllo per
richiesta non basta.

**Certificato TLS.** Se il servizio usa un certificato interno, installa
l'autorità di certificazione nel container invece di disattivare la verifica
nella pagina di configurazione.

## Configurazione

1. **Crea il token** sul server che ospita il servizio impagina — il token
   viene mostrato **una sola volta**:

   ```bash
   ./impagina.sh --api chiave nuova nextcloud
   ```

2. Apri **Impostazioni → Amministrazione → Impagina** e compila:

   - **Protocollo, host, porta** ed eventuale **percorso di base** (solo se il
     servizio è pubblicato sotto un sotto-percorso del reverse proxy).
   - **Token Bearer**: si scrive, non si rilegge mai. Il campo resta vuoto
     anche dopo il salvataggio; scrivine uno nuovo solo per sostituirlo.
   - **Opzioni di conversione predefinite**: template, indice, copertina
     (*decide il template* / *sempre* / *mai*) e filigrana. Il front-matter del
     singolo documento ha comunque la precedenza su questi valori.
   - **Limiti**: dimensione massima dell'invio e attesa massima per un lavoro.
   - **Gruppi abilitati**: se non ne scegli nessuno, possono tutti. Gli
     amministratori sono sempre abilitati.

3. Premi **Verifica connessione**: il primo esito dice se il servizio risponde
   (`GET /salute`, senza autenticazione), il secondo se il token è valido
   (`GET /template`). Sono separati apposta: così si capisce subito se il
   problema è l'indirizzo o la chiave.

Il token viene salvato come valore **sensibile**: `occ config:list` lo oscura e
non compare né nei log né nelle risposte del backend.

Tutte le chiavi, se preferisci `occ`:

```bash
occ config:app:set impagina host --value=impagina.example.internal
occ config:app:set impagina schema --value=https
occ config:app:set impagina porta --value=443
occ config:app:set impagina template --value=company
occ config:app:set impagina attesa_massima --value=180
```

## Uso

1. In **File**, apri il menu contestuale (⋯) di un file `.md` o `.markdown` e
   scegli **Converti in PDF con Impagina**.
2. Compare l'avviso "Conversione di … in PDF…". Di solito dopo un paio di
   secondi il PDF compare nell'elenco, accanto al sorgente, e l'avviso
   "… è pronto" lo apre con un clic. Se qualcosa non va (immagini mancanti,
   errore nel documento…) l'avviso spiega il motivo.
3. Se il servizio è occupato, irraggiungibile o impiega più di 20 secondi,
   la conversione prosegue **in background** e l'avviso lo dice: puoi chiudere
   la pagina.
4. In ogni caso arriva anche una **notifica**: se è andata bene, con il link al
   PDF; se no, con il motivo.

Se converti di nuovo lo stesso documento, il PDF precedente **non** viene
sovrascritto: il nuovo file si chiamerà `documento (2).pdf`, `documento (3).pdf`
e così via.

L'azione non compare se: hai selezionato più di un file, il file non è
Markdown, non hai il permesso di creare file nella cartella, non appartieni a
un gruppo abilitato o l'app non è ancora configurata.

## Latenza dei lavori in background

La conversione si prova **subito**, durante la richiesta: il servizio converte
in meno di un secondo, quindi di norma il PDF è pronto prima che l'avviso
sparisca. Solo quando non si può (servizio occupato, irraggiungibile o più
lento di 20 secondi) la conversione passa a una `QueuedJob`, che parte quando
gira il **cron di Nextcloud**: in AIO ogni pochi minuti.

Se questo caso è frequente, si può tenere un worker dedicato:

```bash
docker exec -u www-data nextcloud-aio-nextcloud \
  php occ background-job:worker 'OCA\Impagina\BackgroundJob\ConvertJob'
```

Nota: **l'attesa del risultato avviene dentro lo stesso lavoro**. È una scelta
deliberata — spezzarla in due lavori aggiungerebbe un altro giro di cron a ogni
conversione — ma significa che il lavoro tiene occupato un worker del cron fino
a quando il PDF è pronto o scade l'*attesa massima* configurata.

Due clic ravvicinati sullo stesso file non producono due PDF: la conversione
in corso è protetta da un blocco nella memcache di Nextcloud (Redis in AIO).
Senza una memcache configurata la protezione vale solo per i lavori già in
coda.

## Limiti noti

- **Immagini remote.** Un documento che referenzia `https://esempio/logo.png`
  viene **rifiutato**: Typst non scarica file remoti, quindi il PDF ne uscirebbe
  senza. Scarica l'immagine accanto al documento e referenziala per percorso
  relativo.
- **Typst grezzo.** I blocchi ```` ```{=typst} ```` fanno fallire la conversione
  anche se il servizio fosse configurato per accettarli
  (`IMPAGINA_API_TYPST_GREZZO=1`): l'app è deliberatamente più severa.
- **Un solo documento per volta.** L'app converte un `.md` alla volta; la
  modalità ZIP del servizio non viene usata.
- **Un token per tutta l'istanza.** Tutti gli utenti condividono la chiave
  `nextcloud`: lato servizio i lavori non sono separati per utente. Gli
  identificativi dei lavori non escono mai dal backend, quindi nessun utente può
  vedere il lavoro di un altro, ma vale la pena saperlo.
- **Opzioni solo di istanza.** Gli utenti non possono scegliere template,
  filigrana o copertina per il singolo documento; per farlo si usa il
  front-matter del documento, che ha comunque la precedenza.
- **Risultati a scadenza.** Il servizio conserva il PDF un'ora: l'app lo scarica
  subito, ma se l'attesa massima è più lunga della vita del risultato il lavoro
  fallisce con "il risultato è scaduto".
- Le verifiche su una istanza Nextcloud reale (33, 35 e `master`) non sono
  ancora state eseguite: si veda la sezione seguente.

## Sviluppo

```bash
make deps     # composer install + npm ci
make build    # compila il frontend in js/ e css/
make test     # lint, php-cs-fixer, psalm, rector, phpunit, eslint, vue-tsc
make dist     # archivio pronto da installare
```

Struttura:

```
appinfo/info.xml                 manifesto dell'app
lib/AppInfo/Application.php      registrazioni (notifier, listener, servizi)
lib/Controller/                  ConvertController (utenti), SettingsController (admin)
lib/BackgroundJob/ConvertJob.php il lavoro accodato: legge gli argomenti e delega
lib/Service/Conversione.php      il flusso completo della conversione
lib/Service/ImpaginaClient.php   client degli endpoint del servizio
lib/Service/ConfigService.php    accesso tipizzato alla configurazione
lib/Model/                       valori immutabili e lettura tipizzata del JSON
lib/Exception/                   eccezioni per categoria di trattamento
lib/Notification/Notifier.php    traduzione delle notifiche
src/files.ts                     azione nel menu contestuale di File
src/admin/                       pagina di amministrazione (Vue)
l10n/                            traduzioni (italiano)
stubs/                           classi dell'app File, solo per l'analisi statica
tests/                           PHPUnit: 206 test
docs/impagina-openapi.yaml       il contratto del servizio impagina (2.0.0)
.github/workflows/               la CI su GitHub Actions
```

I test girano **senza** un server Nextcloud: le classi `OCP\` arrivano dagli
stub di `nextcloud/ocp` e il servizio HTTP è simulato
(`tests/Finzione/ServizioFinto.php`).

La CI su GitHub Actions fa gli stessi controlli di `make test`, divisi in
quattro workflow: `lint-php.yml` (sintassi su PHP 8.3, 8.4 e 8.5, e
php-cs-fixer), `phpunit.yml` (i test sulle tre versioni di PHP),
`static-analysis.yml` (Psalm e Rector) e `lint-js.yml` (ESLint, Stylelint,
vue-tsc e build). L'analisi statica gira con gli stub della 33 (quelli di
`composer.lock`), della 34 e della 35, che bloccano, e con quelli di `master`,
che informano soltanto. Si sviluppa contro la 33, la versione minima: così
Psalm segnala subito una API arrivata dopo.

Psalm gira a `errorLevel="1"`; con poca memoria a disposizione conviene
lanciarlo con un tetto esplicito:

```bash
php -d memory_limit=900M vendor/bin/psalm --threads=1 --no-cache --no-progress
```

### Verifiche manuali da fare su una istanza vera

1. Un `.md` con immagini in sottocartelle produce il PDF nella stessa cartella e
   arriva la notifica di successo.
2. Ripetendo la conversione si ottiene `nome (2).pdf`, senza sovrascrivere.
3. Un `.md` con un'immagine mancante, un URL esterno o un percorso assoluto
   fallisce con una notifica che li elenca **tutti**, senza creare il lavoro sul
   servizio.
4. Un utente fuori dai gruppi abilitati non vede l'azione e, chiamando
   l'endpoint direttamente, riceve `403`.
5. Con servizio irraggiungibile, token errato o template inesistente l'utente
   riceve una notifica comprensibile e l'amministratore trova i dettagli nel log.
6. Il token non compare da nessuna parte:
   `docker exec nextcloud-aio-nextcloud grep -ri 'imp_' /mnt/ncdata/nextcloud.log`.
7. L'app si abilita e supera tutti i punti precedenti anche su una istanza
   `master` (Nextcloud 36).

## Compatibilità e aggiornamento alla 36

`appinfo/info.xml` dichiara `min-version="33" max-version="35"`. Dalla 33 alla
35 l'app usa le stesse API: l'app File usa ovunque Vue 3 e `@nextcloud/files`
4, e le uniche differenze emerse riguardano gli stub usati nei test. Fino alla
34 `IRootFolder` estende l'interfaccia interna `OC\Hooks\Emitter`, fornita da
`stubs/oc-hooks.php`; `IClient::sendRequest()` esiste solo dalla 34.

La 36 è ancora
in sviluppo e la sua documentazione per sviluppatori non elenca i cambiamenti,
quindi la compatibilità **non si dichiara a scatola chiusa**. Il codice è però
già scritto per reggerla: solo API pubbliche `OCP\`, rotte con attributi PHP,
nessuna API globale `OC.*` e nessun componente rimosso.

**Cambiamenti della 36 già emersi.** `IRootFolder::getUserFolder()` dichiarerà
`OCP\Files\IUserFolder` (che estende `Folder`) invece di `Folder`. Il codice
dell'app non ne risente; se ne accorgono solo i test, che scelgono l'interfaccia
in base a quella installata (`tests/Finzione/AlberoFinto.php`). Al 2026-11-08 il
job `master` dell'analisi statica è verde.

Quando esce la 36:

1. Leggi *Critical changes*, *Deprecations* e *New in this release* del
   Developer Manual della 36 (o i file `.rst` in
   `nextcloud/documentation/developer_manual/release_notes/`).
2. Guarda l'esito del job `psalm + rector (Nextcloud master)` del workflow
   `.github/workflows/static-analysis.yml`, che gira Psalm e Rector con gli stub
   `nextcloud/ocp:dev-master` e che fino ad allora non blocca la CI.
3. Installa l'app su una istanza `master` ed esegui le sette verifiche manuali
   qui sopra.
4. Solo allora porta `max-version` a `36`, aggiorna il `CHANGELOG.md` e rilascia.

## Licenza

Il codice dell'app è distribuito con licenza [MIT](LICENSE).

L'archivio compilato contiene anche le librerie JavaScript di Nextcloud
(`@nextcloud/vue`, `@nextcloud/files`, `@nextcloud/dialogs` e altre), che hanno
licenza AGPL-3.0-or-later o GPL-3.0-or-later e mantengono la propria: l'elenco
completo, con versioni e licenze, è nei file `js/*.license` generati dalla
build, e il testo dell'AGPL è in
[`LICENSES/AGPL-3.0-or-later.txt`](LICENSES/AGPL-3.0-or-later.txt).
Chi ridistribuisce l'archivio compilato deve quindi rispettare anche quelle
licenze.
