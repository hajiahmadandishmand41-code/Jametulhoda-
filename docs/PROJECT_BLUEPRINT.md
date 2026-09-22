# Jametulhoda — Project Blueprint

## Goal
Build a clean, modular raw-PHP Persian RTL educational/Islamic content website in controlled phases.

## Architecture
- Raw PHP 8.4/8.5; no framework.
- MySQL/MariaDB through PDO.
- Shared-hosting/InfinityFree compatible.
- Front-controller routing with a small explicit route table.
- Keep public pages in pages/, reusable logic in app/, configuration in config/, admin in admin/, database assets in database/, tests in tests/.
- No unnecessary rewrites or migrations.

## Main public content
- Articles
- News
- Events
- Reports (supports multiple images)
- Contextual related audio/video attached to parent content
- Search, topics/categories, related content

## Admin
- Dashboard
- CRUD for each main content type
- Media management and safe uploads
- Publish/draft state
- Users/authentication/authorization
- Settings

## Development phases
1. Project skeleton, router, config, PDO base, layout, homepage, 404.
2. Database schema and data layer.
3. Authentication and authorization.
4. Admin foundation/dashboard.
5. Articles CRUD.
6. News and events CRUD.
7. Reports and multi-image support.
8. Media and content relationships.
9. Public content UI.
10. Search/topics/related content.
11. Upload/security hardening.
12. SEO/sitemap/robots/schema.
13. Full security review.
14. Automated/browser/mobile tests.
15. InfinityFree deployment preparation.
16. Production verification and v1.0 release.

## Phase gate
A phase is complete only when its syntax, route, HTTP, database, and relevant browser tests pass. Do not start the next phase until the current phase is green.

## Rules
- Do not build future-phase features early.
- Do not invent placeholder links or fake content.
- Preserve working architecture unless a documented reason requires change.
- Before destructive schema changes, document the migration plan.
- After each phase, report changed files, tests, failures, and readiness for the next phase.
