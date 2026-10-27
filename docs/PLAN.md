# Implementation plan

Requirements: [TASK.md](TASK.md). One step = one commit. The implementer agent builds a step, qa-tester and reviewer verify it independently after steps 2-4.

## Step 0. Foundation fixes

Found by running a clean clone on a Linux filesystem (WSL ext4), the way a reviewer would.

- MySQL cold start: first init took over 100 s and the healthcheck gave up, so `app` never started. Also `mysqladmin ping` over the socket reports "alive" while the temporary init server is running. Fix: ping over TCP `-h 127.0.0.1` (init server has networking off), `start_period: 60s`, 30 retries.
- Linux permissions: the bind mount is owned by the host user (uid 1000), php-fpm workers run as `www-data` (uid 33), compiled views cannot be written, every page returns 500. Fix: the image remaps `www-data` to the host UID (build arg `UID`, default 1000) and every PHP service runs as that user. Files created by composer, artisan or the app belong to the host user, nothing needs `chown`, and `docker compose exec app ...` runs as the right user by default. On Docker Desktop the UID does not matter.
- Compose falls back to defaults (`${VAR:-default}`) so it does not depend on `.env` for interpolation. Host port is `APP_PORT`, default 8080.
- Verify: fresh clone, `cp .env.example .env && docker compose up -d`, HTTP 200.

## Step 1. Data layer

- `config/files.php`: `ttl_minutes` (FILE_TTL_MINUTES, default 1440), `notify_email` (NOTIFY_EMAIL), `max_size_kb` = 10240 (no env: it has to stay below the 12M nginx/php limits), `disk` = `local`.
- `config/filesystems.php`: local disk `serve => false`. Nothing needs the signed `/storage` route.
- Migration `stored_files`: `id`, `original_name` varchar(255), `path` varchar(255) unique, `mime_type` varchar(127), `size` unsigned bigint, `expires_at` timestamp indexed, timestamps. Hard delete, all times in UTC.
- Model `StoredFile` with explicit `$fillable` and casts. Enum `DeletionReason: string` (`manual`, `expired`). Factory.

## Step 2. Deletion and notification

`FileDeletionService::delete(StoredFile $file, DeletionReason $reason): bool` is the only place that deletes a file.

```
DB::transaction:
    affected = DELETE FROM stored_files WHERE id = ?     -- row lock until commit
    affected == 0 -> return false                         -- someone else deleted it, no notice row
    INSERT INTO deletion_notices (...)                    -- outbox row, published_at NULL
commit
Storage::delete(path)  -- after commit; failure is logged, the row is already gone
return true
```

```
notices:publish (every minute, withoutOverlapping(5)):
    for each unpublished notice (chunkById):
        DB::transaction:
            SELECT ... WHERE id = ? AND published_at IS NULL FOR UPDATE SKIP LOCKED
            none -> skip                                  -- another run has it, or it is done
            push FileDeletedNotification to RabbitMQ      -- failure throws, row stays unpublished
            UPDATE published_at = now()
```

Why an outbox instead of publishing inside the delete transaction (the first version of this plan):
- Publishing inside the transaction is a dual write. If the push succeeds and COMMIT then fails, an email goes out for a file that was not deleted. The broker call also runs while the row lock is held.
- Publishing after commit (`afterCommit()`) loses the notice if the broker is down: the row is already deleted.
- With the outbox the notice row commits or rolls back together with the delete, so there is no deletion without a notice and no notice without a deletion. No network call happens under the `stored_files` row lock (the publisher does push under the lock of its own outbox row, which nothing else waits on). A broker outage only delays the email: rows stay unpublished and the next run sends them.
- Delivery is at-least-once. If the process dies after the push but before the `published_at` update commits, the notice is sent again on the next run. A duplicate email is accepted over a missing one. The driver does not use publisher confirms, so loss in flight after the broker accepted a message is still theoretically possible. A job that keeps failing in the worker lands in `failed_jobs` and can be retried.
- Two publisher runs at once do not double-send: `FOR UPDATE SKIP LOCKED` makes the second run pass over a row the first one holds, and the `published_at IS NULL` recheck skips rows already finished.
- The queue connection keeps `after_commit => false`, so the push happens immediately inside the claim transaction.
- A second deleter racing on the same stored file blocks on the row lock, then sees 0 affected rows and inserts no notice. Exactly one notice row.

`FileDeletedNotification implements ShouldQueue`:
- Gets a scalar snapshot (name, size, reason, uploaded at, deleted at), never the model. `SerializesModels` would re-fetch the deleted row in the worker and fail with `ModelNotFoundException`.
- `onConnection('rabbitmq')` set explicitly, so the requirement does not depend on `QUEUE_CONNECTION`.
- Mail channel, on-demand route to `config('files.notify_email')`, built by `notices:publish` from the outbox row. Empty address: the service throws before deleting anything, a misconfiguration must not silently eat notifications.
- Feature tests always fake notifications: there is no broker in CI, and an unfaked test would try to connect to RabbitMQ.
- Queue is durable and messages are persistent (driver defaults, checked in vendor), so a RabbitMQ restart does not drop pending notices.

## Step 3. Purge

- `files:purge-expired`: `StoredFile::where('expires_at', '<=', now())->chunkById(100, ...)`, each file through the service with reason `expired`.
  - `chunkById`, not `chunk`: deleting inside `chunk()` shifts the offset and skips rows.
  - `<=`: a file exactly at its TTL counts as expired.
  - The first failure stops the run and the command exits non-zero. The only failures that can reach the command are DB errors or a missing `NOTIFY_EMAIL`, both systemic; the delete never calls the broker. The next run, a minute later, picks the rest up.
- Schedule: `everyMinute()->withoutOverlapping(5)` in `routes/console.php`. Overlaps would be safe anyway because of the conditional delete, the flag just avoids wasted work.
- A file is removed between TTL and TTL + 1 minute.

## Step 3b. Outbox (revision after submission)

- Migration and model `deletion_notices`: `original_name`, `size`, `reason`, `uploaded_at`, `deleted_at`, `published_at` (nullable, indexed), timestamps. Plain values, same as the notification.
- `FileDeletionService` inserts the row in the delete transaction and no longer touches the broker.
- `notices:publish` (see Step 2) sends unpublished rows. Scheduled every minute next to the purge, so a notice reaches the queue at most about a minute after the delete.
- Tests: broker down (delete commits, notice stays unpublished, a later run sends it), publish marks the row, a second run sends nothing, and a MySQL-only test where a real second process races the delete while the first holds the row lock.

## Step 4. Upload

- `StoreFileRequest`: `required|file|max:{max_size_kb}|extensions:pdf,docx|mimes:pdf,docx`, plus an `after()` check that the extension guessed from content equals the client extension. Without it a PDF renamed to `.docx` passes both rules. Original name longer than 255 characters is rejected instead of failing on insert.
- Checked a real Word file inside the container: libmagic reports `wordprocessingml.document` and the guessed extension is `docx`, so generic `application/zip` stays rejected.
- Changed after review: `mimes` was replaced by `App\Support\FileType`. libmagic reads only the first few KB, so a valid DOCX with large `docProps/` entries before `word/` was reported as plain zip and rejected. When libmagic says zip, the zip's central directory is checked for `[Content_Types].xml` and `word/document.xml`. Request and upload service use the same detector.
- `FileUploadService::store()`: stores as `files/{uuid}.{ext}`, extension and mime taken from content, never from the client. If the insert fails, the stored file is removed.
- `FileController`: `create` (upload page), `index` (management page, 20 per page, newest first), `store` (JSON 201), `destroy` (route model binding, service returns false -> 404 JSON).
- Routes: `GET /`, `GET /files`, `POST /files`, `DELETE /files/{storedFile}`. Upload and management are separate pages, as the task asks.

## Step 5. Frontend

- Layout with Bootstrap 5 and jQuery from jsDelivr with SRI hashes, CSRF meta tag and `$.ajaxSetup`.
- Upload page: `FormData` upload with a progress bar, client-side precheck of size and extension, separate handling for 422 (validation), 413 (anything over 12M is cut by nginx before Laravel) and 5xx. All messages are inserted with `.text()`.
- Files page: name, size, uploaded, "expires in" (absolute time in the tooltip), delete with confirmation, row removed on 200 and on 404.
- Remove the unused Vite skeleton: `welcome.blade.php`, `vite.config.js`, `package.json`, `.npmrc`, `resources/css`, `resources/js`.

## Step 6. Verification

qa-tester and reviewer run in parallel on the same commit. I triage findings, fixes go in separate commits.

## Step 7. Delivery

- README: one-line description, quick start, links (app, RabbitMQ UI, Mailpit), how to verify (upload, manual delete, email in Mailpit, `FILE_TTL_MINUTES=1` for auto-delete, stop the worker to see the message waiting in RabbitMQ), architecture, decisions, tests, known limits (no auth).
- GitHub Actions: tests on PHP 8.4 against a `mysql:8.4` service (the row lock race test needs it), plus `pint --test`.
- Final clean-clone check on Linux.
