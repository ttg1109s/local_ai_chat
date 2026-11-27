#!/usr/bin/env bash
set -euo pipefail
BASE_URL="${LAC_LLAMA_CPP_URL:-http://127.0.0.1:8080}"
MODEL="${LAC_LLAMA_CPP_MODEL:-local-model}"
API_KEY="${LAC_LLAMA_CPP_API_KEY:-no-key-required}"
HEALTH=$(curl -fsS "$BASE_URL/health")
echo "$HEALTH" | grep -q 'ok' || { echo "llama.cpp health endpoint did not report ready" >&2; exit 1; }
BODY=$(printf '%s' '{"model":"'"$MODEL"'","stream":true,"messages":[{"role":"system","content":"Reply with exactly LAC_SMOKE_OK and nothing else."},{"role":"user","content":"Compatibility smoke test."}],"temperature":0,"max_tokens":24}')
RESPONSE=$(curl -NfsS "$BASE_URL/v1/chat/completions" -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json' -d "$BODY")
printf '%s' "$RESPONSE" | grep -q 'LAC_SMOKE_OK' || { echo "chat-completions smoke test failed: $RESPONSE" >&2; exit 1; }
echo "PASS: /health and OpenAI-compatible /v1/chat/completions"
if [[ "${LAC_LLAMA_CPP_TEST_SCHEMA:-0}" == "1" ]]; then
  SCHEMA_BODY=$(printf '%s' '{"model":"'"$MODEL"'","stream":false,"messages":[{"role":"system","content":"Return the object requested by the JSON schema."},{"role":"user","content":"Return status=ok."}],"response_format":{"type":"json_schema","schema":{"type":"object","properties":{"status":{"type":"string","enum":["ok"]}},"required":["status"],"additionalProperties":false}},"temperature":0,"max_tokens":40}')
  SCHEMA_RESPONSE=$(curl -fsS "$BASE_URL/v1/chat/completions" -H "Authorization: Bearer $API_KEY" -H 'Content-Type: application/json' -d "$SCHEMA_BODY")
  printf '%s' "$SCHEMA_RESPONSE" | grep -q '"status"' || { echo "schema-constrained output test failed: $SCHEMA_RESPONSE" >&2; exit 1; }
  echo "PASS: response_format JSON Schema (server/model dependent)"
fi
