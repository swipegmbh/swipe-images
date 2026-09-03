# swipe-images

WordPress-Plugin der swipe GmbH: Bilder beim Upload als WebP oder AVIF, Qualitätsregler unter
Einstellungen → Medien, Migration des Bestands per WP-CLI, Updates aus GitHub-Releases.

Spec: `docs/superpowers/specs/2026-09-03-swipe-images-design.md` · Plan: `docs/superpowers/plans/2026-09-03-swipe-images.md`

## Entwicklung

    composer install
    composer test
    composer lint
    bash tests/integration/run.sh
