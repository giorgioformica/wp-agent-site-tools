=== Agent Site Tools ===
Requires at least: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later

Strumenti MCP per il controllo completo del sito da parte di un assistente AI.

== Requisiti ==
* WordPress 6.9+ (Abilities API nel core)
* MCP Adapter attivo (plugin ufficiale, oppure incluso in Elementor con Elementor MCP) per l'endpoint dedicato /wp-json/ast/mcp

== Strumenti (ast/*) ==
Elementor: elementor-outline, elementor-get, elementor-save, elementor-patch, elementor-search,
elementor-replace, elementor-create, elementor-templates, elementor-set-conditions,
elementor-kit-get, elementor-kit-update
Contenuti: content-list, content-get, content-save, content-delete, terms-list, terms-save
Media: media-upload, media-list, media-update
Menu: menus-get, menu-save
Sito: site-info, option-get, option-update, theme-mods, homepage-set
Design: asset-write, asset-list, asset-delete, motion-settings
Sistema: cache-purge, snapshots-list, snapshot-restore, log-read
File: file-list, file-read, file-search (tutto wp-content), file-write, file-delete (solo themes/, mu-plugins/, plugins/ast-*)
Plugin: plugin-list, plugin-activate, plugin-deactivate
Tema: theme-switch
SEO: le abilities di All in One SEO 4.9.8+ (aioseo/*) vengono esposte sullo stesso endpoint.

Ogni scrittura salva uno snapshot (tabella {prefix}ast_snapshots, ultimi 30 per oggetto),
ripristinabile da Strumenti → Agent Site Tools o con snapshot-restore.
Scrittura file: disattivabile da Strumenti → Agent Site Tools. I PHP passano un controllo di sintassi
prima della scrittura e una richiesta di verifica (admin-ajax) dopo: in caso di errore fatale
il file (o il tema) viene ripristinato automaticamente.

== Hook ==
ast_partner_prefixes, ast_writable_roots, ast_theme_option_prefixes (filtri); ast_option_updated (azione); ast_cache_purge (filtro).
Dettagli ed esempi in AGENTS.md alla radice della repository.

== Changelog ==
= 1.2.0 (edizione pubblica) =
* Nomi neutri (prefisso ast/, endpoint /wp-json/ast/mcp, tabella ast_snapshots).
* Opzioni del tema abilitabili con il filtro ast_theme_option_prefixes; hook ast_option_updated e ast_cache_purge al posto dell'integrazione con un tema specifico.
* Asset per pagina validi anche per le traduzioni Polylang.
* Librerie GSAP e Lenis non incluse (assets/vendor/LEGGIMI.md): senza di esse il motore di animazioni resta spento.
= 1.2.0 =
* Scrittura anche in plugins/ast-* (plugin propri).
* plugin-list, plugin-activate (prova di caricamento in processo separato prima dell'attivazione), plugin-deactivate.
= 1.1.0 =
* Lettura, ricerca e confronto (md5) dei file di wp-content; lettura dei log di errore.
* Scrittura file in themes/ e mu-plugins/ con snapshot, controllo sintassi e ripristino automatico.
* Attivazione tema con copia dei theme mods (child theme).

== Animazioni ==
Classi: ast-reveal, ast-stagger, ast-split-text, ast-parallax, ast-pin, ast-hscroll,
ast-counter, ast-rule (classi globali V4, classi CSS V3 o del tema o data-ast="ruolo").
Rispetta prefers-reduced-motion; disattivato nell'editor Elementor.

== Librerie (non incluse, vedi assets/vendor/LEGGIMI.md) ==
* GSAP 3.15.0, ScrollTrigger, SplitText — GreenSock Standard "No Charge" License (https://gsap.com/standard-license)
* Lenis 1.3.27 — MIT (darkroom.engineering)
