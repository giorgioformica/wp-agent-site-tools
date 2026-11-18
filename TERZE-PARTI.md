# Strumenti di terze parti

Strumenti usati nel progetto da cui nasce Agent Site Tools. Nessuno è incluso nella repository, salvo dove indicato.

## Indispensabili

| Strumento | Ruolo | Costo | Licenza / riferimento |
|---|---|---|---|
| [WordPress](https://wordpress.org) ≥ 6.9 (consigliato 7.x) | CMS; Abilities API nel core, AI Client dalla 7.0 | gratuito | GPL-2.0-or-later |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Espone le abilities come strumenti MCP | gratuito | GPL-2.0-or-later; incluso anche in Elementor (verificato sulla 4.4) |
| [Claude Desktop](https://claude.ai/download) | Client MCP con cui si dialoga con il sito; browser integrato per le verifiche visive | app gratuita, uso secondo il piano Claude ([prezzi](https://claude.com/pricing)) | Anthropic |
| [mcp-remote](https://www.npmjs.com/package/mcp-remote) | Ponte locale stdio → HTTP tra Claude Desktop e l'endpoint del sito | gratuito | open source (npm) |
| [Node.js](https://nodejs.org) | Esegue `npx mcp-remote` sul computer di chi lavora | gratuito | MIT |

Alternativa a `mcp-remote`: [@automattic/mcp-wordpress-remote](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote).

## Page builder

| Strumento | Ruolo | Costo |
|---|---|---|
| [Elementor](https://elementor.com) ≥ 4.3 | Page builder; server **Elementor MCP** ufficiale per costruire sezioni V4 (Elementor → Elementor MCP) | gratuito |
| [Elementor Pro](https://elementor.com/pricing/) | Theme Builder (header, footer, modelli), popup, moduli | a pagamento |
| [PRO Elements](https://github.com/proelements/proelements) | Alternativa GPL alle funzioni Pro usata nel progetto | gratuito |
| Tema commerciale compatibile con Elementor | Struttura e widget del tema | a pagamento, non incluso |

## Plugin usati accanto al connettore

| Strumento | Ruolo | Costo |
|---|---|---|
| [All in One SEO](https://wordpress.org/plugins/all-in-one-seo-pack/) | SEO; dalla 4.9.8 registra abilities `aioseo/*` che il connettore espone sullo stesso endpoint | gratuito, versione Pro a pagamento |
| [Polylang](https://wordpress.org/plugins/polylang/) | Sito bilingue; il connettore applica gli asset per pagina anche alle traduzioni | gratuito, versione Pro a pagamento |
| [WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/) | Invio delle email dei moduli | gratuito, versione Pro a pagamento |
| Speed Optimizer (SiteGround) | Cache; `cache-purge` la svuota se presente | incluso nell'hosting SiteGround |

## Librerie per il motore di animazioni (facoltativo)

| Libreria | Versione | Licenza |
|---|---|---|
| [GSAP](https://gsap.com), ScrollTrigger, SplitText | 3.15.0 | [Standard "No Charge" License](https://gsap.com/standard-license) |
| [Lenis](https://github.com/darkroomengineering/lenis) | 1.3.27 | MIT |

Non sono distribuite con il plugin: istruzioni in `plugin/agent-site-tools/assets/vendor/LEGGIMI.md`.

## Strumenti di metodo (non necessari al plugin)

- **Progetti Claude**: documenti di passaggio di consegne, manuale delle pagine, lessico e diario delle modifiche, per dare continuità tra sessioni.
- **Skill**: istruzioni riutilizzabili caricate dal modello (revisione dei testi, regole SEO, costruzione delle pagine).
- **Agenti in parallelo**: traduzioni e revisioni affidate a sessioni distinte con un brief comune.
