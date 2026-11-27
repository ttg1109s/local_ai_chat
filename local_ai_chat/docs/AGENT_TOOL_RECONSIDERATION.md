# Two-stage tool discovery and reconsideration

## First-round catalog

The first-round catalog is a routing index only. Each enabled tool exposes exactly:
- `name`
- `label`
- `description` (short `catalog_description`)

Detailed descriptions, `when_to_use`, `when_not_to_use`, JSON Schema, field guidance,
semantic validation rules, examples, and `can_feed` stay in the Registry and are returned
only after `tool_help_request`.

## Help is advisory, not an execution mandate

After receiving `tool_help_result`, the model must evaluate whether the tool can actually
complete the requested task with the available inputs.

- If suitable, it may emit a schema-valid `tool_call`.
- If unsuitable, it must not call the tool merely because Help was returned. It emits:

```json
{
  "type": "tool_reconsider",
  "tool": "exact_tool_name",
  "reason": "Why this tool cannot serve the task"
}
```

The Runtime accepts this message only when the tool is enabled and its Help was already
returned during the current turn. It does not create an invocation and does not execute
anything. Instead it sends `tool_reconsider_result` back to the model with the enabled-tool
summaries and asks the model to make a fresh task-level decision.

The model may then:
1. request Help for another enabled tool;
2. continue without tools and return the best supported answer; or
3. ask the user one necessary clarification.

The Runtime must not force a tool call, automatically substitute a different tool, or treat
the reconsideration response as task completion. The model owns the next decision.

## Audit events

- `agent_tool_reconsidered`: model declined a helped tool and Runtime returned control to the model.
- `agent_tool_reconsider_rejected`: reconsideration was requested without a valid Help grant.

Both paths explicitly record `execution_started=false`.
