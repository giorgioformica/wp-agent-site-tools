# Agent Site Tools

Plugin WordPress che espone l'intero sito a un assistente AI (Claude Desktop o qualsiasi client MCP) come un insieme di **abilities**: azioni con nome, schema di input, controllo dei permessi e snapshot ripristinabile prima di ogni scrittura.

Nasce da un progetto reale (sito istituzionale bilingue su WordPress + Elementor costruito interamente dialogando con Claude Desktop) ed è pubblicato in forma neutra, senza riferimenti al cliente e senza alcuna credenziale.

> Per chi deve **estendere** il plugin o scriverne di simili, compresa un'altra AI: leggere [`AGENTS.md`](AGENTS.md).
> Strumenti di terze parti, gratuiti e a pagamento: [`TERZE-PARTI.md`](TERZE-PARTI.md).

## Come funziona

```
Claude Desktop ──stdio──▶ mcp-remote (sul computer di chi lavora)
                               │  HTTPS + password applicativa WordPress
                               ▼
                 /wp-json/ast/mcp   (MCP Adapter)
                               │
                               ▼
                 Abilities API  ── permission_callback: capacità del ruolo WordPress
                               │
        ┌──────────────────────┼───────────────────────┐
        ▼                      ▼                       ▼
 Agent Site Tools (ast/*)   Elementor MCP        abilities di altri plugin
 contenuti, Elementor V3,   sezioni V4 atomiche  (es. All in One SEO, aioseo/*)
 file, design, sistema
                               │
                               ▼
                 snapshot dello stato precedente → scrittura
```

- **Abilities API** (core WordPress ≥ 6.9): registro delle azioni.
- **MCP Adapter** (plugin ufficiale WordPress, incluso anche nelle versioni di Elementor dotate di Elementor MCP): le espone come strumenti MCP.
- **Agent Site Tools** registra 44 abilities con prefisso `ast/` e crea l'endpoint dedicato `/wp-json/ast/mcp`, sul quale aggiunge anche le abilities di plugin partner (per default `aioseo/*`).

## Requisiti

- WordPress 6.9 o successivo (consigliato 7.x), PHP 7.4+
- MCP Adapter attivo: plugin [MCP Adapter](https://github.com/WordPress/mcp-adapter) oppure Elementor con Elementor MCP, che lo include tra le dipendenze (verificato sulla 4.4)
- Elementor (facoltativo, ma è il caso d'uso principale)
- Sul computer di chi usa Claude Desktop: Node.js (per `npx mcp-remote`)

## Installazione

1. Copiare `plugin/agent-site-tools/` in `wp-content/plugins/` e attivarlo.
2. Facoltativo, per il motore di animazioni: scaricare GSAP e Lenis come indicato in [`plugin/agent-site-tools/assets/vendor/LEGGIMI.md`](plugin/agent-site-tools/assets/vendor/LEGGIMI.md). Senza le librerie il motore resta spento e il resto funziona.
3. In **Strumenti → Agent Site Tools** verificare che l'MCP Adapter risulti attivo e annotare l'endpoint.
4. Creare una **password applicativa** (Utenti → Profilo → Password applicative) per l'utente con cui l'assistente lavorerà.

## Collegamento a Claude Desktop

Esempio in [`esempi/claude_desktop_config.esempio.json`](esempi/claude_desktop_config.esempio.json). L'intestazione è `Basic` + base64 di `utente:password-applicativa`:

```bash
printf 'utente:xxxx xxxx xxxx xxxx xxxx xxxx' | base64
```

La password applicativa è una credenziale: non va messa in repository, si revoca dal profilo utente. Per Elementor conviene usare la configurazione generata da **Elementor → Elementor MCP**.

## Strumenti

| Dominio | Abilities |
|---|---|
| Contenuti | `content-list`, `content-get`, `content-save` (anche SEO), `content-delete`, `terms-list`, `terms-save` |
| Elementor | `elementor-outline`, `elementor-get`, `elementor-patch`, `elementor-save`, `elementor-search`, `elementor-replace` (con prova a vuoto), `elementor-create`, `elementor-templates`, `elementor-set-conditions`, `elementor-kit-get`, `elementor-kit-update` |
| Media | `media-upload` (da URL, deduplicato), `media-list`, `media-update` |
| Menu | `menus-get`, `menu-save` |
| Sito | `site-info`, `option-get`, `option-update`, `theme-mods`, `homepage-set` |
| Design | `asset-write`, `asset-list`, `asset-delete` (CSS/JS versionati per ambito), `motion-settings` |
| File e plugin | `file-list`, `file-read`, `file-search`, `file-write`, `file-delete`, `log-read`, `plugin-list`, `plugin-activate`, `plugin-deactivate`, `theme-switch` |
| Sistema | `cache-purge`, `snapshots-list`, `snapshot-restore` |

Le stesse abilities si possono invocare via REST dal browser autenticato:
`GET /wp-json/wp-abilities/v1/abilities/ast/<nome>/run?input[chiave]=valore` (sola lettura) oppure `POST …/run` con corpo `{"input": {…}}` e intestazione `X-WP-Nonce`.

## Sicurezza

- **Permessi per ruolo.** Ogni ability verifica una capacità WordPress (`edit_posts`, `upload_files`, `edit_theme_options`, `manage_options`…). Un utente Editore collegato con la propria password applicativa può lavorare sui contenuti ma non su file, plugin e opzioni.
- **Snapshot.** Prima di ogni scrittura lo stato precedente va nella tabella `{prefix}ast_snapshots` (ultimi 30 per oggetto); ripristino con `snapshot-restore` o dalla pagina del plugin.
- **File.** Scrittura solo in `themes/`, `mu-plugins/`, `plugins/ast-*` (filtro `ast_writable_roots`), disattivabile dalla pagina del plugin; controllo di sintassi PHP prima, verifica dopo, ripristino automatico in caso di errore fatale.
- **Segreti.** Le opzioni con nomi da credenziale (`secret`, `token`, `password`, `api_key`, `license`…) non vengono restituite né scritte.
- **Contenuti ostili.** L'assistente legge testi scritti da altri (commenti, moduli): a un profilo redazionale non vanno dati i verbi di file e plugin. Vedi «Profilo redazione» in `AGENTS.md`.

## Filtri e azioni

| Hook | Tipo | Uso |
|---|---|---|
| `ast_partner_prefixes` | filtro | prefissi di abilities di altri plugin da esporre sull'endpoint (default `['aioseo/']`) |
| `ast_writable_roots` | filtro | cartelle di `wp-content` scrivibili |
| `ast_theme_option_prefixes` | filtro | prefissi delle opzioni del tema leggibili e scrivibili (default vuoto) |
| `ast_option_updated` | azione | dopo `option-update` (es. rigenerare il CSS dinamico del tema) |
| `ast_cache_purge` | filtro | aggiungere la pulizia di cache proprie a `cache-purge` |

## Struttura

```
plugin/agent-site-tools/   il plugin
esempi/                    configurazione client e plugin di esempio con abilities aggiuntive
AGENTS.md                  guida allo sviluppo di nuovi connettori (scritta anche per un'AI)
TERZE-PARTI.md             strumenti pubblici e a pagamento usati
```

## Licenza

GPL-2.0-or-later. Le librerie GSAP e Lenis non sono incluse e restano soggette alle rispettive licenze.

Realizzato da GeniusWeb S.r.l.
