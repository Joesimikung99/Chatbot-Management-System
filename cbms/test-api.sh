#!/bin/bash
# ============================================================
# API Test Script — Step 12
# AI Chatbot System — CBMS
#
# Usage:
#   chmod +x test-api.sh
#   ./test-api.sh
#
# Or on Windows (Git Bash):
#   bash test-api.sh
# ============================================================

BASE_URL="https://appupili.up.ac.th/cbms/api/chat.php"
# Change to your local dev URL for testing:
# BASE_URL="http://localhost/cbms/api/chat.php"

GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}============================================${NC}"
echo -e "${CYAN}  AI Chatbot API Test Suite${NC}"
echo -e "${CYAN}  $(date)${NC}"
echo -e "${CYAN}============================================${NC}\n"

# ── Test 1: Basic Chat ─────────────────────────────────────────────────
echo -e "${CYAN}[Test 1] Basic chat message${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -d '{"message": "สวัสดี บอทตอบได้ไหม?"}')
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -1)
if [ "$HTTP_CODE" = "200" ]; then
  echo -e "${GREEN}  ✅ HTTP $HTTP_CODE${NC}"
  echo "  Response: $(echo $BODY | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("data",{}).get("reply","")[:100])' 2>/dev/null || echo "$BODY")"
else
  echo -e "${RED}  ❌ HTTP $HTTP_CODE — $BODY${NC}"
fi
echo ""

# ── Test 2: Chat with Session ID ──────────────────────────────────────
echo -e "${CYAN}[Test 2] Chat with explicit session ID${NC}"
SESSION_ID="test-session-$(date +%s)"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -d "{\"message\": \"ระบบนี้รองรับกี่แพลตฟอร์ม?\", \"session_id\": \"$SESSION_ID\"}")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -1)
if [ "$HTTP_CODE" = "200" ]; then
  echo -e "${GREEN}  ✅ HTTP $HTTP_CODE | Session: $SESSION_ID${NC}"
else
  echo -e "${RED}  ❌ HTTP $HTTP_CODE — $BODY${NC}"
fi
echo ""

# ── Test 3: Conversation History ──────────────────────────────────────
echo -e "${CYAN}[Test 3] Get conversation history${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$BASE_URL?action=history&session_id=$SESSION_ID")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
if [ "$HTTP_CODE" = "200" ]; then
  echo -e "${GREEN}  ✅ HTTP $HTTP_CODE${NC}"
else
  echo -e "${RED}  ❌ HTTP $HTTP_CODE${NC}"
fi
echo ""

# ── Test 4: Missing message field ────────────────────────────────────
echo -e "${CYAN}[Test 4] Validation — missing message field${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -d '{"session_id": "test"}')
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
if [ "$HTTP_CODE" = "422" ]; then
  echo -e "${GREEN}  ✅ Correctly returned HTTP 422 (Unprocessable Entity)${NC}"
else
  echo -e "${RED}  ❌ Expected 422, got HTTP $HTTP_CODE${NC}"
fi
echo ""

# ── Test 5: Empty message ────────────────────────────────────────────
echo -e "${CYAN}[Test 5] Validation — empty message${NC}"
RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL" \
  -H "Content-Type: application/json" \
  -d '{"message": ""}')
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
if [ "$HTTP_CODE" = "422" ]; then
  echo -e "${GREEN}  ✅ Correctly returned HTTP 422${NC}"
else
  echo -e "${RED}  ❌ Expected 422, got HTTP $HTTP_CODE${NC}"
fi
echo ""

# ── Test 6: Facebook Webhook Verification ────────────────────────────
echo -e "${CYAN}[Test 6] Facebook webhook verification${NC}"
FB_URL="https://appupili.up.ac.th/cbms/api/webhook-facebook.php"
VERIFY_TOKEN=$(grep FACEBOOK_VERIFY_TOKEN ../.env 2>/dev/null | cut -d'=' -f2 | tr -d '"' || echo "random-verify-token-for-webhook")
RESPONSE=$(curl -s -w "\n%{http_code}" -X GET \
  "$FB_URL?hub_mode=subscribe&hub_verify_token=$VERIFY_TOKEN&hub_challenge=CHALLENGE_CODE")
HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY=$(echo "$RESPONSE" | head -1)
if [ "$HTTP_CODE" = "200" ] && [ "$BODY" = "CHALLENGE_CODE" ]; then
  echo -e "${GREEN}  ✅ Facebook webhook verification OK${NC}"
else
  echo -e "${RED}  ❌ HTTP $HTTP_CODE — $BODY${NC}"
fi
echo ""

echo -e "${CYAN}============================================${NC}"
echo -e "${GREEN}  Tests complete!${NC}"
echo -e "${CYAN}============================================${NC}\n"
