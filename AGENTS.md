# Guida per chi sviluppa connettori (umani e AI)

Questo file spiega come aggiungere abilities ad Agent Site Tools o scrivere un connettore analogo per un altro sito WordPress. È scritto per essere letto anche da un agente AI: le regole sono numerate e ciascuna dice come verificarla.

## 1. Modello mentale

- Un **connettore** non è un server MCP scritto a mano: è un insieme di **abilities** registrate sull'Abilities API di WordPress. L'**MCP Adapter** le trasforma in strumenti MCP.
- Un'ability è un **verbo**: nome, descrizione, schema di input, funzione di esecuzione, funzione di permesso, annotazioni.
- Il client (Claude Desktop) vede l'elenco degli strumenti e legge le descrizioni. **La descrizione è l'unico manuale che il modello consulta.**
- Il perimetro di ciò che l'assistente può fare coincide con le **capacità del ruolo WordPress** dell'utente con cui si collega.

## 2. Da leggere prima di scrivere codice

1. `plugin/agent-site-tools/includes/class-abilities.php`: come `Abilities::add()` diventa `wp_register_ability()`.
2. `includes/class-util.php`: costruttori di schema (`Util::obj`, `s`, `i`, `b`, `o`, `a`, `any`, `note`), lettura sicura dell'input (`Util::int`, `str`, `bool`, `arr`), errori (`Util::err`).
3. `includes/class-snapshots.php`: `Snapshots::take()` e i tipi ripristinabili (`elementor`, `kit`, `post`, `option`, `theme_mod`, `asset`, `menu`, `file`, `theme`).
4. Un file di esempio in `includes/abilities/`, per esempio `content.php`.
5. `esempi/ast-esempio/ast-esempio.php`: un plugin separato che aggiunge abilities senza toccare il connettore.

## 3. Anatomia di un'ability

```php
use AgentSiteTools\Abilities;
use AgentSiteTools\Snapshots;
use AgentSiteTools\Util;

Abilities::add(
	'dominio-azione',                       // diventa ast/dominio-azione
	array(
		'label'       => 'Dominio: azione',
		'description' => 'Che cosa fa. Quando usarla. Che cosa restituisce. Che cosa leggere prima.',
		'input'       => Util::obj(
			array(
				'post_id' => Util::i( 'ID del contenuto.' ),
				'note'    => Util::note(),      // nota salvata con lo snapshot
			),
			array( 'post_id' )                // campi obbligatori
		),
		'perm'        => static function ( array $in ): bool {
			return current_user_can( 'edit_post', Util::int( $in, 'post_id' ) );
		},
		// in alternativa: 'cap' => 'edit_posts',
		'destructive' => false,
		'readonly'    => false,
		'idempotent'  => false,
		'run'         => static function ( array $in ) {
			// 1. leggere e validare
			// 2. Snapshots::take( … ) prima di scrivere
			// 3. scrivere
			// 4. restituire un array compatto con snapshot_id
		},
	)
);
```

Le abilities vanno aggiunte **prima** di `wp_abilities_api_init`. Da un plugin esterno: `add_action( 'plugins_loaded', …, 20 )`, controllando che `class_exists( '\AgentSiteTools\Abilities' )`.

## 4. Regole

Ogni regola ha accanto il modo per verificarla.

1. **Nome = dominio + verbo**, in kebab-case (`content-save`, `elementor-patch`). *Verifica:* il nome da solo dice che cosa cambia.
2. **Descrizione scritta per il modello**: che cosa fa, quando usarla e quando no, che cosa restituisce, quale ability leggere prima (es. «Leggere `elementor-outline` prima di modificare»). *Verifica:* un modello che non ha mai visto il codice saprebbe usarla correttamente leggendo solo la descrizione.
3. **Schema stretto**: solo i campi necessari, tipi espliciti, valori ammessi e default scritti nella descrizione del campo, `required` minimo. *Verifica:* nessun campo «jolly» che accetta qualsiasi struttura senza spiegarla.
4. **Permesso più stretto possibile**: capacità sull'oggetto (`edit_post`, `delete_post`) quando l'ability agisce su un oggetto, capacità generale (`edit_posts`, `upload_files`, `edit_theme_options`, `manage_options`) altrimenti. Mai `__return_true`. *Verifica:* collegandosi come Editore, l'ability è rifiutata se tocca file, plugin o opzioni.
5. **Snapshot prima di ogni scrittura** con `Snapshots::take( tipo, chiave, stato, etichetta, nota )`, e `snapshot_id` nella risposta. Per un tipo nuovo va aggiunto il relativo `case` in `Snapshots::restore()`. *Verifica:* `snapshots-list` mostra la voce e `snapshot-restore` riporta l'oggetto allo stato precedente.
6. **Modifiche puntuali prima delle sostituzioni**: si agisce per identificativo (elemento, chiave, riga) invece di riscrivere l'intero oggetto; le operazioni di massa hanno `dry_run`. *Verifica:* esiste un modo di cambiare una parola senza inviare l'intero documento.
7. **Database: il minor numero di query.** Query batch e JOIN, cache precaricate (`update_post_thumbnail_cache`, `update_meta_cache`), mai una query per riga dentro un ciclo. *Verifica:* il numero di query non cresce con il numero di risultati.
8. **Risposte compatte**: estratti invece di HTML completo, paginazione (`per_page`, `page`), solo i campi utili. Il contesto del modello è una risorsa scarsa. *Verifica:* un elenco di 100 voci resta leggibile.
9. **Segreti mai in uscita**: opzioni e meta con nomi da credenziale si filtrano con `Util::is_secret_name()`; nessuna chiave nei log né nelle risposte. *Verifica:* `option-get` su un nome con `api_key` restituisce un errore.
10. **Errori utili al modello**: `Util::err( 'codice', 'messaggio che dice come rimediare', status )`. *Verifica:* il messaggio indica l'ability o il parametro da usare.
11. **File PHP solo attraverso `Files`**: controllo di sintassi prima, verifica dopo, ripristino automatico. Nessuna scrittura diretta con `file_put_contents` su codice eseguibile. *Verifica:* un file con un errore di sintassi viene rifiutato e il sito resta in piedi.
12. **Annotazioni corrette**: `readonly` per le letture, `destructive` per cancellazioni e sostituzioni, `idempotent` se ripetere la chiamata non cambia nulla. *Verifica:* il client chiede conferma sulle operazioni distruttive.
13. **Cache**: le abilities che cambiano ciò che vede il pubblico lo dicono nella descrizione e suggeriscono `cache-purge`. *Verifica:* dopo la modifica, una richiesta anonima mostra il nuovo contenuto.
14. **Lingua coerente**: label e descrizioni nella lingua delle altre abilities del connettore.

## 5. Abilities di altri plugin sullo stesso endpoint

Qualsiasi plugin che registri abilities pubbliche può comparire sull'endpoint `/wp-json/ast/mcp` aggiungendo il suo prefisso:

```php
add_filter( 'ast_partner_prefixes', function ( array $p ) {
	$p[] = 'woocommerce/';
	return $p;
} );
```

## 6. Temi con opzioni proprie

Le opzioni del tema non sono leggibili né scrivibili per default. Per abilitarle:

```php
add_filter( 'ast_theme_option_prefixes', fn () => array( 'mytheme_', 'optionsframework' ) );

// Se il tema genera CSS dinamico dalle opzioni, rigenerarlo dopo la scrittura:
add_action( 'ast_option_updated', function ( string $name ) {
	if ( 0 === strpos( $name, 'mytheme_' ) && function_exists( 'mytheme_regenerate_css' ) ) {
		mytheme_regenerate_css();
	}
} );

// Cache aggiuntive da svuotare con cache-purge:
add_filter( 'ast_cache_purge', function ( array $done ) {
	if ( function_exists( 'mytheme_flush_cache' ) ) {
		mytheme_flush_cache();
		$done[] = 'mytheme';
	}
	return $done;
} );
```

## 7. Profilo redazione per il cliente

Per consegnare l'assistente a chi gestisce i contenuti:

1. Creare un utente con ruolo **Editore** e una password applicativa dedicata.
2. Esporre un endpoint con le sole abilities editoriali, così l'elenco degli strumenti resta corto e non contiene verbi che verrebbero comunque rifiutati:

```php
add_action( 'mcp_adapter_init', function ( $adapter ) {
	$editoriali = array(
		'ast/content-list', 'ast/content-get', 'ast/content-save',
		'ast/terms-list', 'ast/terms-save',
		'ast/media-upload', 'ast/media-list', 'ast/media-update',
	);
	$adapter->create_server(
		'ast-redazione', 'ast', 'redazione',
		'Agent Site Tools — redazione',
		'Contenuti e media del sito, senza accesso a file, plugin e opzioni.',
		'v1',
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		$editoriali, array(), array()
	);
}, 20 );
```

3. Collegare il client a `/wp-json/ast/redazione` invece che a `/wp-json/ast/mcp`.
4. Consegnare insieme le regole editoriali del sito (tono, lessico, formule da evitare) come skill o istruzioni di progetto: l'assistente del cliente scriverà con la stessa voce.

Il ripristino degli snapshot resta all'amministratore. Un ruolo ridotto limita i danni ma non è una sandbox: l'assistente legge testi scritti da altri, che possono contenere istruzioni.

## 8. Verifica di una nuova ability

1. Controllo di sintassi: `php -l` su ogni file modificato.
2. Il client vede lo strumento (`tools/list`) con nome e descrizione attesi.
3. Chiamata diretta via REST dal browser autenticato:
   - lettura: `GET /wp-json/wp-abilities/v1/abilities/ast/<nome>/run?input[campo]=valore`
   - scrittura: `POST /wp-json/wp-abilities/v1/abilities/ast/<nome>/run` con `{"input": {…}}` e intestazione `X-WP-Nonce` (nonce da `wpApiSettings.nonce` in bacheca)
4. Come Editore: le abilities riservate agli amministratori rispondono con errore di permesso.
5. Dopo una scrittura: snapshot presente, ripristino funzionante, cache svuotata, verifica da visitatore anonimo.

## 9. Da non fare

- Esporre l'accesso al database o all'FTP come strumento generico.
- Restituire interi documenti quando basta un estratto.
- Scrivere chiavi, password applicative o token in file del repository o in risposte delle abilities.
- Duplicare ciò che il produttore di un plugin offre già con abilities proprie (es. Elementor MCP per le sezioni V4): si colmano soltanto le lacune.
- Lasciare che una scrittura avvenga senza snapshot.
