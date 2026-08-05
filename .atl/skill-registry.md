# Skill Registry

**Delegator use only.** Any agent that launches sub-agents reads this registry to resolve compact rules, then injects them directly into sub-agent prompts. Sub-agents do NOT read this registry or individual SKILL.md files.

See `_shared/skill-resolver.md` for the full resolution protocol.

## User Skills

| Trigger | Skill | Path |
|---------|-------|------|
| Agentic flows with directivas, self-correction protocol, and memory learning | agentic_determinista | C:\Users\innov\.config\opencode\skills\agentic_determinista\SKILL.md |
| Agentic flows for production: staging/prod, security, logs, tests, memory | agentic_fullstack | C:\Users\innov\.config\opencode\skills\agentic_fullstack\SKILL.md |
| When creating a pull request, opening a PR, or preparing changes for review | branch-pr | C:\Users\innov\.config\opencode\skills\branch-pr\SKILL.md |
| When writing Go tests, using teatest, or adding test coverage | go-testing | C:\Users\innov\.config\opencode\skills\go-testing\SKILL.md |
| When creating a GitHub issue, reporting a bug, or requesting a feature | issue-creation | C:\Users\innov\.config\opencode\skills\issue-creation\SKILL.md |
| When user says "new change", "sdd new", "new feature", or starts describing a non-trivial requirement (intake + ADD) | architectural-intake | C:\Users\innov\.config\opencode\skills\architectural-intake\SKILL.md |
| When user says "judgment day", "judgment-day", "review adversarial", "dual review", "doble review", "juzgar", "que lo juzguen" | judgment-day | C:\Users\innov\.config\opencode\skills\judgment-day\SKILL.md |
| When user asks to create a new skill, add agent instructions, or document patterns for AI | skill-creator | C:\Users\innov\.config\opencode\skills\skill-creator\SKILL.md |
| WordPress projects using Genesis Framework and FluentSuite | wordpress-starterkit | C:\Users\innov\.config\opencode\skills\wordpress-starterkit\SKILL.md |
| WordPress projects using FluentSuite (FluentForms, FluentCRM, FluentCart) | wordpress-fluent-suite | C:\Users\innov\.config\opencode\skills\wordpress-fluent-suite\SKILL.md |

## Compact Rules

### agentic_determinista
- Create deterministic agentic flows with Directivas (SOPs) and executable scripts.
- Always write a Directiva before creating any script.
- Self-correction protocol: diagnose → patch code → patch Directiva (Historial de Aprendizaje) → verify.
- Never hardcode credentials or tokens; always use `.env`.
- Never close a session without updating `memory.md`.

### agentic_fullstack
- Extend SDD for production with staging/prod environments.
- Use separate `.env`, `.env.staging`, `.env.prod` files; never mix credentials across environments.
- Structured JSON logs in `.logs/`; never log credentials, tokens, or PII.
- Security barriers: sanitize all input, rate limit requests, validate auth tokens, filter outputs.
- No deploy without passing tests, security review, and validated environment config.

### branch-pr
- Every PR MUST link an approved issue (`status:approved` label).
- Branch naming: `type/description` (feat/, fix/, chore/, docs/, refactor/, perf/, test/, build/, ci/, revert/).\n- Conventional commits: `type(scope): description` or `type: description`.
- PR body must contain linked issue, exactly one `type:*` label, summary, changes table, and test plan.
- Run automated checks (e.g., shellcheck) before merging.

### go-testing
- Use table-driven tests for pure functions and multiple test cases.
- Test Bubbletea TUI state transitions directly via `Model.Update()`.
- Use `teatest.NewTestModel()` for full interactive flow integration tests.
- Use golden file testing for visual output comparisons.
- Mock system dependencies for controlled test environments; use `t.TempDir()` for file operations.

### issue-creation
- Blank issues are disabled; MUST use bug report or feature request template.
- Every new issue gets `status:needs-review` automatically.
- A maintainer MUST add `status:approved` before any PR can be opened.
- Questions go to Discussions, not issues.

### architectural-intake
- ALWAYS run before `sdd new <change>` or any code; produces ADD design doc first.
- Categorize drivers into R (constraints), PA (architectural concerns), PR (purpose), HU (user stories), AC (quality attributes with full QAW format: stimulus/source/artifact/environment/response/measure).
- Build Utility Tree; prioritize H-H drivers first, then H-M. Top 3 dictate architecture.
- Output: ADD doc at `Docs/design/ADD-<CHANGE-ID>-<slug>.md` with driver tracking table + 4+1 views + sprint plan.
- 4+1 mandatory views: Logical, Process, Deployment, Implementation, Data.
- Forward compat tactics: URL versioning (`/api/v1/`), OpenAPI spec checked-in, new fields always optional with default.
- Use `assets/ADD-skeleton.md` template and `assets/intake-checklist.md` for the 8-question intake.

### judgment-day
- Resolve skills (read registry) and build `## Project Standards (auto-resolved)` block BEFORE launching judges.
- Launch TWO independent blind judge sub-agents in parallel via `delegate`.
- Synthesize verdict: Confirmed (both), Suspect (one), Contradiction (disagree).
- Classify warnings as `real` (fix required) or `theoretical` (report as INFO, do not fix).
- Fix confirmed issues via separate Fix Agent, then re-judge.
- After 2 fix iterations, ask user before continuing; never auto-escalate.

### skill-creator
- Create skills for repeatable patterns, complex workflows, or project-specific conventions.
- Skill structure: `skills/{name}/SKILL.md` (required), `assets/` (optional), `references/` (optional).
- Frontmatter required: name, description (with Trigger), license, metadata.author, metadata.version.
- Keep compact rules to 5-15 lines; include minimal code examples and commands.
- Register new skills in `AGENTS.md`.

### wordpress-starterkit
- NEVER hardcode business logic in theme PHP files; use native FluentSuite plugins.
- Follow strict SDD: SPECs before code, autonumbering `NN_name_SPEC.md`.
- Use conditional asset loading (`wp_enqueue_scripts`) per template.
- Support classes: Evaluator (eval only), PDF_Generator (PDF only), Diagnostico (webhook only).
- Delete scratch files immediately; move tests to `tests/` folder.
- Maturity mapping: Low → Mini SaaS/Starter Kit, Medium → AppSheet, Medium-High → Academy, High → Custom PWA.

### wordpress-fluent-suite
- NEVER hardcode business logic in theme PHP files.
- Configure FluentForms, FluentCRM, FluentCart natively from admin panel.
- Use native webhooks instead of WordPress hooks for business logic.
- Standard funnel: Diagnosis → CRM Tags → Results → Service → Checkout → OTO.
- Use standard fonts (helvetica) in FPDF; never external fonts.
- Delete DB migration scripts immediately after execution.

## Project Conventions

| File | Path | Notes |
|------|------|-------|
| AGENTS.md | D:\sitios desarrollo\crm-laravel\AGENTS.md | Index — references files below |
| routes/api.php | D:\sitios desarrollo\crm-laravel\routes\api.php | Referenced by AGENTS.md |
| WebhookController | D:\sitios desarrollo\crm-laravel\app\Http\Controllers\API\WebhookController.php | Referenced by AGENTS.md |
| OrganizationService | D:\sitios desarrollo\crm-laravel\app\Models\OrganizationService.php | Referenced by AGENTS.md |
| TestDataSeeder | D:\sitios desarrollo\crm-laravel\database\seeders\TestDataSeeder.php | Referenced by AGENTS.md |
| PlanControllerTest | D:\sitios desarrollo\crm-laravel\tests\Feature\API\PlanControllerTest.php | Referenced by AGENTS.md |
| GenerateApiToken | D:\sitios desarrollo\crm-laravel\app\Console\Commands\GenerateApiToken.php | Referenced by AGENTS.md |
| OrganizationServiceController | D:\sitios desarrollo\crm-laravel\app\Http\Controllers\API\OrganizationServiceController.php | Referenced by AGENTS.md |
| app.css | D:\sitios desarrollo\crm-laravel\resources\css\app.css | Referenced by AGENTS.md |

Read the convention files listed above for project-specific patterns and rules. All referenced paths have been extracted — no need to read index files to discover more.
