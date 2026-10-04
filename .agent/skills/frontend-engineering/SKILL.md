---
name: frontend-engineering
version: 1.0.0
description: Apply the Frontend Engineering Standard to design, implement, review, refactor, and verify modern frontend applications. Use for non-trivial frontend architecture, UI/UX, state, API/data flow, forms, security, performance, accessibility, i18n, testing, and code review tasks.
---

# Frontend Engineering Skill

You are operating under a strict engineering standard. Your job is not merely to generate frontend code. Your job is to produce a coherent, maintainable, secure, accessible, performant, testable, and polished frontend system while preserving the existing project's valid conventions.

## Source of truth

Before a non-trivial frontend task, read:

`references/FRONTEND_ARCHITECTURE_STANDARD.md`

Treat it as the canonical engineering standard behind this skill. Do not duplicate the entire standard in this file. This file defines how to apply it; the reference defines the detailed rules.

## Operating mode

Follow this lifecycle unless the task is genuinely trivial:

```text
1. UNDERSTAND
2. INSPECT
3. MODEL
4. REUSE
5. PLAN
6. IMPLEMENT
7. SELF-REVIEW
8. VERIFY
9. VISUALLY REVIEW WHEN APPLICABLE
10. REPORT
```

## 1. Understand

Extract:

- requested behavior;
- affected screens/routes;
- entities and user actions involved;
- acceptance criteria;
- explicit constraints;
- non-functional requirements;
- risks and ambiguities.

Do not invent missing business requirements. Make the smallest reasonable assumption only when the task can proceed safely; otherwise surface the ambiguity.

## 2. Inspect

Inspect the repository before designing new structure.

Identify:

- framework and versions;
- existing FSD boundaries or equivalent architecture;
- design system and reusable components;
- routing;
- state/data-fetching conventions;
- API client conventions;
- forms and validation;
- i18n/RTL setup;
- testing tools and commands;
- lint/type/build configuration;
- relevant analogous features.

Existing valid patterns are the default. Do not create a parallel architecture without a concrete reason.

## 3. Model

Build an implementation model before coding:

- What entities exist?
- What user actions exist?
- What widgets or route-level compositions exist?
- What is shared?
- Who owns each piece of state?
- What are the API inputs/outputs?
- What are loading, empty, error, success, and partial states?
- What security boundaries apply?
- What accessibility, responsive, locale, and performance concerns apply?

## 4. Reuse

Search for an existing component, feature, entity, helper, service, query pattern, form pattern, or design-system primitive before creating a new one.

Prefer:

```text
reuse existing
→ extend existing
→ compose existing
→ extract shared abstraction when genuinely generic
→ create new abstraction only when justified
```

Never duplicate an established pattern simply because a local implementation is faster.

## 5. Plan

For non-trivial work, produce a concise implementation plan internally before editing.

The plan must identify:

- files/areas likely to change;
- ownership and FSD placement;
- data/state flow;
- reuse strategy;
- testing strategy;
- verification strategy;
- migration or compatibility concerns.

Avoid speculative architecture. Design only what the task and repository evidence justify.

## 6. Implement

Implement in dependency order and preserve the architecture standard:

```text
shared
→ entities
→ features
→ widgets
→ pages
→ app integration
```

Respect public APIs of slices. Do not bypass boundaries with deep imports.

Keep page components focused on composition. Keep business concepts in entities. Keep user actions in features. Keep truly generic primitives in shared.

## 7. Self-review

Before testing, inspect your own implementation for:

- wrong FSD layer;
- forbidden imports;
- duplicated state;
- business logic in UI components;
- unnecessary abstractions;
- duplicated components or API logic;
- unsafe rendering;
- missing error/empty/loading states;
- accessibility regressions;
- i18n/RTL regressions;
- unnecessary network requests;
- avoidable performance regressions;
- unnecessary scope expansion.

Fix discovered issues before claiming completion.

## 8. Verify

Run the narrowest relevant checks first, then broaden when needed.

Typical gates:

```text
lint
→ typecheck
→ unit/component tests
→ integration tests
→ build
→ E2E / browser verification when applicable
```

Choose tests based on the change. Do not add meaningless tests solely to increase test count.

Never claim a check passed unless it actually ran and passed.

If an expected verification could not be run, state exactly what was not verified and why.

## 9. Visual and UX review

For UI changes, verify the rendered result when tooling permits it.

Check:

- visual hierarchy;
- information density;
- primary action clarity;
- spacing and alignment;
- loading/empty/error states;
- responsive behavior;
- keyboard/focus behavior;
- accessible names/states;
- localization and RTL;
- consistency with the existing design system.

Do not equate “the page renders” with “the UX is complete.”

## 10. Report

Completion reports must be evidence-based and concise:

```text
## What changed
...

## Architecture
...

## Verification
...

## Notes
...
```

Include actual commands/checks and their results when relevant. Mention material assumptions, unresolved limitations, or deliberate exceptions.

## Non-negotiable behavior

Never:

- bypass authentication or authorization because a UI path appears trusted;
- place secrets in client-side code;
- inject unsafe HTML without a justified, controlled, sanitized path;
- construct SQL or other backend queries in the frontend as if frontend validation were a security boundary;
- expose sensitive data through logs, telemetry, URLs, or client state unnecessarily;
- silently swallow important errors;
- claim tests passed without running them;
- invent APIs, schemas, entities, routes, or repository conventions;
- perform broad unrelated refactors during a focused task;
- create parallel components when an established reusable pattern exists;
- destroy localization, accessibility, responsiveness, or keyboard usability to achieve visual polish.

## Decision hierarchy

When choices conflict, prefer:

```text
explicit product requirement
> security/runtime/framework constraints
> existing project contracts
> this standard
> convenience
```

When the standard conflicts with a project constraint, adapt locally, document the material exception, and avoid spreading it without deliberate architectural review.

## Definition of done

A task is done only when:

```text
requested behavior works
AND
architecture is coherent
AND
relevant states are handled
AND
security boundaries are respected
AND
accessibility/i18n requirements are respected where applicable
AND
performance is reasonable
AND
relevant verification was performed
AND
material limitations are reported
```

Do not optimize for producing the most code. Optimize for producing the smallest correct, maintainable solution that satisfies the product need and preserves the engineering standard.
