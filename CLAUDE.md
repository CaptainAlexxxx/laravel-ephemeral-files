# Project context for Claude Code

Laravel app that stores PDF/DOCX uploads with a 24h retention window and emails a deletion notice via RabbitMQ. Stack: Laravel 13, PHP 8.4, MySQL, RabbitMQ, Bootstrap 5 + jQuery, Docker Compose.

Requirements, acceptance criteria, pitfalls and decisions: `docs/TASK.md`. Read it before planning or implementing anything.

## Rules

- Follow Laravel conventions: FormRequest for validation, thin controllers, business logic in services.
- Manual delete (controller) and automatic delete (purge command) must both go through `FileDeletionService::delete()`. Never duplicate deletion logic.
- `FileDeletionService::delete()` never calls the broker: it inserts a `deletion_notices` outbox row in the delete transaction. `notices:publish` (scheduled every minute) pushes unpublished rows to the `rabbitmq` connection and sets `published_at`. Feature tests must fake notifications: there is no broker in CI.
- No feature, route, or config key outside what `docs/TASK.md` lists. If something looks missing from the task, ask before adding it.
- No speculative abstractions: no repository classes, no interfaces with a single implementation, no config options nobody asked for.
- Every change states which files were touched and the exact command used to verify it (test, pint, artisan command).
- Work only on the current approved step. Do not edit files outside that step's scope.

## Commands

- Start the stack: `docker compose up -d`
- Run tests: `docker compose exec app php artisan test`
- Lint: `docker compose exec app ./vendor/bin/pint`
- Run the purge command manually: `docker compose exec app php artisan files:purge-expired`

## Agents

Subagent roles (implementer, qa-tester, reviewer) are defined in `.claude/agents/`.
