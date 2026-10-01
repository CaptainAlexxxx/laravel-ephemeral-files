# Requirements

Source of truth for implementation, tests and review. Requirement IDs are stable: new ones are appended, existing ones are never renumbered.

## Requirements

- [ ] R1: A user uploads a PDF or DOCX file from a web page asynchronously (AJAX, no page reload).
- [ ] R2: Files above 10 MB are rejected.
- [ ] R3: A file whose content is not PDF or DOCX is rejected, whatever its extension.
- [ ] R4: Every uploaded file has a MySQL row: original name, stored path, mime type, size, upload time, expiry time.
- [ ] R5: A separate management page lists stored files and lets the user delete any of them manually.
- [ ] R6: A file is deleted automatically 24 hours after upload (TTL configurable via `.env`).
- [ ] R7: Any deletion removes both the DB row and the file on disk.
- [ ] R8: Every deletion, manual or automatic, publishes a message to RabbitMQ; a worker consumes it and sends an email to the address from `.env`.
- [ ] R9: One deletion produces exactly one notification, also when a manual delete and the purge race on the same file.
- [ ] R10: `cp .env.example .env && docker compose up -d` starts the whole stack with no other steps.
- [ ] R11: `README.md` is enough for someone with only Docker installed to run and check the app.
- [ ] R12: Stored files are not reachable over HTTP and user input never becomes part of a filesystem path.

## Acceptance criteria

- R1: Uploading a valid file shows a progress bar and a success message, the page does not reload.
- R2: A file of exactly 10240 KB is accepted. A file of 10240 KB + 1 byte gets HTTP 422 with a JSON error. An 11 MB file also gets 422, not a 413 from nginx.
- R3: A text file renamed to `.pdf`, and a PDF renamed to `.docx`, both get 422.
- R4: After an upload the `stored_files` row holds the original name, detected mime type, size and `expires_at = created_at + TTL`.
- R5: The management page lists files with "expires in", deleting a row removes it from the list without a reload. Deleting an already deleted file returns 404.
- R6: A file past its `expires_at` is gone after the next scheduler run (at most one minute later).
- R7: After either deletion path neither the row nor the file on disk exists.
- R8: With the worker stopped, a deletion leaves a message in the RabbitMQ queue. With the worker running, the email shows up in Mailpit, addressed to `NOTIFY_EMAIL`.
- R9: A manual delete and a purge run on the same expired file produce one notification.
- R10: On a clean clone, on Linux as well as Docker Desktop, the command above gives a working app on `http://localhost:8080`.
- R11: A person new to the project can upload, list, delete, and see the email using only the README.
- R12: No public URL serves stored files. Stored names are generated UUIDs.

## Pitfalls

- Two deletion code paths, one in the controller and one in the command: they drift apart. One service is used by both.
- RabbitMQ replaced by the `database`, `redis` or `sync` driver because it is easier to run. The notification sets the `rabbitmq` connection explicitly.
- Size limit only in Laravel: nginx answers 413, or PHP drops the body when `post_max_size` is exceeded and `$_FILES` arrives empty. nginx and PHP allow 12M, Laravel cuts at 10M and returns JSON.
- Type checked by extension only. Content is sniffed, and the detected type must also match the extension.
- DOCX is a ZIP container and libmagic reports `application/zip` when large `docProps/` entries come before `word/`. On `application/zip` the archive must contain `[Content_Types].xml` and `word/document.xml` to count as DOCX, any other ZIP is rejected.
- Notification lost or duplicated: published after commit and the broker is down, or published twice when manual delete and purge race. Covered by publishing inside the delete transaction and a conditional delete, see Decisions.
- Queued notification holding an Eloquent model: the worker re-fetches the already deleted row and fails. The notification carries plain values.
- Physical file deletion failing silently. Failure is logged with the file id and path.
- Deleting rows inside `chunk()` skips records because the offset shifts. `chunkById()` is used.
- Scheduler or worker not running in Docker. Both are separate services.
- Bind mount permissions on Linux hosts: php-fpm cannot write `storage` and every page returns 500. Containers run as a user with the host UID.
- CRLF in shell scripts written on Windows. `.gitattributes` forces LF.
- Original file name rendered as HTML (XSS). Blade escaping, and `.text()` in jQuery.
- 24 hours cannot be checked in a review. `FILE_TTL_MINUTES` makes it configurable.

## Decisions

| Decision | Rejected alternative | Reason |
|---|---|---|
| Laravel 13, PHP 8.4 | Older LTS releases | Current versions, supported by the RabbitMQ driver. |
| RabbitMQ as a Laravel queue connection (`vladimir-yuldashev/laravel-queue-rabbitmq`) | Hand-written `php-amqplib` publisher and consumer | Retries, failed jobs and queued notifications come with the framework. |
| `expires_at` column, `files:purge-expired` every minute | A job delayed by 24 hours at upload | A delayed job disappears with a purged queue and is hard to test. A sweep is idempotent and testable with `travel()`. |
| One `FileDeletionService` for both paths | Delete logic in the controller and in the command | One place for the delete, the notification and the race handling. |
| Conditional `DELETE ... WHERE id = ?`, notify only when one row was affected | Always notify after delete | Losers of a race see 0 rows and stay silent. No extra locks. |
| Publish to RabbitMQ inside the delete transaction | Publish after commit (`afterCommit()`) | If the broker is down, after-commit publishing loses the notice for a deletion that already happened. Inside the transaction the delete rolls back and is retried. |
| Private disk, UUID file names | Public disk, original names | No path traversal, no direct links, no name collisions. |

## Out of scope

- Authentication and per-user files.
- Download or preview of stored files.
- Editing file metadata.
- Remote storage (S3).
