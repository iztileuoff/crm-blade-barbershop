import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * Channel substitutions for every color Tailwind feeds into color-mix() via an
 * opacity modifier. color-mix(in oklab, C N%, transparent) is visually
 * identical to rgb(<channels of C> / N%) — mixing with a fully transparent
 * color only scales the alpha, it does not shift the color. Semantic tokens map
 * to their --*-rgb channel vars (which flip light/dark, see app.css); palette
 * and black/white colors are static, so their channels are inlined.
 */
const colorMixChannels = {
    'var(--content)': 'var(--content-rgb)',
    'var(--surface)': 'var(--surface-rgb)',
    'var(--brass)': 'var(--brass-rgb)',
    'var(--brass-ink)': 'var(--brass-ink-rgb)',
    'var(--success)': 'var(--success-rgb)',
    'var(--danger)': 'var(--danger-rgb)',
    'var(--info)': 'var(--info-rgb)',
    'var(--royal)': 'var(--royal-rgb)',
    'var(--color-white)': '255 255 255',
    'var(--color-black)': '0 0 0',
    'var(--color-blue-500)': '48 128 255',
    'var(--color-emerald-500)': '0 187 127',
    'var(--color-emerald-900)': '0 78 59',
    'var(--color-violet-500)': '141 84 255',
};

/**
 * Rewrites Tailwind's color-mix() opacity output to rgb()-with-alpha at build
 * time so translucent colors render in browsers without color-mix() support
 * (Chrome < 111 / Safari < 16.4 — e.g. Chrome 109 on Windows 8.1). Runs after
 * Lightning CSS minification on the final bundle; anything not in the channel
 * map (e.g. currentcolor) is left untouched.
 */
function colorMixRgbFallback() {
    // Opacity-modifier output: color-mix(in oklab, C N%, transparent) → rgb(C / N%).
    const opacityPattern = /color-mix\(in oklab,\s*(var\(--[a-z0-9-]+\)|currentcolor)\s+([\d.]+)%,\s*transparent\)/gi;

    // Shadow utilities wrap the color again with --tw-*-alpha (default 100%):
    // color-mix(in oklab, <rgb(...)> var(--tw-shadow-alpha), transparent). After
    // the inner mix is rewritten above this outer mix is a no-op, so collapse it
    // to the inner rgb() color.
    const shadowWrapper = /color-mix\(in oklab,\s*(rgb\((?:[^()]+|\([^()]*\))*\))\s+var\(--tw-(?:shadow|inset-shadow|drop-shadow)-alpha\),\s*transparent\)/gi;

    // When targeting browsers without color-mix(), Lightning CSS keeps the modern
    // value inside `@supports (color: color-mix(in lab, red, red))` and emits a
    // bare (alpha-stripped) fallback outside it. Once we've rewritten that modern
    // value to rgb() — which the old browsers DO support — the gate is exactly
    // backwards: it hides our usable fallback behind the feature it replaces.
    // Relax the probe to an always-true condition so old browsers apply the rgb()
    // rules too. Declarations we left as color-mix (e.g. currentcolor) simply fail
    // to parse there and harmlessly fall back to the bare value, as before.
    const colorMixSupportsGate = /@supports \(color:\s*color-mix\(in lab, red, red\)\)/gi;

    return {
        name: 'color-mix-rgb-fallback',
        apply: 'build',
        generateBundle(_options, bundle) {
            for (const file of Object.values(bundle)) {
                if (file.type === 'asset' && file.fileName.endsWith('.css')) {
                    let css = typeof file.source === 'string' ? file.source : file.source.toString();

                    css = css.replace(opacityPattern, (match, color, percent) => {
                        const channels = colorMixChannels[color];

                        return channels ? `rgb(${channels} / ${percent}%)` : match;
                    });

                    css = css.replace(shadowWrapper, (_match, color) => color);

                    css = css.replace(colorMixSupportsGate, '@supports (color: red)');

                    file.source = css;
                }
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        colorMixRgbFallback(),
    ],
    css: {
        transformer: 'lightningcss',
        lightningcss: {
            // Downlevels oklch()/lab() to rgb fallbacks for old browsers.
            targets: {
                chrome: 109 << 16,
                safari: 15 << 16,
                firefox: 102 << 16,
            },
        },
    },
    build: {
        cssMinify: 'lightningcss',
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
