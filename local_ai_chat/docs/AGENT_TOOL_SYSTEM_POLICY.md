# Agent Tool Catalog and System-Tool Policy

## Active tool set

The Agent Runtime exposes 11 registered tools:

- User-selectable tools: `search_web`, `manage_websites`, `get_server_info`, `list_models`, `python_calculate`, `python_generate_data`, `python_statistics`, `python_plot`, and `python_dataframe`.
- Runtime-managed system tools: `data_access` and `verify_result`.

The following tools have been retired from the Agent registry and dispatcher: `python_analyze_json`, `python_machine_learning`, `get_llama_info`, `get_infrastructure_status`, and `document_extract`. Their Agent handlers and corresponding Python worker actions were removed. Historical storage manifests may still contain old tool names as audit data; they do not make those tools executable.

## Example coverage

Examples are returned only in `tool_help_result`, alongside the complete input schema. The registry now contains 95 examples. They cover each supported operation/mode/type in the active tool set, including:

- all four `data_access` operations;
- all three public web-search modes and a source-reuse claim-audit example;
- website list/read paths;
- the canonical math operation modes;
- all supported data-generation distributions;
- every registered statistics method;
- all chart types and both single-/multi-series plotting;
- DataFrame profile/filter/groupby operations;
- arithmetic and record-count verification.

`tests/agent_tool_argument_regression_test.php` validates every example against its registered schema and checks operation/mode coverage.

## System tools

`data_access` and `verify_result` are marked `internal` and `system_tool`. They are hidden from user permission selection and discovery, but Runtime automatically injects them into the model-callable tool map for each Agent run. The user-enabled permission snapshot remains separate and unchanged.

### `data_access`

Runtime records every committed Data Pack reference produced by another tool. Before finalization, the Agent must successfully call `data_access` with the exact reference for each committed Data Pack. If it does not, Runtime returns a mandatory system-tool requirement and gives the model another opportunity to request Help and execute the required operation. After two unsuccessful repair attempts, the run ends with `completed_with_limitations`; the Agent does not present the result as fully verified.

### `verify_result`

Runtime requires `verify_result` for:

- directly recomputable numeric evaluations from `python_calculate` (`evaluate`, `calculate`, or `numeric`, without substitutions);
- row arrays returned by `data_access`. This is a conservative Runtime gate: once a row array is returned, a record-count verification is required before finalization, even if the final answer does not explicitly quote the count.

Arithmetic results require `kind=arithmetic`; row-array counts require `kind=record_count`. Runtime checks that the corresponding verification call succeeded before accepting a final answer. A paginated page count is not the total dataset count; the Agent must not conflate them.

The verifier currently supports arithmetic recomputation and record recounting. It is not a universal semantic, statistical, or factual verifier. For result types outside those scopes, the Agent must state the verification limitation rather than sending fabricated or unrelated inputs.

## Protocol version

The system-tool injection and finalization requirements are part of Unified Agent Protocol version 4.3.
