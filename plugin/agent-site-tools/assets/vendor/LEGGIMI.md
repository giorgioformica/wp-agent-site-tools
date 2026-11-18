# Librerie del motore di animazioni

Le librerie non sono distribuite con il plugin. Senza questi file il motore di animazioni resta spento; il resto del plugin funziona normalmente.

Copiare in questa cartella:

| File | Origine |
|---|---|
| `gsap.min.js` | pacchetto npm `gsap@3.15.0`, `dist/gsap.min.js` |
| `ScrollTrigger.min.js` | pacchetto npm `gsap@3.15.0`, `dist/ScrollTrigger.min.js` |
| `SplitText.min.js` | pacchetto npm `gsap@3.15.0`, `dist/SplitText.min.js` |
| `lenis.min.js` | pacchetto npm `lenis@1.3.27`, `dist/lenis.min.js` (solo se lo scroll fluido è attivo) |

Esempio:

```bash
npm pack gsap@3.15.0 lenis@1.3.27
tar -xzf gsap-3.15.0.tgz package/dist/gsap.min.js package/dist/ScrollTrigger.min.js package/dist/SplitText.min.js
tar -xzf lenis-1.3.27.tgz package/dist/lenis.min.js
mv package/dist/*.js .
```

Licenze: GSAP [Standard "No Charge" License](https://gsap.com/standard-license), Lenis MIT.
