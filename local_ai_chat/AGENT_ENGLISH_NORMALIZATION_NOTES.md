# Agent English Normalization Policy

All Agent Runtime protocol instructions, internal diagnostics, event names, and log messages must be written in English.

Model-facing protocol messages must use the canonical English field names and message types defined by the Unified Agent Protocol. User-facing final answers remain in the user's language unless the user explicitly requests another language.

PHP is responsible for validating message structure, tool availability, authorization, argument schemas, execution state, and receipts. PHP must not infer whether a request needs a tool from keyword rules or other heuristic intent classifiers.

The model decides whether an enabled capability is relevant. If no tool is enabled, the model receives a direct-response request and no Agent Runtime loop is started. If tools are enabled, the model first receives the enabled-tool catalog and may return a final answer directly. Agent Runtime starts only after the model emits a supported tool interaction request.

The user-enabled tool snapshot is immutable for the turn. The model-facing `data_access` tool is a normal user-permissioned capability. It is exposed only when explicitly enabled; selective reads are run-scoped and full-payload reference hydration is prohibited.
