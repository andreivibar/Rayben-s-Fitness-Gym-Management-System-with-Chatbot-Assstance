# Backend notes

## Login rate limiting

Apply `migrations/20261121_create_login_rate_limits.sql` to the application database before enabling the login limiter. It creates hashed scope-lock rows and a rolling failure-event ledger; neither table stores raw usernames, emails, IP addresses, or passwords. Configure a stable, random `LOGIN_RATE_LIMIT_HMAC_KEY` with at least 32 characters in the PHP server environment or `backend/.env`; use the same key across application nodes and do not reuse the password-reset or email-change key. Missing or invalid key configuration causes login to fail closed with a temporary-unavailable response.

Defaults are five failed attempts per account/IP in 15 minutes with a 15-minute cooldown, 30 failed attempts per IP in 15 minutes, and a configurable short IP throttle of five failures per 60 seconds. The short throttle can be changed with `LOGIN_IP_BURST_LIMIT` and `LOGIN_IP_BURST_WINDOW_SECONDS`. Optional `LOGIN_TRUSTED_PROXIES` accepts a comma-separated list of trusted proxy IPs/CIDRs; forwarded IPs are ignored unless `REMOTE_ADDR` matches that list.

Run the disposable MariaDB 10.11.16 integration and concurrency test with:

```powershell
& 'C:\xampp\php\php.exe' 'backend\tests\login_rate_limit_db_test.php'
```

It creates a uniquely named `gym_login_rate_limit_test_*` database, verifies the server version, runs the login migration and assertions, and drops only the database it created. Optional `LOGIN_RATE_LIMIT_TEST_HOST`, `LOGIN_RATE_LIMIT_TEST_PORT`, `LOGIN_RATE_LIMIT_TEST_USER`, and `LOGIN_RATE_LIMIT_TEST_PASSWORD` environment variables select the local test server credentials.

## Member gym assistant

The member-only assistant API is `api/member_chatbot.php`. It requires an authenticated, approved member account with a current Monthly Subscription backed by a paid payment and the existing application/document verification checks. Conversation and message history is stored in the `member_chat_conversations` and `member_chat_messages` tables; members can delete their own conversations.

Apply `migrations/20261110_add_member_chatbot_conversations.sql` once per database before deploying this feature. The development database has already had this additive migration applied.

Configure `GEMINI_API_KEY` in the PHP/Apache server environment. The chatbot uses Google's Gemini Developer API `generateContent` endpoint with the stable `gemini-3.6-flash` model by default; `GEMINI_MODEL` can select another supported model. Gemini API keys must remain on the PHP server and must never be put in frontend code or browser requests. The server must have PHP cURL enabled and outbound HTTPS access to `generativelanguage.googleapis.com`. Free-tier usage is subject to Google's model- and project-specific rate limits; the API responds with HTTP 429 and a retry-later message when quota is exhausted.

The previous OpenAI request helper remains in the PHP configuration during the Gemini rollout. The chatbot API uses Gemini; do not remove the legacy helper until a real Gemini API request has been verified.

Run the eligibility regression test with:

```powershell
& 'C:\xampp\php\php.exe' 'backend\tests\member_chatbot_eligibility_test.php'
```

## Rejected registration identifier reuse

The additive migration `migrations/20261112_release_rejected_registration_identifiers.sql` releases usernames and emails only for positively matched rejected initial accounts while retaining their request and account history. It invalidates tokens only for those matched accounts. The migration intentionally does not select a database: connect to and verify a disposable test copy before running it; do not apply it to the live database without separate approval. It requires MariaDB 10.4+ and a client that supports `DELIMITER` directives (for example, the MariaDB command-line client).

The database-backed identity-release regression test is restricted to `gym_management_test`:

```powershell
$env:DUPLICATE_NAME_REGISTRATION_TEST_TEMP_TABLES = '1'
$env:GYM_TEST_DB_NAME = 'gym_management_test_migration_YYYYMMDD_HHMM'
& 'C:\xampp\php\php.exe' 'backend\tests\duplicate_name_registration_db_test.php'
```
