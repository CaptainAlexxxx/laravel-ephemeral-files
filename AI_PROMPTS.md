# AI usage

I built this with Claude Code. Below are the prompts I used, why I wrote them that way, and what I changed after reading the output. The original prompts were in Russian, these are English translations.

## Setup

I plan, review every diff and decide what gets committed. The code is written by agents with narrow roles, and the agent that writes code is not the one that checks it. The roles are committed in [`.claude/agents/`](.claude/agents/):

| Agent | Does | Not allowed to |
|---|---|---|
| [implementer](.claude/agents/implementer.md) (Sonnet, Opus for the deletion core) | Builds one plan step, runs Pint and tests | Write tests for its own step |
| [qa-tester](.claude/agents/qa-tester.md) (Sonnet) | Writes feature tests from `docs/TASK.md`, smoke-tests the running stack with curl and Mailpit | Edit application code |
| [reviewer](.claude/agents/reviewer.md) (Opus) | Reviews the diff against the requirements and a security checklist | Edit anything |

All agents read [`docs/TASK.md`](docs/TASK.md) (requirements with IDs, pitfalls, decisions) and [`CLAUDE.md`](CLAUDE.md) (project rules) first. The requirements in `TASK.md` are my paraphrase, the original task text is not in the repo.

How it went: plan, implementation step by step with one commit per step, then QA and review in parallel on the finished code. I sorted the findings, accepted fixes went in as separate commits, and review ran again on the fixes. I stopped after the third review pass found nothing in the application code.

## Prompts

### Analysis, before the repo existed

```
Here is the task. Before any code: propose an architecture and give the
trade-off for every choice that has an alternative, list what a reviewer
will look for, split the work into steps, and draft the prompts for each
step.
```

Asking for trade-offs makes the model explain a choice instead of picking the first thing that works. After this I added the agent roles as files, and decided to keep the original task text out of the public repo.

### Bootstrap

```
Study the task document. Write docs/TASK.md and bootstrap a Laravel
project on the agreed stack. Think about the pitfalls up front. Create
the agents I described and keep a log of the prompts.
```

Infrastructure and docs went to two agents in parallel, each with a fixed list of files it could touch. What I changed:

- The plan said Laravel 12 and PHP 8.3. I checked Packagist: Laravel 13 is current and the RabbitMQ driver supports it, so I used Laravel 13 and PHP 8.4.
- The Laravel 13 skeleton ships Laravel Boost files (`CLAUDE.md`, `AGENTS.md`) that tell agents to install Boost first. I replaced `CLAUDE.md` with project rules and deleted `AGENTS.md`.
- The docs agent wrote that the RabbitMQ driver requires Laravel 13. It supports 10 to 13. Corrected.
- The infrastructure agent left a test script inside the container and added a `.dockerignore` that did nothing. Removed both.

### Plan

```
Plan mode, no code. Design the DB schema, the classes and what each one
owns, the flow for upload, manual delete and scheduled purge, the compose
services, and which test covers which requirement ID. For every choice
with an alternative (scheduler vs delayed job, queue package vs raw AMQP)
give the trade-off. List the risks and what you need me to confirm.
```

Result: [`docs/PLAN.md`](docs/PLAN.md). Two things I did not take as given.

The model proposed to delete the row and then dispatch the notification with `afterCommit()`. That is the usual way to avoid notifying about a rolled back change, but here it loses the notice when RabbitMQ is down: the row is already deleted, the publish fails, nobody is told. The task asks for a notice on every deletion, so I changed the order. The delete and the publish now run in one transaction and the file is removed from disk after commit. If the broker is down, the delete rolls back and the purge retries a minute later. The worst remaining case is a duplicate email, not a missing one. This was the most important correction in the project.

The infrastructure agent reported the stack as working on my Windows machine. I cloned the repo onto a Linux filesystem (WSL) and started it the way the README says. It failed twice: MySQL took over 100 seconds to initialise and the healthcheck gave up first, and every page returned 500 because php-fpm could not write to the mounted `storage` directory. Docker Desktop on Windows hides the second one. Both fixes are step 0 of the plan.

### Implementation

```
Implement step N of docs/PLAN.md: <step>. Only this step, only these
files: <list>. Do not commit. When done, verify on the running stack and
report the files you touched, the command you used to check it, and
anything left open.
```

A small diff I can read in full, and the agent has to check the result on the running stack. For the deletion service I used Opus and wrote the transaction order into the prompt.

- The deletion step passed its checks, then failed when the agent sent a real email: the image had no `intl` extension, which `Number::fileSize()` needs. Tests did not catch it because they fake notifications. Fixed in the Dockerfile.
- I ran the purge and upload steps in parallel. They shared the database and one agent's cleanup deleted the other's test rows. No harm to the code, but it cost time to figure out.
- The implementer could not run the upload page in a browser and said so. I checked it in a browser myself.

### Verification

```
Run the qa-tester and reviewer agents in parallel on the current commit.
Do not fix anything. Merge their findings into one list, deduplicate,
mark each as bug / risk / style.
```

- QA's first content-type tests failed on correct code. Laravel's `UploadedFile::fake()` takes the mime type from the file name, so a text file named `.pdf` really was a PDF as far as the test knew. QA found the cause in the framework instead of changing the expected results. The fixtures are now real files.
- The reviewer confirmed the transaction design by reading the framework and driver code, and found a scheduler lock that would block the purge for 24 hours after a crash.
- While checking a DOCX finding I built DOCX files with different internal layouts. A valid DOCX with large metadata at the start was detected as plain zip and rejected. Detection now checks the zip contents in that case.
- For the first round of fixes I let the implementer write the failing test before each fix, to save a round trip. The reviewer checked those tests in the next pass.
- On the third pass the reviewer showed that the new rollback test could not fail: it mocked the notification facade, so moving the publish after commit would still pass. The test now breaks the real queue push, and I confirmed it goes red when the publish is deferred.

## Findings and decisions

| Finding | From | Decision |
|---|---|---|
| Scheduler lock held 24h after a crash, purge silently stops | reviewer | Fixed, lock expires after 5 minutes |
| No `pcntl`, so `queue:work --timeout` and graceful stop don't work | reviewer | Fixed |
| App healthcheck too short for a cold `composer install` | reviewer | Fixed |
| RabbitMQ UI, Mailpit and app open on all interfaces | reviewer | Fixed, bound to 127.0.0.1 |
| `after_commit` for RabbitMQ relied on a driver default | reviewer | Fixed, set explicitly |
| File name rendered as markdown in the email, a link could be injected | reviewer | Fixed, plain-text part checked too |
| Invalid UTF-8 file name returned 500 | reviewer | Fixed, now 422 |
| Valid DOCX detected as zip | reviewer | Fixed |
| `expires_at` and `created_at` one second apart | reviewer | Fixed |
| Small UI issues: expired rows, stale session error, empty last page | reviewer | Fixed |
| Content-type tests meaningless with `UploadedFile::fake()` | qa-tester | Fixed |
| Rollback test could not fail, no-public-access test only checked routes | reviewer | Fixed |
| README explained the TTL reload wrongly | reviewer | Fixed |
| `APP_DEBUG=true` in `.env.example` | reviewer | Kept: local stack, real errors help whoever runs it |
| Orphan file if the process dies between commit and disk delete | reviewer | Kept, listed in README known limits |
| `UID=1000` default breaks hosts with another user id | reviewer | Kept, documented in README |
