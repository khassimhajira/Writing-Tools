#!/bin/bash
# WriteHuman cookie auto-refresher.
#
# Reads the access token from the cookie file, decodes its expiry, and
# if it's within a 10-minute refresh window (or already expired), calls
# Supabase's /auth/v1/token?grant_type=refresh_token endpoint and
# rewrites the cookie file with the fresh access token + refresh token.
#
# Designed to be run from cron every 10 minutes:
#   */10 * * * * /home/u124071091/stealth_data/writehuman_refresh.sh >>/home/u124071091/stealth_data/writehuman_refresh.log 2>&1
#
# Idempotent: if the token is still valid for >15 minutes, it's a no-op.
# If the refresh fails (network, refresh token expired), the file is
# left untouched and the failure is logged.

set -e

COOKIE_FILE=/home/u124071091/stealth_data/writehuman_cookie.txt
LOG_TAG="[writehuman-refresh $(date -u +%Y-%m-%dT%H:%M:%SZ)]"
SUPABASE_URL="https://hicfsbrfkzsxbwayibfm.supabase.co"
ANON_KEY="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImhpY2ZzYnJma3pzeGJ3YXlpYmZtIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzMwMzE4MDgsImV4cCI6MjA4ODYwNzgwOH0.8vN4qjWB6aBGHuz7ixzoLRrMgKO3Lnc-Vmm2SjbW9n0"

if [ ! -r "$COOKIE_FILE" ]; then
    echo "$LOG_TAG cookie file missing or unreadable: $COOKIE_FILE" >&2
    exit 1
fi

# Extract sb auth-token cookie value. The whole file is one Cookie
# header line: "name1=value1; name2=value2; ...".
SB_COOKIE=$(awk -v RS='; ' '/^sb-hicfsbrfkzsxbwayibfm-auth-token=/' "$COOKIE_FILE" | head -1)
SB_VALUE=${SB_COOKIE#sb-hicfsbrfkzsxbwayibfm-auth-token=}

if [ -z "$SB_VALUE" ]; then
    echo "$LOG_TAG could not find sb-...-auth-token in cookie file" >&2
    exit 1
fi

# Strip 'base64-' prefix if present (Supabase's newer cookie format).
case "$SB_VALUE" in
    base64-*) JSON=$(echo "${SB_VALUE#base64-}" | tr '_-' '/+' | base64 -d 2>/dev/null) ;;
    *)        JSON="$SB_VALUE" ;;
esac

# Pull expires_at and refresh_token using a tiny python one-liner. python3
# is always present on Hostinger CageFS.
read EXPIRES_AT REFRESH_TOKEN <<<$(python3 -c '
import json, sys
try:
    d = json.loads(sys.argv[1])
    print(d.get("expires_at", 0), d.get("refresh_token", ""))
except Exception as e:
    sys.stderr.write("decode error: %s\n" % e)
    sys.exit(1)
' "$JSON")

if [ -z "$REFRESH_TOKEN" ] || [ "$REFRESH_TOKEN" = "None" ]; then
    echo "$LOG_TAG no refresh_token in cookie blob" >&2
    exit 1
fi

NOW=$(date +%s)
SECONDS_LEFT=$((EXPIRES_AT - NOW))

echo "$LOG_TAG access expires in ${SECONDS_LEFT}s (at $EXPIRES_AT)"

# If we have more than 15 minutes left, skip refresh.
if [ $SECONDS_LEFT -gt 900 ]; then
    echo "$LOG_TAG no refresh needed"
    exit 0
fi

echo "$LOG_TAG refreshing..."

RESP=$(curl -sS \
    -X POST "$SUPABASE_URL/auth/v1/token?grant_type=refresh_token" \
    -H "Content-Type: application/json" \
    -H "apikey: $ANON_KEY" \
    -H "Authorization: Bearer $ANON_KEY" \
    -d "{\"refresh_token\":\"$REFRESH_TOKEN\"}" \
    --max-time 25)

if [ -z "$RESP" ]; then
    echo "$LOG_TAG refresh got empty response" >&2
    exit 1
fi

# Bail out if Supabase returned an error.
ERR=$(echo "$RESP" | python3 -c 'import json,sys
try:
  d=json.load(sys.stdin)
  print(d.get("error_description") or d.get("error") or d.get("msg") or "")
except: print("parse-error")
')

if [ -n "$ERR" ]; then
    echo "$LOG_TAG refresh error: $ERR" >&2
    echo "$LOG_TAG raw response (first 500): ${RESP:0:500}" >&2
    exit 1
fi

# The new session blob has the same shape as the cookie; we just need to
# wrap it as base64-<...> and rewrite the cookie file. Preserve the other
# cookies (sb-session-token, wh_anon_id) from the existing file.
NEW_COOKIE_VALUE=$(echo -n "$RESP" | base64 -w0 | tr '/+' '_-' | sed 's/=*$//')
NEW_COOKIE="base64-$NEW_COOKIE_VALUE"

# Capture other cookies as-is.
OTHERS=$(awk -v RS='; ' '!/^sb-hicfsbrfkzsxbwayibfm-auth-token=/' "$COOKIE_FILE" | tr '\n' ' ' | sed 's/ $//' | sed 's/  */; /g')

# Rebuild file. Auth token first for clarity.
TMP=$(mktemp)
printf 'sb-hicfsbrfkzsxbwayibfm-auth-token=%s; %s\n' "$NEW_COOKIE" "$OTHERS" > "$TMP"
chmod 600 "$TMP"
mv "$TMP" "$COOKIE_FILE"

# Verify new expiry.
NEW_EXP=$(echo "$RESP" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("expires_at",0))')
echo "$LOG_TAG refresh OK; new expires_at=$NEW_EXP (in $((NEW_EXP - NOW))s)"
