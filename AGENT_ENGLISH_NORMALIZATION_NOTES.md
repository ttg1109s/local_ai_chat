# Agent English Normalization Policy

All Agent Runtime protocol instructions, internal diagnostics, event names, and log messages must be written in English.

## Scope
- Runtime system and developer instructions sent to models.
- Tool names, argument schemas, status values, event identifiers, and protocol labels.
- Internal diagnostics, warnings, errors, trace messages, and logs.
- Test fixtures that assert internal protocol or diagnostic text.

## User-facing language
User-facing content may follow the user's language preference. This policy does not require all product UI or model responses to be English; it applies to internal constructs and runtime protocol text.

## Rationale
A consistent English internal vocabulary improves interoperability across models, reduces ambiguity in protocol parsing and debugging, and keeps regression tests stable. Translate user-facing explanations separately when appropriate; do not localize internal identifiers or protocol messages.
