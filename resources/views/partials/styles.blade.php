<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
<style>
    /* Mirrors the default Laravel (Breeze) look without requiring a front-end build. */
    *, ::before, ::after { box-sizing: border-box; border: 0 solid #e5e7eb; }
    body { margin: 0; font-family: Figtree, ui-sans-serif, system-ui, sans-serif; color: #111827; background: #f3f4f6; -webkit-font-smoothing: antialiased; line-height: 1.5; }
    a { color: inherit; }
    h1, h2, h3, p { margin: 0; }
    svg { display: block; }
    .sa-guest { min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding-top: 1.5rem; }
    .sa-logo { width: 5rem; height: 5rem; color: #6b7280; }
    .sa-card { width: 100%; margin-top: 1.5rem; padding: 1rem 1.5rem; background: #fff; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .1), 0 2px 4px -2px rgb(0 0 0 / .1); overflow: hidden; }
    .sa-nav { background: #fff; border-bottom: 1px solid #f3f4f6; }
    .sa-nav-inner, .sa-container { max-width: 80rem; margin: 0 auto; padding: 0 1rem; }
    .sa-nav-inner { display: flex; justify-content: space-between; align-items: center; height: 4rem; }
    .sa-nav-inner .sa-logo { width: 2.25rem; height: 2.25rem; color: #1f2937; }
    .sa-header { background: #fff; box-shadow: 0 1px 3px 0 rgb(0 0 0 / .1), 0 1px 2px -1px rgb(0 0 0 / .1); }
    .sa-header h2 { font-weight: 600; font-size: 1.25rem; line-height: 1.75rem; color: #1f2937; padding: 1.5rem 0; }
    .sa-main { padding: 3rem 0; }
    .sa-main > .sa-container > * + * { margin-top: 1.5rem; }
    .sa-section { padding: 1rem; background: #fff; box-shadow: 0 1px 3px 0 rgb(0 0 0 / .1), 0 1px 2px -1px rgb(0 0 0 / .1); }
    .sa-section > * { max-width: 36rem; }
    .sa-title { font-size: 1.125rem; line-height: 1.75rem; font-weight: 500; color: #111827; }
    .sa-text { margin-top: .25rem; font-size: .875rem; line-height: 1.25rem; color: #4b5563; }
    .sa-mt-4 { margin-top: 1rem; } .sa-mt-6 { margin-top: 1.5rem; } .sa-mb-4 { margin-bottom: 1rem; }
    .sa-label { display: block; font-weight: 500; font-size: .875rem; line-height: 1.25rem; color: #374151; }
    .sa-input { display: block; width: 100%; margin-top: .25rem; padding: .5rem .75rem; font: inherit; font-size: 1rem; border: 1px solid #d1d5db; border-radius: .375rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05); background: #fff; }
    .sa-input:focus { outline: 2px solid transparent; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
    .sa-error { margin: .5rem 0 0; padding: 0; list-style: none; font-size: .875rem; color: #dc2626; }
    .sa-status { margin-bottom: 1rem; font-weight: 500; font-size: .875rem; color: #16a34a; }
    .sa-actions { display: flex; align-items: center; justify-content: flex-end; gap: 1rem; margin-top: 1rem; }
    .sa-actions.sa-between { justify-content: space-between; }
    .sa-btn { display: inline-flex; align-items: center; padding: .5rem 1rem; font: inherit; font-weight: 600; font-size: .75rem; line-height: 1rem; letter-spacing: .1em; text-transform: uppercase; text-decoration: none; border: 1px solid transparent; border-radius: .375rem; cursor: pointer; transition: background-color .15s ease-in-out; color: #fff; background: #1f2937; }
    .sa-btn:hover { background: #374151; }
    .sa-btn:focus { outline: 2px solid transparent; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #6366f1; }
    .sa-btn-secondary { color: #374151; background: #fff; border-color: #d1d5db; box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05); }
    .sa-btn-secondary:hover { background: #f9fafb; }
    .sa-btn-danger { background: #dc2626; }
    .sa-btn-danger:hover { background: #ef4444; }
    .sa-link { font-size: .875rem; color: #4b5563; text-decoration: underline; background: none; border: 0; padding: 0; cursor: pointer; font-family: inherit; border-radius: .375rem; }
    .sa-link:hover { color: #111827; }
    .sa-list { list-style: none; margin: 1rem 0 0; padding: 0; }
    .sa-list > li { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .75rem 0; border-top: 1px solid #e5e7eb; }
    .sa-list > li:first-child { border-top: 0; }
    .sa-option { display: block; padding: 1rem; margin-top: .75rem; border: 1px solid #e5e7eb; border-radius: .375rem; text-decoration: none; }
    .sa-option:hover { border-color: #6366f1; background: #f9fafb; }
    .sa-option strong { display: block; font-weight: 600; color: #111827; }
    .sa-badge { display: inline-block; padding: 0 .5rem; margin-left: .5rem; font-size: .75rem; border-radius: 9999px; background: #f3f4f6; color: #4b5563; }
    .sa-badge-warning { background: #fef3c7; color: #92400e; }
    .sa-qr { display: inline-block; padding: .5rem; margin-top: 1rem; background: #fff; }
    .sa-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .875rem; word-break: break-all; }
    .sa-inline { display: inline; }
    .sa-module-grid { display: grid; gap: 1rem; max-width: none; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); }
    .sa-module-grid .sa-option { margin-top: 0; }
    .sa-actions { flex-wrap: wrap; }
    .sa-record-value { white-space: pre-wrap; overflow-wrap: anywhere; }
    @media (min-width: 640px) {
        .sa-guest { justify-content: center; padding-top: 0; }
        .sa-card { max-width: 28rem; border-radius: .5rem; }
        .sa-section { padding: 2rem; border-radius: .5rem; }
        .sa-nav-inner, .sa-container { padding: 0 1.5rem; }
    }
    @media (min-width: 1024px) { .sa-nav-inner, .sa-container { padding: 0 2rem; } }
</style>
