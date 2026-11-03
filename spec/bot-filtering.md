# Automation classification (contract version 2)

Classification is best-effort, transient and never verifies an identity. The
ordered rules in [automation-rules.json](automation-rules.json) are authoritative;
`scripts/generate-automation.py` produces equivalent native classifiers. Changes
must update [shared vectors](test-vectors/automation.json) and regenerate all
ports. Tests check generated-file parity. No DNS, IP or third-party lookups run.

Categories are `search`, `ai`, `social`, `monitoring`, `other`, `uncertain`,
`unknown` and `browser`. Recognized tokens match ASCII alphanumeric boundaries,
with ASCII case folding. Administrator patterns have a 200 UTF-8 byte limit.
Generic `bot`, `crawler`, `spider` and `headless` tokens imply
uncertain automation, not a known operator. This avoids classifying `Cubot` or
`robotics` substrings as bots. Administrator additions remain explicitly opted-in
literal substring rules for compatibility, yielding the fixed family `custom`.
UA input over 1,024 UTF-8 bytes yields `uncertain/oversized` without parsing it.

Recognized rules yield `identity: declared`; generic/custom rules are heuristic,
unknown inputs are unknown, browser tokens are presumed. `verified` is always
false. Matching an OpenAI or Claude family describes a declared product token,
not proof of its origin or even that it is an autonomous crawler. Rule references
include the official [OpenAI](https://developers.openai.com/api/docs/bots) and
[Anthropic](https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler)
token lists, checked 2026-11-03.
The declared Perplexity tokens are documented in its official
[crawler reference](https://docs.perplexity.ai/docs/resources/perplexity-crawlers).
GoogleOther remains `other`, following Google's
[generic-crawler description](https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers#googleother);
OpenAI's advertising validator likewise remains `other`, rather than implying
search or AI-training activity from its operator alone.

Recognized and uncertain automation increments only `automation_daily`, never
ordinary page views or optional human dimensions. Unknown/missing UA clients
remain eligible for ordinary views for compatibility; that does not prove they
are human. Store only rule-defined category/family, safe path, UTC day and count.
The dashboard reports observed requests, never all website crawler traffic.
JavaScript collectors miss clients that do not execute the script. Native
server integrations observe only requests that enter their collection pipeline.
