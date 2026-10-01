# How I used AI on this project

I built this with Claude Code. This file is the log: which prompts I used, why they are shaped the way they are, and what I changed after reading the output. Prompts were written in Russian during the work; here they are translated to English with light editing, the intent is unchanged.

## Setup

I work as the "conductor". I write the requirements, approve the plan, read every diff and decide what gets merged. Agents get narrow roles with only the tools that role needs. The rule I care about most: the agent that writes code does not verify it.

Agent definitions are committed in [`.claude/agents/`](.claude/agents/):

| Agent | Role | Tools | Model |
|---|---|---|---|
| [implementer](.claude/agents/implementer.md) | Builds one approved plan step, runs Pint and the tests | Read, Edit, Write, Bash, Grep, Glob | Sonnet |
| [qa-tester](.claude/agents/qa-tester.md) | Black-box testing from `docs/TASK.md`: writes feature tests, smoke-tests the running stack with curl, checks Mailpit. Never edits app code | Read, Write, Bash, Grep, Glob | Sonnet |
| [reviewer](.claude/agents/reviewer.md) | Reviews the diff against the requirements and a security checklist. No edit tools at all | Read, Grep, Glob, Bash | Opus |

The loop for every step:

1. Plan in plan mode, no code. I approve it.
2. implementer builds one step, one commit.
3. qa-tester and reviewer run in parallel on the same commit and do not see each other's output.
4. I sort the findings: accept, or reject with a reason.
5. Each accepted finding is fixed in its own commit, then QA runs again.

Context files every agent reads first:

- [`docs/TASK.md`](docs/TASK.md): requirements with IDs, acceptance criteria, known pitfalls, decisions. The requirements are in my own words, the original task text is not in the repo.
- [`CLAUDE.md`](CLAUDE.md): project rules. Two of them target mistakes I keep seeing in generated code: duplicated deletion logic, and abstractions nobody asked for (repositories, single-implementation interfaces).

## Prompt log

### 1. Analysis before the repo existed

```
Here is the task. Before any code: propose an architecture and give the
trade-off for every choice that has an alternative, list what a reviewer
will look for, split the work into steps, and draft the prompts for each
step.
```

Why: design mistakes are cheap to fix while there is no code. Asking for trade-offs makes the model justify a choice instead of picking the first one that works.

What I changed after the answer: I asked for the agent roles to be committed as files, so the process is visible in the repo. I also decided against putting the original task text in a public repo, so `docs/TASK.md` is a paraphrased checklist.

### 2. Bootstrap

```
Study the task document. Write docs/TASK.md and bootstrap a Laravel
project on the agreed stack. Think about the pitfalls up front. Create
the agents I described and keep a log of the prompts.
```

Why: one step with a clear boundary, no business logic yet. Infrastructure and documentation went to two agents in parallel, each with its own list of files it may touch.

What I changed:

- The plan from step 1 said Laravel 12 and PHP 8.3. I checked Packagist before scaffolding: Laravel 13 is current and the RabbitMQ driver v15 supports it, so the project is on Laravel 13 and PHP 8.4.
- The Laravel 13 skeleton ships `CLAUDE.md` and `AGENTS.md` from Laravel Boost that tell any agent to install Boost tooling first. Not wanted here: I replaced `CLAUDE.md` with project context and deleted `AGENTS.md`.
- The docs agent stated that the RabbitMQ driver v15 requires Laravel 13. The package supports Laravel 10 through 13. Fixed in `docs/TASK.md`.
- The infrastructure agent left a test script in the container (`/tmp/dispatch_test.php`) and added a `.dockerignore` that had no effect, because the build context is `docker/php`. Both removed.

### 3. Plan, and the part where the model was wrong

```
Plan mode, no code. Design the DB schema, the classes and what each one
owns, the flow for upload, manual delete and scheduled purge, the compose
services, and which test covers which requirement ID. For every choice
with an alternative (scheduler vs delayed job, queue package vs raw AMQP)
give the trade-off. List the risks and what you need me to confirm.
```

Why: I approve the shape of the solution before any file changes, and every test is tied to a requirement ID, so gaps in coverage are visible in the plan already.

Result: [`docs/PLAN.md`](docs/PLAN.md). Two things I did not accept as given.

**When to publish the notification.** Both the analysis in step 1 and the first plan draft said: delete the row, then dispatch the notification with `afterCommit()`. That is the textbook answer for "do not send a notice for a rolled back change", and the model gave it with full confidence. It is wrong for this task. If RabbitMQ is down at that moment, the row is already deleted and committed, the publish throws, and the notice is gone. The file was deleted and nobody was told, which is the one thing the task explicitly asks to avoid. I asked for the failure modes of each option instead of the pattern name, and changed the design: the conditional `DELETE` and the publish run inside one DB transaction, the file on disk is removed after commit. A broker outage now rolls the delete back, the user gets an error and the purge retries a minute later. The leftover risk, publish succeeded and then the commit failed, gives a duplicate email at worst, never a lost one. I think this is the most important correction in the project: the model optimised for a well-known rule and did not check it against what the task actually requires.

**"The stack works."** The infrastructure agent reported all checks green on my Windows machine. I cloned the repo onto a Linux filesystem (WSL) and ran exactly what the README will say. Two failures:

- MySQL initialised for over 100 seconds on a cold volume and the healthcheck gave up first, so nothing started. The check also pinged the socket, which answers while MySQL's temporary init server is running.
- Every page returned 500: the bind mount belongs to the host user and php-fpm runs as `www-data`, so compiled views could not be written. Docker Desktop on Windows hides this.

Both are in step 0 of the plan. A reviewer on Linux or macOS would have seen a broken app on the first request, and the agent had no way to notice it from the machine it ran on.

### 4. Implementation, one step per prompt

```
Implement step N of docs/PLAN.md: <step>. Only this step, only these
files: <list>. Do not commit. When done, verify on the running stack and
report the files you touched, the command you used to check it, and
anything left open.
```

Why: a small diff I can read in full, and the agent has to prove the step works on the live stack, not just say so. For the deletion core I used Opus instead of Sonnet and spelled out the transaction order in the prompt, because that step is where a plausible-looking mistake costs the most.

What came out of it:

- The deletion step looked done: tests green, row and file gone. The agent then sent a real email through RabbitMQ as the prompt required and the worker failed with "The intl PHP extension is required". `Number::fileSize()` needs `intl` and the image did not have it. Every deletion notice in production would have failed while the test suite stayed green, because tests fake notifications. Fixed in the Dockerfile in its own commit.
- I ran the purge step and the upload step in parallel to save time. They share one database, and the purge agent's cleanup deleted rows the upload agent had just created. Nothing broke in the code, but the upload agent saw rows vanish and had to work out why. Parallel agents need separate data, not only separate files.
- The upload page JS was never run in a browser by the agent, it said so honestly. I drove it myself in a browser: upload with progress, a text file renamed to `.pdf`, delete from the list, email in Mailpit.

### 5. Independent verification

```
Run the qa-tester and reviewer agents in parallel on the current commit.
Do not fix anything. Merge their findings into one list, deduplicate,
mark each as bug / risk / style.
```

qa-tester works from `docs/TASK.md` and may read code only to find routes and field names. reviewer has no edit tools at all.

What came out of it:

- QA's first run had six failing content-type tests: a text file named `.pdf` was accepted. The app was fine. `UploadedFile::fake()->createWithContent()` in Laravel guesses the mime type from the file name, not from the bytes, so the test never sent what it claimed to send. QA traced it to the framework instead of loosening the assertions, which is exactly what its prompt forbids. Fixtures are now real temp files. The reviewer, running at the same time, flagged the same tests independently.
- The reviewer confirmed the transactional publish by reading the framework and driver code (no deferral path, InnoDB row lock semantics), then found what I had not: the scheduler lock lives 24 hours by default, so a scheduler killed mid-run would stop the purge for a day without any error.
- Checking the reviewer's DOCX finding, I generated DOCX files with different zip layouts in the container. libmagic reads only the start of the file, so a valid DOCX with large `docProps/` entries before `word/` is reported as plain zip and was rejected. Type detection now checks the zip structure in that case.
- Second and third review passes ran on the fixes only. The second pass found that the markdown escaping fix leaked backslashes into the plain-text email, and that there was no test for the rollback the whole notification design depends on. The third pass found that the new rollback test was too weak: it mocked the Notification facade, so a regression to after-commit publishing would still pass. A test that cannot fail is worse than no test, because it looks like coverage. It now breaks the real queue push, and I made it go red by enabling `after_commit` before accepting it.
- The third pass found no issues in application code. That is where I stopped the review loop.

## Agent findings

Filled in as qa-tester and reviewer report. Rejected findings stay in the table with the reason.

| Finding | Agent | Decision | Reason |
|---|---|---|---|
| `withoutOverlapping()` lock lives 24h, a killed scheduler stops the purge for a day | reviewer | accepted | Silent failure of R6. Lock now expires after 5 minutes |
| No `pcntl` in the image: `queue:work --timeout` and graceful stop do not work | reviewer | accepted | A hung SMTP call would block the worker forever |
| App healthcheck window too short for a cold `composer install` | reviewer | accepted | Same class of bug as the MySQL one from the clean-clone test |
| RabbitMQ UI, Mailpit and app published on all interfaces | reviewer | accepted | guest/guest on the LAN. Bound to 127.0.0.1 |
| `after_commit` for rabbitmq relied on the driver default | reviewer | accepted | The design depends on it, so it is pinned in config |
| File name rendered as markdown in the email, phishing link possible | reviewer | accepted | Escaped as HTML entities, plain-text part checked too |
| Invalid UTF-8 file name gives 500 | reviewer | accepted | Now a 422 |
| Valid DOCX can be detected as plain zip | reviewer | accepted | Structure check, see entry 5 |
| `expires_at` and `created_at` read the clock twice | reviewer | accepted | Broke AC R4 by a second, one clock read now |
| Expired rows shown as "1 minute ago", 419 shown as a generic error, empty last page | reviewer | accepted | UI fixes |
| `composer setup` still calls npm, PHPUnit points at an empty `tests/Unit` | reviewer | accepted | Skeleton leftovers |
| `UploadedFile::fake()` made content-type tests meaningless | qa-tester | accepted | Real fixtures |
| No rollback test, R12 untested, temp fixtures left in `/tmp` | reviewer | accepted | Tests added |
| Rollback test mocked the Notification facade, so deferring the publish with `after_commit` would still pass | reviewer | accepted | The test now breaks the real queue push. Checked red with `after_commit => true` and with `afterCommit()`, green without |
| R12 test only checked Laravel routes, nginx serves `public/` directly | reviewer | accepted | Asserts the disk root is outside `public/` and not served |
| README said the scheduler re-reads `.env` every minute | reviewer | accepted | Wrong: `schedule:work` passes its own environment to children. The TTL is read by php-fpm at upload, so the instruction still worked, the explanation was false |
| `APP_DEBUG=true` in `.env.example` leaks stack traces | reviewer | rejected | Local development stack, the reviewer of this task benefits from real errors. Documented in README |
| A crash between commit and disk delete leaves an orphan file | reviewer | rejected | Real but needs a crash at a precise moment. A cleanup job is extra scope; documented as a known limit |
| `UID=1000` breaks hosts with another user id | reviewer | rejected | Auto-detecting it needs root in the entrypoint again. Documented in README |
| Test DOCX from LibreOffice and Google Docs | reviewer | changed | Could not get those exports here, so I tested the zip layouts instead, which found the real bug above |

## Rules I hold the output to

- I read every diff before it is committed.
- No commit with failing tests or Pint errors.
- Any claim like "it works" is checked by running the command myself.
- Findings are fixed in separate commits that name the finding.
