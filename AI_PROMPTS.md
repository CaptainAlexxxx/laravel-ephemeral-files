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

## Agent findings

Filled in as qa-tester and reviewer report. Rejected findings stay in the table with the reason.

| Finding | Agent | Decision | Reason |
|---|---|---|---|

## Rules I hold the output to

- I read every diff before it is committed.
- No commit with failing tests or Pint errors.
- Any claim like "it works" is checked by running the command myself.
- Findings are fixed in separate commits that name the finding.
